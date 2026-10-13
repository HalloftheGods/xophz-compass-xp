#!/usr/bin/env bash
# Rehearsal smoke for the family routes (xp-family/v1) of Do It For The XP.
#
# Drives the real routes on the Town Hall's throwaway network (TH7) with curl and checks each
# answer. Never point it at the live site.
#
#   tests/rehearsal/family-smoke.sh [family|phones|events|all]     (default: all)
#
#   SITE     the family site      (default http://localhost:8090/doit)
#   COMPOSE  the rehearsal file   (default $HOME/www/x/do-it-for-the-xp/tools/townhall-rehearsal/docker-compose.yml)
#   PARENT   the parent account   (default famparent; made when missing)
#
# Prints PASS <name> or FAIL <name> per check and exits 1 on any failure.
#
# It touches only what it makes: the families F1 and F2 (and their heroes, phones, codes and
# events), the users smokeparent2 and smokeoutsider, the Application Passwords named "smoke" and
# its own claim counters. Each section starts with reset_family, so a section runs on its own,
# and the run ends by removing the smoke families and the passwords it made.
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

SECTION="${1:-all}"
case "$SECTION" in
  family | phones | events | all) ;;
  *)
    printf 'usage: %s [family|phones|events|all]\n' "$0" >&2
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

# Run SQL on the rehearsal database (smoke rows only).
sql() { wp db query "$1" --skip-column-names; }

smoke_families_sql() {
  local in="('$F1','$F2')"
  printf '%s' "DELETE FROM ${PREFIX}xp_family_events WHERE family_id IN $in;
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

# Remove what the run made: the smoke families, the claim counters and the smoke passwords.
teardown() {
  if [ -n "$PREFIX" ]; then
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
}

# --- run -------------------------------------------------------------------------------------
setup

case "$SECTION" in
  family) section_family ;;
  all)
    section_family
    ;;
esac

printf -- '-- %d passed, %d failed\n' "$PASSED" "$FAILED"
[ "$FAILED" -eq 0 ]
