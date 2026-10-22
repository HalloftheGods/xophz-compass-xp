#!/usr/bin/env bash
# Rehearsal smoke for the family routes (xp-family/v1) of Do It For The XP.
#
# Drives the real routes on the Town Hall's throwaway network (TH7) with curl and checks each
# answer. Never point it at the live site.
#
#   tests/rehearsal/family-smoke.sh [family|phones|events|records|all]     (default: all)
#
#   SITE     the family site      (default http://localhost:8090/doit)
#   COMPOSE  the rehearsal file   (default $HOME/www/x/do-it-for-the-xp/tools/townhall-rehearsal/docker-compose.yml)
#   PARENT   the parent account   (default famparent; made when missing)
#
# Prints PASS <name> or FAIL <name> per check and exits 1 on any failure.
#
# It touches only what it makes: the families F1 and F2 (and their heroes, phones, codes, events
# and records), the users smokeparent2 and smokeoutsider, the Application Passwords named "smoke"
# and its own claim counters. Each section starts with reset_family, so a section runs on its own,
# and the run ends by removing the smoke families and the passwords it made.
#
# The records section (family-smoke-records.sh, sourced below) needs a family that is entitled to
# XP Pro. The rehearsal has no licence, so it sets the rehearsal-only option xp_family_pro_override
# (which Family_Pro::entitled reads only while WP_DEBUG is on) for its own checks and removes it
# before it ends, and teardown removes it again if the run stopped half way.
set -uo pipefail

SITE="${SITE:-http://localhost:8090/doit}"
SITE="${SITE%/}"
COMPOSE="${COMPOSE:-$HOME/www/x/do-it-for-the-xp/tools/townhall-rehearsal/docker-compose.yml}"
PARENT="${PARENT:-famparent}"
API="$SITE/wp-json/xp-family/v1"

TOWN_HALL_APP_ID="7606ce60-29c7-43b6-9533-2063ee726df4"
# Any app id but the Town Hall's: a password with it does not make its user a member of the site.
OTHER_APP_ID="5e0c0000-0000-4000-8000-0000000000aa"

# The smoke families (lowercase v4 UUIDs) and devices (16 letters and digits).
F1="5e0c0000-0000-4000-8000-00000000f001"
F2="5e0c0000-0000-4000-8000-00000000f002"
D1="SmokeHomeDevice1"
D2="SmokeHomeDevice2"
PHONE1="SmokeHeroPhone01"
PHONE2="SmokeHeroPhone02"
PHONE3="SmokeHeroPhone03"

# The rehearsal-only Pro override (Family_Pro::OVERRIDE_OPTION), and whether this run set it.
PRO_OVERRIDE="xp_family_pro_override"
OVERRIDE_SET=0

SECTION="${1:-all}"
case "$SECTION" in
  family | phones | events | records | all) ;;
  *)
    printf 'usage: %s [family|phones|events|records|all]\n' "$0" >&2
    exit 2
    ;;
esac

TMP="$(mktemp -d)"
PASSED=0
FAILED=0
STATUS=0
PREFIX=""
PARENT_AUTH=""
PARENT2_AUTH=""
OUTSIDER_AUTH=""
PASSWORD_UUIDS=""

# --- helpers ---------------------------------------------------------------------------------

# One WP-CLI call in the rehearsal's throwaway wpcli container, on the family site.
wp() { docker compose --progress quiet -f "$COMPOSE" run --rm -T wpcli wp --url="$SITE" "$@"; }

pass() {
  printf 'PASS %s\n' "$1"
  PASSED=$((PASSED + 1))
}

fail() {
  printf 'FAIL %s%s\n' "$1" "${2:+ :: $2}"
  FAILED=$((FAILED + 1))
}

# req <auth> <method> <path> [json body]
#   auth: none | key:<hero key> | <user>:<application password>
# Sets STATUS, and leaves the body in $TMP/body and the headers in $TMP/headers.
req() {
  local auth="$1" method="$2" path="$3" body="${4:-}"
  local args=(-s -o "$TMP/body" -D "$TMP/headers" -w '%{http_code}' -X "$method")
  case "$auth" in
    none) ;;
    key:*) args+=(-H "X-XP-Hero-Key: ${auth#key:}") ;;
    *) args+=(-u "$auth") ;;
  esac
  if [ -n "$body" ]; then
    args+=(-H 'Content-Type: application/json' --data "$body")
  fi
  STATUS="$(curl "${args[@]}" "$API$path" 2> /dev/null)"
  [ -n "$STATUS" ] || STATUS=0
}

# The last answer as one JSON value: { status, body, retry_after, headers }.
answer() {
  local retry
  retry="$(tr -d '\r' < "$TMP/headers" | awk 'tolower($1) == "retry-after:" { print $2 }' | tail -n 1)"
  tr -d '\r' < "$TMP/headers" | tr '[:upper:]' '[:lower:]' > "$TMP/headers.lower"
  if jq -e . "$TMP/body" > /dev/null 2>&1; then
    jq -c --argjson status "$STATUS" --arg retry "$retry" --rawfile headers "$TMP/headers.lower" \
      '{ status: $status, body: ., retry_after: $retry, headers: $headers }' "$TMP/body"
  else
    jq -nc --argjson status "$STATUS" --arg retry "$retry" --rawfile headers "$TMP/headers.lower" \
      '{ status: $status, body: null, retry_after: $retry, headers: $headers }'
  fi
}

# ok <name> <jq test on the last answer>
ok() {
  local name="$1" test="$2"
  if answer | jq -e "$test" > /dev/null 2>&1; then
    pass "$name"
  else
    fail "$name" "HTTP $STATUS $(head -c 300 "$TMP/body" 2> /dev/null)"
  fi
}

# A value from the last body: field <jq path>
field() { jq -r "$1 // empty" "$TMP/body" 2> /dev/null; }

# at_once <auth> <method> <path> <json body>...: one request per body, all sent at the same moment
# (for the checks that must hold under concurrency). Leaves each status in $TMP/once.<n>.status
# and each body in $TMP/once.<n>.body.
at_once() {
  local auth="$1" method="$2" path="$3" body i=0
  shift 3
  rm -f "$TMP"/once.*
  for body in "$@"; do
    i=$((i + 1))
    local args=(-s -o "$TMP/once.$i.body" -w '%{http_code}\n' -X "$method" -H 'Content-Type: application/json' --data "$body")
    case "$auth" in
      none) ;;
      key:*) args+=(-H "X-XP-Hero-Key: ${auth#key:}") ;;
      *) args+=(-u "$auth") ;;
    esac
    curl "${args[@]}" "$API$path" > "$TMP/once.$i.status" 2> /dev/null &
  done
  wait
}

# once_count <status>: how many of the at_once answers had this status.
once_count() { cat "$TMP"/once.*.status 2> /dev/null | grep -cx "$1"; }

# All at_once statuses on one line, for a failure message.
once_statuses() { cat "$TMP"/once.*.status 2> /dev/null | sort | uniq -c | tr -s ' \n' ' '; }

# Run SQL on the rehearsal database (smoke rows only).
sql() { wp db query "$1" --skip-column-names; }

smoke_families_sql() {
  local in="('$F1','$F2')"
  # The records go with the family (their numbers live in the family's records_seq and the turns
  # in the heroes' turn_* columns, so deleting those rows clears them); the next bind starts at 0.
  printf '%s' "DELETE FROM ${PREFIX}xp_family_records WHERE family_id IN $in;
DELETE FROM ${PREFIX}xp_family_events WHERE family_id IN $in;
DELETE FROM ${PREFIX}xp_family_link_codes WHERE family_id IN $in;
DELETE FROM ${PREFIX}xp_family_devices WHERE family_id IN $in;
DELETE FROM ${PREFIX}xp_family_heroes WHERE family_id IN $in;
DELETE FROM ${PREFIX}xp_families WHERE family_id IN $in;"
}

# The claim counters are transients keyed by the caller's address; only ours start with this name.
claim_counters_sql() {
  printf '%s' "DELETE FROM ${PREFIX}options WHERE option_name LIKE '\\_transient\\_xp\\_family\\_claim\\_fail\\_%'
  OR option_name LIKE '\\_transient\\_timeout\\_xp\\_family\\_claim\\_fail\\_%';"
}

# reset_family [--clear-only]: delete the smoke families; then bind F1 with home D1 and register
# h-1, h-2 and h-3, unless --clear-only.
reset_family() {
  sql "$(smoke_families_sql) $(claim_counters_sql)" > /dev/null
  [ "${1:-}" = "--clear-only" ] && return 0

  req "$PARENT_AUTH" POST /family "{\"family_id\":\"$F1\",\"device_id\":\"$D1\"}"
  if [ "$STATUS" != 200 ] || [ "$(field .answer)" != created ]; then
    fail "reset_family binds F1" "HTTP $STATUS $(head -c 300 "$TMP/body")"
    return 1
  fi
  local hero
  for hero in h-1 h-2 h-3; do
    req "$PARENT_AUTH" POST "/heroes/$hero" "{\"nickname\":\"Smoke ${hero}\",\"age_group\":\"child\"}"
    if [ "$STATUS" != 200 ]; then
      fail "reset_family registers $hero" "HTTP $STATUS $(head -c 300 "$TMP/body")"
      return 1
    fi
  done
}

# Make (or re-key) the smoke accounts in one WP-CLI call and print "<login>\t<password>\t<uuid>".
#   PARENT        : a network user; its "smoke" password has the Town Hall's app id (TH8 makes
#                   it a member of the family site).
#   smokeparent2  : the same, a second parent.
#   smokeoutsider : a member of the main site only; its password has another app id, so TH8
#                   does not add it to the family site.
make_accounts() {
  wp eval "
    \$town_hall = '$TOWN_HALL_APP_ID';
    \$other     = '$OTHER_APP_ID';
    \$blog      = get_current_blog_id();
    \$main      = get_main_site_id();
    \$accounts  = [ [ '$PARENT', \$town_hall ], [ 'smokeparent2', \$town_hall ], [ 'smokeoutsider', \$other ] ];
    foreach ( \$accounts as list( \$login, \$app_id ) ) {
      \$user = get_user_by( 'login', \$login );
      if ( ! \$user ) {
        \$id = wpmu_create_user( \$login, wp_generate_password( 24 ), \$login . '@rehearsal.test' );
        if ( ! \$id ) { fwrite( STDERR, \"could not make \$login\n\" ); exit( 1 ); }
        \$user = get_user_by( 'id', \$id );
      }
      if ( 'smokeoutsider' === \$login ) {
        if ( ! is_user_member_of_blog( \$user->ID, \$main ) ) { add_user_to_blog( \$main, \$user->ID, 'subscriber' ); }
        if ( is_user_member_of_blog( \$user->ID, \$blog ) ) { remove_user_from_blog( \$user->ID, \$blog ); }
      }
      foreach ( WP_Application_Passwords::get_user_application_passwords( \$user->ID ) as \$item ) {
        if ( 'smoke' === \$item['name'] ) { WP_Application_Passwords::delete_application_password( \$user->ID, \$item['uuid'] ); }
      }
      \$made = WP_Application_Passwords::create_new_application_password( \$user->ID, [ 'name' => 'smoke', 'app_id' => \$app_id ] );
      if ( is_wp_error( \$made ) ) { fwrite( STDERR, \$login . ': ' . \$made->get_error_message() . \"\n\" ); exit( 1 ); }
      echo \$login, \"\t\", \$made[0], \"\t\", \$made[1]['uuid'], \"\n\";
    }
  "
}

setup() {
  if ! curl -s -o /dev/null "$SITE/wp-json/"; then
    printf 'The family site %s does not answer. Start the rehearsal network first.\n' "$SITE" >&2
    exit 1
  fi

  PREFIX="$(wp db prefix 2> /dev/null | tr -d '\r\n')"
  if [ -z "$PREFIX" ]; then
    printf 'Could not read the table prefix of %s through WP-CLI.\n' "$SITE" >&2
    exit 1
  fi

  local accounts login password uuid
  if ! accounts="$(make_accounts)"; then
    printf 'Could not make the smoke accounts.\n' >&2
    exit 1
  fi
  while IFS=$'\t' read -r login password uuid; do
    [ -n "$login" ] || continue
    PASSWORD_UUIDS="$PASSWORD_UUIDS $login:$uuid"
    case "$login" in
      "$PARENT") PARENT_AUTH="$login:$password" ;;
      smokeparent2) PARENT2_AUTH="$login:$password" ;;
      smokeoutsider) OUTSIDER_AUTH="$login:$password" ;;
    esac
  done <<< "$accounts"

  if [ -z "$PARENT_AUTH" ] || [ -z "$PARENT2_AUTH" ] || [ -z "$OUTSIDER_AUTH" ]; then
    printf 'The smoke accounts came back incomplete.\n' >&2
    exit 1
  fi

  # The parent must own no family but the smoke ones, or "created" cannot be checked.
  local owned
  owned="$(sql "SELECT family_id FROM ${PREFIX}xp_families f JOIN ${PREFIX}users u ON u.ID = f.owner_user_id
    WHERE u.user_login = '$PARENT' AND f.family_id NOT IN ('$F1','$F2');" 2> /dev/null | tr -d '\r')"
  if [ -n "$owned" ]; then
    printf '%s already owns the family %s (not a smoke family). Run with PARENT=<another user>.\n' "$PARENT" "$owned" >&2
    exit 1
  fi
}

# Remove what the run made: the smoke families, the claim counters, the smoke passwords and the
# Pro override when the records section left it on.
teardown() {
  if [ -n "$PREFIX" ]; then
    [ "$OVERRIDE_SET" = 1 ] && wp option delete "$PRO_OVERRIDE" > /dev/null 2>&1
    local deletes="" pair
    for pair in $PASSWORD_UUIDS; do
      deletes="$deletes WP_Application_Passwords::delete_application_password( get_user_by( 'login', '${pair%%:*}' )->ID, '${pair#*:}' );"
    done
    sql "$(smoke_families_sql) $(claim_counters_sql)" > /dev/null 2>&1 || true
    [ -n "$deletes" ] && wp eval "$deletes" > /dev/null 2>&1 || true
  fi
  rm -rf "$TMP"
}
trap teardown EXIT

# --- family: binding the family to the account, heroes, moving home --------------------------
section_family() {
  printf -- '-- family\n'
  reset_family --clear-only

  req none POST /family "{\"family_id\":\"$F1\",\"device_id\":\"$D1\"}"
  ok "no auth POST /family -> 401" '.status == 401'

  req "$PARENT_AUTH" POST /family "{\"family_id\":\"$F1\",\"device_id\":\"$D1\"}"
  ok "parent binds F1 from D1 -> created" ".status == 200 and .body.answer == \"created\" and .body.family_id == \"$F1\""

  req "$PARENT_AUTH" POST /family "{\"family_id\":\"$F1\",\"device_id\":\"$D1\"}"
  ok "again from D1 -> home" ".status == 200 and .body.answer == \"home\" and .body.family_id == \"$F1\""

  req "$PARENT_AUTH" POST /family "{\"family_id\":\"$F1\",\"device_id\":\"$D2\"}"
  ok "F1 from D2 -> home-elsewhere" ".status == 200 and .body.answer == \"home-elsewhere\" and .body.family_id == \"$F1\""

  req "$PARENT_AUTH" POST /family "{\"family_id\":\"$F2\",\"device_id\":\"$D2\"}"
  ok "F2 from D2 -> other-family (with F1)" ".status == 200 and .body.answer == \"other-family\" and .body.family_id == \"$F1\""

  req "$PARENT_AUTH" POST /family "{\"family_id\":\"not-a-uuid\",\"device_id\":\"$D1\"}"
  ok "a family id that is not a v4 UUID -> 400" '.status == 400 and .body.code == "xp_family_bad_request"'

  req "$PARENT2_AUTH" POST /family "{\"family_id\":\"$F1\",\"device_id\":\"$D2\"}"
  ok "a second parent binds F1 -> 403 xp_family_taken" '.status == 403 and .body.code == "xp_family_taken"'

  req "$OUTSIDER_AUTH" POST /family "{\"family_id\":\"$F2\",\"device_id\":\"$D2\"}"
  ok "a signed-in non-member -> 403 xp_family_not_member" '.status == 403 and .body.code == "xp_family_not_member"'

  req "$OUTSIDER_AUTH" GET /family
  ok "a non-member reads the family -> 403 xp_family_not_member" '.status == 403 and .body.code == "xp_family_not_member"'

  req "$PARENT2_AUTH" GET /family
  ok "a parent with no family reads it -> 404 xp_family_no_family" '.status == 404 and .body.code == "xp_family_no_family"'

  req "$PARENT2_AUTH" POST /heroes/h-1 '{"nickname":"Mia","age_group":"child"}'
  ok "a parent with no family registers a hero -> 404 xp_family_no_family" '.status == 404 and .body.code == "xp_family_no_family"'

  req none GET /family
  ok "no auth GET /family -> 401" '.status == 401'

  req "$PARENT_AUTH" POST /heroes/h-1 '{"nickname":"Mia","age_group":"child"}'
  ok "register h-1 (Mia, child)" '.status == 200 and .body.ok == true'

  req "$PARENT_AUTH" POST /heroes/h-1 '{"nickname":"","age_group":"child"}'
  ok "an empty nickname -> 400 xp_family_bad_request" '.status == 400 and .body.code == "xp_family_bad_request"'

  req "$PARENT_AUTH" POST /heroes/h-1 '{"nickname":"Mia","age_group":"toddler"}'
  ok "age group toddler -> 400 xp_family_bad_request" '.status == 400 and .body.code == "xp_family_bad_request"'

  local forty="" forty_one
  for _ in $(seq 1 40); do forty="${forty}😀"; done
  forty_one="${forty}😀"
  req "$PARENT_AUTH" POST /heroes/h-2 "{\"nickname\":\"$forty\",\"age_group\":\"teen\"}"
  ok "a 40-emoji nickname is kept" '.status == 200 and .body.ok == true'
  req "$PARENT_AUTH" POST /heroes/h-2 "{\"nickname\":\"$forty_one\",\"age_group\":\"teen\"}"
  ok "a 41-emoji nickname -> 400" '.status == 400 and .body.code == "xp_family_bad_request"'

  req "$PARENT_AUTH" GET /family
  ok "GET /family lists h-1 with its age group" \
    ".status == 200 and .body.family_id == \"$F1\" and ([.body.heroes[] | select(.hero_id == \"h-1\" and .nickname == \"Mia\" and .age_group == \"child\" and .card == null)] | length) == 1"
  ok "GET /family keeps the 40 emoji of h-2" \
    "([.body.heroes[] | select(.hero_id == \"h-2\" and .nickname == \"$forty\" and .age_group == \"teen\")] | length) == 1"

  req "$PARENT_AUTH" DELETE /heroes/h-1
  ok "DELETE /heroes/h-1 -> ok" '.status == 200 and .body.ok == true'
  req "$PARENT_AUTH" GET /family
  ok "h-1 is gone from GET /family" '.status == 200 and ([.body.heroes[] | select(.hero_id == "h-1")] | length) == 0'

  req "$PARENT_AUTH" POST /heroes/h-1 '{"nickname":"Mia","age_group":"child"}'
  ok "POST /heroes/h-1 again -> ok" '.status == 200 and .body.ok == true'
  req "$PARENT_AUTH" GET /family
  ok "h-1 is back" '.status == 200 and ([.body.heroes[] | select(.hero_id == "h-1" and .nickname == "Mia")] | length) == 1'

  req "$PARENT_AUTH" POST /family/move "{\"family_id\":\"$F2\",\"device_id\":\"$D2\"}"
  ok "moving a family that is not the account's -> 403 xp_family_forbidden" '.status == 403 and .body.code == "xp_family_forbidden"'

  req "$PARENT_AUTH" POST /family/move "{\"family_id\":\"$F1\",\"device_id\":\"$D2\"}"
  ok "POST /family/move F1 to D2 -> ok" '.status == 200 and .body.ok == true'

  req "$PARENT_AUTH" POST /family "{\"family_id\":\"$F1\",\"device_id\":\"$D2\"}"
  ok "F1 from D2 is now home" ".status == 200 and .body.answer == \"home\""

  req "$PARENT_AUTH" POST /family "{\"family_id\":\"$F1\",\"device_id\":\"$D1\"}"
  ok "F1 from D1 is now home-elsewhere" ".status == 200 and .body.answer == \"home-elsewhere\""

  # The hero of a hero route is the one in its path, never a hero_id in the query or the body.
  req "$PARENT_AUTH" POST "/heroes/h-1?hero_id=h-7" '{"nickname":"Path","age_group":"child","hero_id":"h-8"}'
  req "$PARENT_AUTH" GET /family
  ok "POST /heroes/h-1 with another hero_id in the query and body saves h-1" \
    '.status == 200 and ([.body.heroes[] | select(.hero_id == "h-1" and .nickname == "Path")] | length) == 1 and ([.body.heroes[] | select(.hero_id == "h-7" or .hero_id == "h-8")] | length) == 0'
}

# --- phones: link codes, claiming, hero keys, the phone list ---------------------------------

# make_code <hero id>: a new link code from the parent; sets CODE.
make_code() {
  req "$PARENT_AUTH" POST "/heroes/$1/link-codes"
  CODE="$(field .code)"
}

# claim <typed code> <device id> <label>: sets KEY (empty when the claim failed).
claim() {
  req none POST /devices/claim "{\"code\":\"$1\",\"device_id\":\"$2\",\"label\":\"$3\"}"
  KEY="$(field .key)"
}

# The id of the parent's listed phone with this label (empty when it is not listed).
phone_id() {
  req "$PARENT_AUTH" GET /devices
  jq -r --arg label "$1" '[.[] | select(.label == $label) | .id] | first // empty' "$TMP/body" 2> /dev/null
}

section_phones() {
  printf -- '-- phones\n'
  reset_family || return 0

  req none POST /heroes/h-1/link-codes
  ok "no auth makes a link code -> 401" '.status == 401'

  req "$PARENT_AUTH" POST /heroes/h-9/link-codes
  ok "a link code for a hero not in the family -> 404 xp_family_unknown_hero" '.status == 404 and .body.code == "xp_family_unknown_hero"'

  make_code h-1
  ok "a link code for h-1: 8 alphabet characters" '.status == 200 and (.body.code | test("^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$"))'
  ok "the code expires about 10 minutes ahead" '(.body.expires_at | fromdateiso8601) - now | (. > 540 and . < 660)'

  claim "${CODE:0:4}-${CODE:4:4}" "$PHONE1" "Smoke phone one"
  ok "claim with ABCD-EFGH formatting -> a hero key" \
    ".status == 200 and (.body.key | test(\"^xph_[A-Za-z0-9_-]{43}$\")) and .body.family_id == \"$F1\" and .body.hero_id == \"h-1\" and (.body | has(\"card\"))"
  local key1="$KEY"

  claim "$CODE" "$PHONE2" "Smoke phone again"
  ok "the same code again -> 404 xp_family_code_invalid" '.status == 404 and .body.code == "xp_family_code_invalid"'

  make_code h-2
  local expired="$CODE"
  sql "UPDATE ${PREFIX}xp_family_link_codes SET expires_at = '2000-01-01 00:00:00'
    WHERE family_id = '$F1' AND code_hash = SHA2('$expired', 256);" > /dev/null
  claim "$expired" "$PHONE2" "Smoke phone expired"
  ok "an expired code -> 404 xp_family_code_invalid" '.status == 404 and .body.code == "xp_family_code_invalid"'

  make_code h-2
  local first="$CODE"
  make_code h-2
  local second="$CODE"
  claim "$first" "$PHONE2" "Smoke phone replaced"
  ok "a second code for a hero makes the first fail -> 404" '.status == 404 and .body.code == "xp_family_code_invalid"'
  claim "$(printf '%s %s' "${second:0:4}" "${second:4:4}" | tr '[:upper:]' '[:lower:]')" "$PHONE2" ""
  ok "the second code, typed as abcd efgh -> a key for h-2" '.status == 200 and .body.hero_id == "h-2" and (.body.key | startswith("xph_"))'
  local key2="$KEY"

  req none POST /devices/claim "{\"code\":\"$second\",\"device_id\":\"not a device\",\"label\":\"x\"}"
  ok "a claim with a bad device id -> 400 xp_family_bad_request" '.status == 400 and .body.code == "xp_family_bad_request"'

  req "key:$key1" GET /family
  ok "the key reads the family -> 200 with no age groups" \
    ".status == 200 and .body.family_id == \"$F1\" and (.body.heroes | length) == 3 and ([.body.heroes[] | has(\"age_group\")] | any | not)"

  req "key:$key1" POST /heroes/h-1/link-codes
  ok "the key makes a link code -> 403 xp_family_forbidden" '.status == 403 and .body.code == "xp_family_forbidden"'
  req "key:$key1" POST /heroes/h-1 '{"nickname":"Hacked","age_group":"adult"}'
  ok "the key saves a hero -> 403 xp_family_forbidden" '.status == 403 and .body.code == "xp_family_forbidden"'
  req "key:$key1" DELETE /heroes/h-2
  ok "the key removes a hero -> 403 xp_family_forbidden" '.status == 403 and .body.code == "xp_family_forbidden"'
  req "key:$key1" POST /family "{\"family_id\":\"$F1\",\"device_id\":\"$PHONE1\"}"
  ok "the key binds the family -> 403 xp_family_forbidden" '.status == 403 and .body.code == "xp_family_forbidden"'
  req "key:$key1" POST /family/move "{\"family_id\":\"$F1\",\"device_id\":\"$PHONE1\"}"
  ok "the key moves home -> 403 xp_family_forbidden" '.status == 403 and .body.code == "xp_family_forbidden"'
  req "key:$key1" GET /devices
  ok "the key lists the phones -> 403 xp_family_forbidden" '.status == 403 and .body.code == "xp_family_forbidden"'
  make_code h-3
  req "key:$key1" POST /devices/claim "{\"code\":\"$CODE\",\"device_id\":\"$PHONE3\",\"label\":\"x\"}"
  ok "the key claims a code -> 403 xp_family_forbidden" '.status == 403 and .body.code == "xp_family_forbidden"'

  req "key:xph_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA" GET /family
  ok "an unknown key -> 401 xp_family_key_revoked" '.status == 401 and .body.code == "xp_family_key_revoked"'
  req "key:not-a-key" GET /family
  ok "a key of the wrong shape -> 401 xp_family_key_revoked" '.status == 401 and .body.code == "xp_family_key_revoked"'

  req "$PARENT_AUTH" GET /devices
  ok "GET /devices lists both phones" \
    '.status == 200 and ([.body[] | select(.label == "Smoke phone one" and .hero_id == "h-1")] | length) == 1 and ([.body[] | select(.hero_id == "h-2" and .label == "A hero phone")] | length) == 1'
  ok "a phone that called has last_seen_at" \
    '([.body[] | select(.label == "Smoke phone one") | .last_seen_at | strings | fromdateiso8601] | length) == 1 and ([.body[] | .created_at | fromdateiso8601] | length) == 2'

  local id1 id2
  id1="$(phone_id "Smoke phone one")"
  id2="$(phone_id "A hero phone")"

  req "key:$key1" DELETE "/devices/$id2"
  ok "a key unlinks another hero's phone -> 403 xp_family_forbidden" '.status == 403 and .body.code == "xp_family_forbidden"'

  # Another account's family cannot unlink or list this family's phones.
  req "$PARENT2_AUTH" POST /family "{\"family_id\":\"$F2\",\"device_id\":\"$D2\"}"
  req "$PARENT2_AUTH" DELETE "/devices/$id1"
  req "$PARENT2_AUTH" GET /devices
  ok "another family's parent lists none of these phones" '.status == 200 and (.body | map(select(.hero_id == "h-1" or .hero_id == "h-2")) | length) == 0'
  req "key:$key1" GET /family
  ok "another family's parent cannot unlink this phone" '.status == 200'

  req "$PARENT_AUTH" DELETE "/devices/$id1"
  ok "the parent unlinks h-1's phone -> ok" '.status == 200 and .body.ok == true'
  req "key:$key1" GET /family
  ok "the unlinked key -> 401 xp_family_key_revoked" '.status == 401 and .body.code == "xp_family_key_revoked"'
  req "$PARENT_AUTH" GET /devices
  ok "the unlinked phone is off the list" ".status == 200 and ([.body[] | select(.id == ${id1:-0})] | length) == 0"

  # A hero key unlinks its own phone.
  req "key:$key2" DELETE "/devices/$id2"
  ok "a key unlinks its own phone -> ok" '.status == 200 and .body.ok == true'
  req "key:$key2" GET /family
  ok "that key -> 401 xp_family_key_revoked" '.status == 401 and .body.code == "xp_family_key_revoked"'

  # Removing a hero revokes every phone of the hero.
  make_code h-1
  claim "$CODE" "$PHONE1" "Smoke phone two"
  local key3="$KEY"
  make_code h-1
  claim "$CODE" "$PHONE2" "Smoke phone three"
  local key4="$KEY"
  req "key:$key3" GET /family
  ok "a second phone of h-1 works" '.status == 200'
  make_code h-1
  local unused="$CODE"
  req "$PARENT_AUTH" DELETE /heroes/h-1
  req "key:$key3" GET /family
  ok "DELETE /heroes/h-1 revokes its phone" '.status == 401 and .body.code == "xp_family_key_revoked"'
  req "key:$key4" GET /family
  ok "DELETE /heroes/h-1 revokes its other phone" '.status == 401 and .body.code == "xp_family_key_revoked"'
  claim "$unused" "$PHONE3" "Smoke phone late"
  ok "a code of a removed hero -> 404 xp_family_code_invalid" '.status == 404 and .body.code == "xp_family_code_invalid"'

  # The app on another origin sends the key header and reads Retry-After.
  STATUS="$(curl -s -o "$TMP/body" -D "$TMP/headers" -w '%{http_code}' -X OPTIONS \
    -H 'Origin: http://localhost:8100' -H 'Access-Control-Request-Method: GET' \
    -H 'Access-Control-Request-Headers: x-xp-hero-key' "$API/family")"
  ok "CORS allows the X-XP-Hero-Key header" '.headers | test("access-control-allow-headers:[^\n]*x-xp-hero-key")'
  ok "CORS exposes Retry-After" '.headers | test("access-control-expose-headers:[^\n]*retry-after")'

  # Wrong codes sent at the same moment still count one by one: at most 10 are tried in an hour.
  sql "$(claim_counters_sql)" > /dev/null
  local guesses=() n tried limited
  for n in $(seq 1 20); do guesses+=("{\"code\":\"ZZZZZZZZ\",\"device_id\":\"$PHONE3\",\"label\":\"Smoke guess\"}"); done
  at_once none POST /devices/claim "${guesses[@]}"
  tried="$(once_count 404)"
  limited="$(once_count 429)"
  if [ "$tried" -ge 1 ] && [ "$tried" -le 10 ] && [ $((tried + limited)) = 20 ]; then
    pass "20 wrong codes at once -> at most 10 tried (404), the rest 429"
  else
    fail "20 wrong codes at once -> at most 10 tried (404), the rest 429" "statuses $(once_statuses)"
  fi

  # 10 failed claims per address per hour, then 429 (counted from a clean counter).
  sql "$(claim_counters_sql)" > /dev/null
  local i bad=0
  for i in $(seq 1 10); do
    claim "ZZZZZZZZ" "$PHONE3" "Smoke guess"
    [ "$STATUS" = 404 ] && bad=$((bad + 1))
  done
  if [ "$bad" = 10 ]; then pass "10 wrong codes -> 404 each"; else fail "10 wrong codes -> 404 each" "$bad of 10"; fi
  claim "ZZZZZZZZ" "$PHONE3" "Smoke guess"
  ok "the 11th claim -> 429 xp_family_rate_limited" '.status == 429 and .body.code == "xp_family_rate_limited" and (.retry_after | tonumber) > 0'
  make_code h-2
  claim "$CODE" "$PHONE3" "Smoke right code"
  ok "even a right code is refused while limited -> 429" '.status == 429'
  sql "$(claim_counters_sql)" > /dev/null
  claim "$CODE" "$PHONE3" "Smoke right code"
  ok "the code still works once the counter is gone (a 429 used nothing)" '.status == 200 and .body.hero_id == "h-2"'
}

# --- events: the append-only stream, limits, rewards from the server -------------------------

uuid() { cat /proc/sys/kernel/random/uuid; }

iso_at() { date -u -d "@$1" +%Y-%m-%dT%H:%M:%SZ; }

# event <id> <hero> <device> <kind> <data json> [at]: one wire event.
event() {
  jq -nc --arg id "$1" --arg hero "$2" --arg dev "$3" --arg kind "$4" --argjson data "$5" \
    --arg at "${6:-$(iso_at "$(date +%s)")}" \
    '{ id: $id, hero_id: $hero, device_id: $dev, kind: $kind, at: $at, data: $data }'
}

# batch <event>...: the body of POST /events.
batch() { printf '%s\n' "$@" | jq -sc '{ events: . }'; }

# quests <n> <hero> <device>: the body of POST /events with n quest.done events.
quests() {
  local i
  for i in $(seq 1 "$1"); do uuid; done | jq -Rsc --arg hero "$2" --arg dev "$3" --arg at "$(iso_at "$(date +%s)")" \
    '{ events: (split("\n") | map(select(length > 0)) | map({ id: ., hero_id: $hero, device_id: $dev, kind: "quest.done", at: $at, data: { difficulty: 1, xp: 10, fight: false } })) }'
}

# link_phone <hero> <device id>: a hero key for a new phone of that hero; sets KEY.
link_phone() {
  make_code "$1"
  claim "$CODE" "$2" "Smoke phone of $1"
}

section_events() {
  printf -- '-- events\n'
  reset_family || return 0

  link_phone h-1 "$PHONE1"
  local key1="$KEY"
  link_phone h-2 "$PHONE2"
  local key2="$KEY"

  local look='{"skin":"tan","hair":"bob","hairColor":"red","outfit":"tunic","secret":"x"}'
  local card_id
  card_id="$(uuid)"
  local card
  card="$(event "$card_id" h-1 "$D1" hero.card "{\"nickname\":\"Mia\",\"look\":$look,\"jobClass\":\"knight\",\"level\":3,\"birthday\":\"2015-04-01\"}")"

  req none POST /events "$(batch "$card")"
  ok "no auth POST /events -> 401" '.status == 401'

  req "$PARENT_AUTH" POST /events "$(batch "$card")"
  ok "a hero.card with an extra birthday -> accepted" ".status == 200 and .body.accepted == [\"$card_id\"] and .body.duplicate == [] and .body.rejected == []"

  req "$PARENT_AUTH" GET /family
  ok "GET /family shows the card without birthday or secret" \
    '.status == 200 and (.body.heroes[] | select(.hero_id == "h-1") | .card | (.nickname == "Mia" and .level == 3 and .jobClass == "knight" and .look.hair == "bob" and (has("birthday") | not) and (.look | has("secret") | not) and (.updated_at | fromdateiso8601) > 0))'
  req "key:$key2" GET /family
  ok "a sibling's phone sees the card" '.status == 200 and (.body.heroes[] | select(.hero_id == "h-1") | .card.nickname) == "Mia"'

  req "$PARENT_AUTH" POST /events "$(batch "$card")"
  ok "the same id again -> duplicate" ".status == 200 and .body.accepted == [] and .body.duplicate == [\"$card_id\"]"

  req "$PARENT_AUTH" GET "/events?since=0"
  ok "GET /events?since=0 has the card once, without birthday" \
    ".status == 200 and ([.body[] | select(.id == \"$card_id\")] | length) == 1 and (.body[] | select(.id == \"$card_id\") | (.kind == \"hero.card\" and .scope == \"public\" and .hero_id == \"h-1\" and .device_id == \"$D1\" and (.data | has(\"birthday\") | not) and (.at | fromdateiso8601) > 0 and (.received_at | fromdateiso8601) > 0 and (.seq | type) == \"number\"))"

  # An older card never replaces a newer one.
  local old_card
  old_card="$(event "$(uuid)" h-1 "$D1" hero.card '{"nickname":"Old Mia","look":{"skin":"tan"},"jobClass":"knight","level":1}' "$(iso_at $(($(date +%s) - 86400)))")"
  req "$PARENT_AUTH" POST /events "$(batch "$old_card")"
  req "$PARENT_AUTH" GET /family
  ok "an older hero.card is stored but does not replace the card" '(.body.heroes[] | select(.hero_id == "h-1") | .card.nickname) == "Mia"'

  # Review focus 1: a refused event never sinks the rest of its batch.
  local good_id reward_id ghost_id future_id unknown_id empty_id
  good_id="$(uuid)"
  reward_id="$(uuid)"
  ghost_id="$(uuid)"
  future_id="$(uuid)"
  unknown_id="$(uuid)"
  empty_id="$(uuid)"
  req "$PARENT_AUTH" POST /events "$(batch \
    "$(event "$good_id" h-2 "$D1" quest.done '{"difficulty":2,"xp":"40","fight":true,"name":"take your medicine"}')" \
    "$(event "$reward_id" h-2 "$D1" reward.granted '{"xp":500,"ap":5,"reason":"cheat","sourceId":"me"}')" \
    "$(event "$ghost_id" h-9 "$D1" quest.done '{"difficulty":1,"xp":1,"fight":false}')" \
    "$(event "$future_id" h-2 "$D1" quest.done '{"difficulty":1,"xp":1,"fight":false}' "$(iso_at $(($(date +%s) + 90000)))")" \
    "$(event "$unknown_id" h-2 "$D1" hero.secret '{"x":1}')" \
    "$(event "$empty_id" h-2 "$D1" hero.leveled '{"level":"high"}')" \
    '{"id":"abc","hero_id":"h-2","device_id":"SmokeHomeDevice1","kind":"quest.done","at":"2026-01-01T00:00:00Z","data":{}}')"
  ok "a reward.granted from the parent -> rejected, the rest of the batch kept" \
    ".status == 200 and (.body.accepted | sort) == ([\"$good_id\",\"$empty_id\"] | sort) and ([.body.rejected[] | select(.id == \"$reward_id\" and .code == \"xp_family_event_invalid\")] | length) == 1"
  ok "a hero not in the family, a time a day ahead, an unknown kind and a bad id -> rejected" \
    "([.body.rejected[] | select(.code == \"xp_family_event_invalid\") | .id] | sort) == ([\"$reward_id\",\"$ghost_id\",\"$future_id\",\"$unknown_id\",\"abc\"] | sort)"
  req "$PARENT_AUTH" GET "/events?since=0"
  ok "a quest.done keeps only difficulty and fight (xp as a string is dropped, no name)" \
    "(.body[] | select(.id == \"$good_id\") | .data) == {\"difficulty\":2,\"fight\":true}"
  ok "an event whose data filters to nothing has data {} (an object)" \
    "(.body[] | select(.id == \"$empty_id\") | .data) == {}"
  ok "no rejected event was stored" \
    "([.body[] | select(.id == \"$reward_id\" or .id == \"$ghost_id\" or .id == \"$future_id\" or .id == \"$unknown_id\")] | length) == 0"

  # A hero key may write none of this base's kinds: not for itself, not for a sibling, no reward.
  local own_id sib_id key_reward_id
  own_id="$(uuid)"
  sib_id="$(uuid)"
  key_reward_id="$(uuid)"
  req "key:$key1" POST /events "$(batch \
    "$(event "$own_id" h-1 "$PHONE1" quest.done '{"difficulty":1,"xp":5,"fight":false}')" \
    "$(event "$sib_id" h-2 "$PHONE1" hero.card '{"nickname":"Hacked","look":{"skin":"tan"},"jobClass":"x","level":99}')" \
    "$(event "$key_reward_id" h-1 "$PHONE1" reward.granted '{"xp":9999,"ap":99,"reason":"x","sourceId":"x"}')")"
  ok "a hero key's events (its own, a sibling's, a reward.granted) -> all rejected" \
    ".status == 200 and .body.accepted == [] and ([.body.rejected[] | .id] | sort) == ([\"$own_id\",\"$sib_id\",\"$key_reward_id\"] | sort)"
  req "$PARENT_AUTH" GET /family
  ok "the sibling's card is untouched" '(.body.heroes[] | select(.hero_id == "h-2") | .card) == null'

  # Rewards come only from the server.
  local granted
  granted="$(wp eval "\$r = Xophz_Compass_Xp_Family_Events::grant_reward( '$F1', 'h-1', 25, 2, 'party-bonus', 'smoke-1' ); echo is_wp_error( \$r ) ? 'error: ' . \$r->get_error_message() : wp_json_encode( \$r );" 2>&1 | tail -n 1)"
  local grant_id
  grant_id="$(jq -r '.id // empty' <<< "$granted" 2> /dev/null)"
  if [ -n "$grant_id" ]; then pass "grant_reward through wp eval"; else fail "grant_reward through wp eval" "$granted"; fi
  local missing
  missing="$(wp eval "\$r = Xophz_Compass_Xp_Family_Events::grant_reward( '$F1', 'h-9', 25, 2, 'party-bonus', 'smoke-2' ); echo is_wp_error( \$r ) ? \$r->get_error_code() : 'granted';" 2>&1 | tail -n 1)"
  if [ "$missing" = xp_family_event_invalid ]; then pass "grant_reward for a hero not in the family -> refused"; else fail "grant_reward for a hero not in the family -> refused" "$missing"; fi

  req "key:$key1" GET "/events?since=0"
  ok "h-1's phone reads its reward" \
    "(.body[] | select(.id == \"$grant_id\") | (.kind == \"reward.granted\" and .scope == \"family\" and .device_id == \"server\" and .data == {\"xp\":25,\"ap\":2,\"reason\":\"party-bonus\",\"sourceId\":\"smoke-1\"}))"
  ok "h-1's phone reads the public events too" "([.body[] | select(.id == \"$card_id\" or .id == \"$good_id\")] | length) == 2"
  req "key:$key2" GET "/events?since=0"
  ok "h-2's phone does not read h-1's reward" ".status == 200 and ([.body[] | select(.id == \"$grant_id\")] | length) == 0 and ([.body[] | select(.id == \"$card_id\")] | length) == 1"
  req "$PARENT_AUTH" GET "/events?since=0"
  ok "the parent reads it" "([.body[] | select(.id == \"$grant_id\")] | length) == 1"

  # Reading by number: oldest first, after since, at most limit.
  local first_seq
  first_seq="$(jq -r '.[0].seq // 0' "$TMP/body" 2> /dev/null || echo 0)"
  ok "events come oldest first" '[.body[] | .seq] == ([.body[] | .seq] | sort)'
  req "$PARENT_AUTH" GET "/events?since=0&limit=1"
  ok "limit=1 -> one event" ".status == 200 and (.body | length) == 1 and .body[0].seq == $first_seq"
  req "$PARENT_AUTH" GET "/events?since=$first_seq"
  ok "since=<the first number> -> the events after it" ".status == 200 and ([.body[] | select(.seq <= $first_seq)] | length) == 0 and (.body | length) > 0"

  # Batches and limits.
  req "$PARENT_AUTH" POST /events "$(quests 51 h-3 "$D1")"
  ok "51 events -> 400 xp_family_bad_request" '.status == 400 and .body.code == "xp_family_bad_request"'

  local sent=0 part
  for part in 50 50; do
    req "$PARENT_AUTH" POST /events "$(quests "$part" h-3 "$D1")"
    if [ "$STATUS" = 200 ]; then sent=$((sent + $(jq '.accepted | length' "$TMP/body"))); fi
  done
  # The last 20 of the hour, as six batches of 20 at the same moment: the limit is checked under
  # the family's lock, so exactly one batch is taken and the other five get 429.
  local bodies=() n
  for n in 1 2 3 4 5 6; do bodies+=("$(quests 20 h-3 "$D1")"); done
  at_once "$PARENT_AUTH" POST /events "${bodies[@]}"
  sent=$((sent + $(jq -s 'map(.accepted // [] | length) | add // 0' "$TMP"/once.*.body)))
  if [ "$sent" = 120 ]; then pass "120 events for h-3: 50, 50, then one of six batches of 20 sent at once"; else fail "120 events for h-3: 50, 50, then one of six batches of 20 sent at once" "$sent accepted; statuses $(once_statuses)"; fi
  if [ "$(once_count 429)" = 5 ]; then pass "the other five batches sent at once -> 429"; else fail "the other five batches sent at once -> 429" "statuses $(once_statuses)"; fi
  req "$PARENT_AUTH" POST /events "$(quests 1 h-3 "$D1")"
  ok "one more for h-3 -> 429 with Retry-After: 600" '.status == 429 and .body.code == "xp_family_rate_limited" and .retry_after == "600"'
  req "$PARENT_AUTH" POST /events "$(quests 1 h-2 "$D1")"
  ok "h-2 can still send" '.status == 200 and (.body.accepted | length) == 1'

  # Moving home: the old home's next send gets 409 for the whole batch.
  req "$PARENT_AUTH" POST /family/move "{\"family_id\":\"$F1\",\"device_id\":\"$D2\"}"
  req "$PARENT_AUTH" POST /events "$(quests 1 h-2 "$D1")"
  ok "after moving home to D2, a send from D1 -> 409 xp_family_moved" '.status == 409 and .body.code == "xp_family_moved"'
  req "$PARENT_AUTH" POST /events "$(quests 1 h-2 "$D2")"
  ok "a send from D2 -> accepted" '.status == 200 and (.body.accepted | length) == 1'

  # Kept 90 days, counted from when the site received an event.
  sql "UPDATE ${PREFIX}xp_family_events SET received_at = UTC_TIMESTAMP() - INTERVAL 91 DAY
    WHERE family_id = '$F1' AND event_id = '$good_id';" > /dev/null
  wp eval 'do_action( "xp_family_cleanup" );' > /dev/null 2>&1
  req "$PARENT_AUTH" GET "/events?since=0&limit=200"
  ok "an event received 91 days ago is gone after xp_family_cleanup" \
    ".status == 200 and ([.body[] | select(.id == \"$good_id\")] | length) == 0 and ([.body[] | select(.id == \"$card_id\")] | length) == 1"
}

# The records section: the family's save, a hero's turn and the Pro gate.
source "$(dirname "${BASH_SOURCE[0]}")/family-smoke-records.sh"

# --- run -------------------------------------------------------------------------------------
setup

case "$SECTION" in
  family) section_family ;;
  phones) section_phones ;;
  events) section_events ;;
  records) section_records ;;
  all)
    section_family
    section_phones
    section_events
    section_records
    ;;
esac

printf -- '-- %d passed, %d failed\n' "$PASSED" "$FAILED"
[ "$FAILED" -eq 0 ]
