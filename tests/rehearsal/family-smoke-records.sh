# The records section of the family smoke: the family's save as records (PUT and GET /records),
# a hero's turn (play and rest), the Pro gate and the home rule it relaxes. Sourced by family-smoke.sh, which holds the
# helpers it uses (req, ok, field, sql, wp, make_code, claim, reset_family, ...) and its variables;
# it is not run on its own.

# rec <store> <key> <hero id, or - for none> <body: the record's JSON text, or empty for a removal>
# One record as it travels: the body is a JSON string, hero_id is null for a family record.
rec() {
  jq -nc --arg store "$1" --arg key "$2" --arg hero "$3" --arg body "$4" \
    '{ store: $store, key: $key, hero_id: (if $hero == "-" then null else $hero end), deleted: ($body == ""), body: $body }'
}

# put_body <device id> <record>...: the JSON body of a PUT /records.
put_body() {
  local device="$1"
  shift
  printf '%s\n' "$@" | jq -sc --arg device "$device" '{ device_id: $device, records: . }'
}

# turn <auth> <play|rest> <hero> <device id> [label] [force]: POST /heroes/<hero>/<play|rest>.
turn() {
  local auth="$1" verb="$2" hero="$3" device="$4" label="${5:-}" force="${6:-false}"
  req "$auth" POST "/heroes/$hero/$verb" \
    "$(jq -nc --arg d "$device" --arg l "$label" --argjson f "$force" '{ device_id: $d, label: $l, force: $f }')"
}

# How old, in seconds, a busy answer says its holder was last seen.
SEEN_AGO='(now - (.body.device.last_seen_at | fromdateiso8601))'

section_records() {
  printf -- '-- records\n'
  reset_family || return 0

  local debug
  debug="$(wp eval 'echo WP_DEBUG ? "on" : "off";' 2> /dev/null | tail -n 1 | tr -d '\r')"
  if [ "$debug" != on ]; then
    fail "the rehearsal runs with WP_DEBUG on (the Pro override needs it)" "WP_DEBUG is '$debug'"
    return 0
  fi

  make_code h-1
  claim "$CODE" "$PHONE1" "Smoke phone one"
  local key1="$KEY"
  if [ -z "$key1" ]; then
    fail "h-1's phone claims a hero key" "HTTP $STATUS $(head -c 300 "$TMP/body")"
    return 0
  fi

  # --- the Pro gate: no licence on the rehearsal, no override yet -----------------------------
  wp option delete "$PRO_OVERRIDE" > /dev/null 2>&1
  local sweep='{"name":"Sweep"}'
  local first
  first="$(put_body "$D1" "$(rec __achievements ql-sweep - "$sweep")")"

  req none PUT /records "$first"
  ok "no auth PUT /records -> 401" '.status == 401'
  req "$PARENT_AUTH" PUT /records "$first"
  ok "an unentitled family: PUT /records -> 403 xp_family_pro_required" '.status == 403 and .body.code == "xp_family_pro_required"'
  req "$PARENT_AUTH" GET "/records?since=0"
  ok "an unentitled family: GET /records -> 403 xp_family_pro_required" '.status == 403 and .body.code == "xp_family_pro_required"'
  turn "$PARENT_AUTH" play h-1 "$D1" "the family tablet"
  ok "an unentitled family: play -> 403 xp_family_pro_required" '.status == 403 and .body.code == "xp_family_pro_required"'
  turn "$PARENT_AUTH" rest h-1 "$D1"
  ok "an unentitled family: rest -> 403 xp_family_pro_required" '.status == 403 and .body.code == "xp_family_pro_required"'
  req "key:$key1" PUT /records "$first"
  ok "an unentitled family: a hero key's PUT -> 403 xp_family_pro_required" '.status == 403 and .body.code == "xp_family_pro_required"'

  if wp option update "$PRO_OVERRIDE" 1 > /dev/null 2>&1; then
    OVERRIDE_SET=1
  else
    fail "the rehearsal Pro override is set" "wp option update $PRO_OVERRIDE failed"
    return 0
  fi

  # --- the first batch, before anyone has the turn --------------------------------------------
  local log1='{"userId":"h-1","questId":"ql-sweep","extra":{},"list":[]}'
  req "$PARENT_AUTH" PUT /records "$(put_body "$D1" \
    "$(rec __achievements ql-sweep - "$sweep")" \
    "$(rec __family_settings settings - '{"theme":"snes"}')" \
    "$(rec __questLog log-1 h-1 "$log1")")"
  ok "3 records before the turn: the 2 family ones accepted as 1 and 2, the hero's refused xp_family_turn" \
    '.status == 200 and ([.body.accepted[] | .seq] == [1, 2]) and ([.body.accepted[] | .store] == ["__achievements", "__family_settings"])
     and (.body.refused | length) == 1 and .body.refused[0].store == "__questLog" and .body.refused[0].key == "log-1" and .body.refused[0].code == "xp_family_turn"'

  req "$PARENT_AUTH" PUT /records "$(put_body "not a device" "$(rec __achievements ql-sweep - "$sweep")")"
  ok "a parent PUT with a bad device id -> 400 xp_family_bad_request" '.status == 400 and .body.code == "xp_family_bad_request"'

  # --- the parent device plays: granted, then the batch again ---------------------------------
  turn "$PARENT_AUTH" play h-1 "$D1" "the family tablet"
  ok "D1 plays h-1 -> granted" '.status == 200 and .body.answer == "granted"'
  turn "$PARENT_AUTH" play h-2 "$D1" "the family tablet"
  ok "D1 plays h-2 -> granted" '.status == 200 and .body.answer == "granted"'
  turn "$PARENT_AUTH" play h-1 "$D1" "the family tablet"
  ok "D1 plays h-1 again -> granted" '.status == 200 and .body.answer == "granted"'
  turn "$PARENT_AUTH" play h-9 "$D1" "the family tablet"
  ok "playing a hero that is not in the family -> 404 xp_family_no_hero" '.status == 404 and .body.code == "xp_family_no_hero"'

  local swept='{"name":"Sweep","reward":5}'
  req "$PARENT_AUTH" PUT /records "$(put_body "$D1" \
    "$(rec __achievements ql-sweep - "$swept")" \
    "$(rec __family_settings settings - '{"theme":"snes"}')" \
    "$(rec __questLog log-1 h-1 "$log1")" \
    "$(rec __questLog log-2 h-2 '{"userId":"h-2","questId":"ql-sweep"}')")"
  ok "put again with the turns held: all 4 accepted, numbered 3 to 6 after the first batch" \
    '.status == 200 and ([.body.accepted[] | .seq] == [3, 4, 5, 6]) and (.body.refused | length) == 0'

  # --- reading: the parent reads all, a hero key the family and its own hero ------------------
  req "$PARENT_AUTH" GET "/records?since=0"
  ok "the parent reads since 0: the 4 rows, oldest first, each body a string" \
    '.status == 200 and ([.body[] | .seq] == [3, 4, 5, 6]) and ([.body[] | .key] == ["ql-sweep", "settings", "log-1", "log-2"])
     and all(.body[]; (.body | type) == "string" and .deleted == false)'
  ok "a family row has a null hero_id, a hero row its hero, every row its device and an ISO saved_at" \
    "([.body[] | select(.store == \"__achievements\" or .store == \"__family_settings\") | .hero_id] == [null, null])
     and ([.body[] | select(.store == \"__questLog\") | .hero_id] == [\"h-1\", \"h-2\"])
     and all(.body[]; .device_id == \"$D1\" and (.saved_at | fromdateiso8601 | . > 0))"
  ok "the rewritten record comes back with its new body, byte for byte" \
    '(.body[] | select(.key == "ql-sweep") | .body) == "{\"name\":\"Sweep\",\"reward\":5}"'
  ok "a body with an empty object and an empty list comes back as written" \
    '(.body[] | select(.key == "log-1") | .body) == "{\"userId\":\"h-1\",\"questId\":\"ql-sweep\",\"extra\":{},\"list\":[]}"'

  req "key:$key1" GET "/records?since=0"
  ok "h-1's phone reads the family rows and h-1's own, never h-2's" \
    '.status == 200 and ([.body[] | .key] == ["ql-sweep", "settings", "log-1"])'

  req "$PARENT_AUTH" GET "/records?since=0&limit=2"
  ok "limit=2 -> the first two rows" '.status == 200 and ([.body[] | .seq] == [3, 4])'
  req "$PARENT_AUTH" GET "/records?since=4"
  ok "since=4 -> the rows after it" '.status == 200 and ([.body[] | .seq] == [5, 6])'
  req "$PARENT_AUTH" GET "/records?since=6"
  ok "since=<the last number> -> nothing" '.status == 200 and .body == []'

  # --- a second device: busy, and how long ago the holder was seen ----------------------------
  sql "UPDATE ${PREFIX}xp_family_heroes SET turn_seen_at = UTC_TIMESTAMP() - INTERVAL 10 MINUTE
    WHERE family_id = '$F1' AND hero_id = 'h-1';" > /dev/null
  turn "$PARENT_AUTH" play h-1 "$D2" "the family computer"
  ok "D2 plays h-1 -> busy, naming the family tablet, last seen about 10 minutes ago" \
    ".status == 200 and .body.answer == \"busy\" and .body.device.label == \"the family tablet\" and $SEEN_AGO > 540 and $SEEN_AGO < 700"
  req "$PARENT_AUTH" GET "/records?since=6&device_id=$D1"
  turn "$PARENT_AUTH" play h-1 "$D2" "the family computer"
  ok "after D1 reads as the holder, D2 is told it was seen within the minute" \
    ".status == 200 and .body.answer == \"busy\" and .body.device.label == \"the family tablet\" and $SEEN_AGO < 120"
  turn "key:$key1" play h-1 "" "the family tablet"
  ok "h-1's own phone plays h-1 -> busy as well" '.status == 200 and .body.answer == "busy" and .body.device.label == "the family tablet"'
  turn "key:$key1" play h-2 ""
  ok "h-1's phone asks for h-2's turn -> 403 xp_family_scope" '.status == 403 and .body.code == "xp_family_scope"'

  # --- a hero key's writes --------------------------------------------------------------------
  req "key:$key1" PUT /records "$(put_body "" "$(rec __profiles h-1 h-1 '{"id":"h-1","name":"Mia"}')")"
  ok "h-1's phone writes h-1's profile while D1 holds the turn -> refused xp_family_turn" \
    '.status == 200 and .body.accepted == [] and .body.refused[0].store == "__profiles" and .body.refused[0].code == "xp_family_turn"'
  req "key:$key1" PUT /records "$(put_body "" \
    "$(rec __achievements ql-hack - '{"name":"Hack"}')" \
    "$(rec __questLog log-2x h-2 '{"userId":"h-2"}')" \
    "$(rec __chat msg-1 - '{"text":"Hello"}')")"
  ok "a hero key: a family store and h-2's log -> xp_family_scope, its chat message accepted as 7" \
    '.status == 200 and ([.body.refused[] | .code] == ["xp_family_scope", "xp_family_scope"]) and ([.body.accepted[] | [.store, .key, .seq]] == [["__chat", "msg-1", 7]])'
  req "$PARENT_AUTH" GET "/records?since=6"
  ok "the parent reads the chat row under the hero that wrote it, from the hero's phone" \
    ".status == 200 and ([.body[] | [.key, .hero_id, .device_id]] == [[\"msg-1\", \"h-1\", \"$PHONE1\"]])"

  # --- keys: case, trailing space, unknown stores; the rest of a batch carries on -------------
  req "$PARENT_AUTH" PUT /records "$(put_body "$D1" \
    "$(rec __achievements 'ql-sweep ' - '{"name":"Space"}')" \
    "$(rec __localSettings sound - '{"on":true}')" \
    "$(rec __achievements Ql-Sweep - '{"name":"Case"}')" \
    "$(rec __shops shop-1 - '{"name":"Shop"}')")"
  ok "a key with a trailing space -> xp_family_record_invalid, __localSettings -> xp_family_store_unknown, the rest accepted as 8 and 9" \
    '.status == 200 and ([.body.refused[] | .code] == ["xp_family_record_invalid", "xp_family_store_unknown"]) and ([.body.accepted[] | [.key, .seq]] == [["Ql-Sweep", 8], ["shop-1", 9]])'
  req "$PARENT_AUTH" GET "/records?since=0"
  ok "keys that differ only by case are two records, and the trailing-space key touched neither" \
    '([.body[] | select(.store == "__achievements") | .key] | sort) == ["Ql-Sweep", "ql-sweep"]
     and (.body[] | select(.key == "ql-sweep") | .body) == "{\"name\":\"Sweep\",\"reward\":5}"'

  # --- a removal is a tombstone, and the cleanup drops old ones only --------------------------
  req "$PARENT_AUTH" PUT /records "$(put_body "$D1" "$(rec __shops shop-1 - '')")"
  ok "removing shop-1 -> accepted as 10" '.status == 200 and ([.body.accepted[] | [.key, .seq]] == [["shop-1", 10]]) and (.body.refused | length) == 0'
  req "$PARENT_AUTH" GET "/records?since=9"
  ok "the removal reads as a tombstone: deleted true, an empty body" \
    '.status == 200 and (.body | length) == 1 and .body[0].key == "shop-1" and .body[0].deleted == true and .body[0].body == ""'
  sql "UPDATE ${PREFIX}xp_family_records SET saved_at = UTC_TIMESTAMP() - INTERVAL 91 DAY
    WHERE family_id = '$F1' AND record_key IN ('shop-1', 'Ql-Sweep');" > /dev/null
  wp eval 'do_action( "xp_family_cleanup" );' > /dev/null 2>&1
  req "$PARENT_AUTH" GET "/records?since=0&limit=200"
  ok "xp_family_cleanup drops the 91 day old tombstone and keeps the live record of the same age" \
    '.status == 200 and ([.body[] | select(.key == "shop-1")] | length) == 0 and ([.body[] | select(.key == "Ql-Sweep")] | length) == 1'

  # --- the takeover: force, the old holder is refused, a late rest changes nothing ------------
  turn "$PARENT_AUTH" play h-1 "$D2" "the family computer" true
  ok "D2 plays h-1 with force -> granted" '.status == 200 and .body.answer == "granted"'
  req "$PARENT_AUTH" PUT /records "$(put_body "$D1" "$(rec __questLog log-1 h-1 "$log1")")"
  ok "D1's next write of h-1's log -> refused xp_family_turn" \
    '.status == 200 and .body.accepted == [] and .body.refused[0].key == "log-1" and .body.refused[0].code == "xp_family_turn"'
  req "$PARENT_AUTH" PUT /records "$(put_body "$D1" "$(rec __questLog log-2 h-2 '{"userId":"h-2","questId":"ql-sweep","done":true}')")"
  ok "D1 still holds h-2: its write of h-2's log is accepted" '.status == 200 and (.body.accepted | length) == 1 and (.body.refused | length) == 0'
  req "$PARENT_AUTH" PUT /records "$(put_body "$D2" "$(rec __questLog log-1 h-1 "$log1")")"
  ok "D2's write of h-1's log is accepted" '.status == 200 and (.body.accepted | length) == 1 and (.body.refused | length) == 0'
  turn "$PARENT_AUTH" rest h-1 "$D1"
  ok "D1's late rest -> rested" '.status == 200 and .body.answer == "rested"'
  turn "$PARENT_AUTH" play h-1 "$D1" "the family tablet"
  ok "...and changed nothing: D2 still holds h-1 and is named" '.status == 200 and .body.answer == "busy" and .body.device.label == "the family computer"'

  # --- rest: the holder gives the turn back ---------------------------------------------------
  turn "$PARENT_AUTH" rest h-1 "$D2"
  ok "D2 rests h-1 -> rested" '.status == 200 and .body.answer == "rested"'
  req "$PARENT_AUTH" PUT /records "$(put_body "$D2" "$(rec __questLog log-1 h-1 "$log1")")"
  ok "after the rest nobody holds h-1: D2's write is refused xp_family_turn" '.status == 200 and .body.accepted == [] and .body.refused[0].code == "xp_family_turn"'

  # --- a hero's own phone takes the turn under the label it was linked with -------------------
  turn "key:$key1" play h-1 "" "the family tablet"
  ok "h-1's phone plays h-1 -> granted" '.status == 200 and .body.answer == "granted"'
  turn "$PARENT_AUTH" play h-1 "$D1" "the family tablet"
  ok "D1 is told the phone's own label, not the 'the family tablet' it sent" '.status == 200 and .body.answer == "busy" and .body.device.label == "Smoke phone one"'
  req "key:$key1" PUT /records "$(put_body "" "$(rec __profiles h-1 h-1 '{"id":"h-1","name":"Mia"}')")"
  ok "h-1's phone writes h-1's profile as the holder -> accepted" '.status == 200 and (.body.accepted | length) == 1 and (.body.refused | length) == 0'
  turn "key:$key1" rest h-1 ""
  ok "h-1's phone rests h-1 -> rested" '.status == 200 and .body.answer == "rested"'
  turn "$PARENT_AUTH" play h-1 "$D1" "the family tablet"
  ok "D1 plays h-1 -> granted, the phone having rested" '.status == 200 and .body.answer == "granted"'

  # --- the relaxed home rule: an entitled family's parent may send from any of its devices ---
  req "$PARENT_AUTH" POST /events "$(quests 1 h-3 "$D2")"
  ok "an entitled family: a parent's events from D2, which is not home, are accepted" '.status == 200 and (.body.accepted | length) == 1'

  # --- CORS: the app on another origin sends PUT with an Authorization header -----------------
  STATUS="$(curl -s -o "$TMP/body" -D "$TMP/headers" -w '%{http_code}' -X OPTIONS \
    -H 'Origin: http://localhost:8100' -H 'Access-Control-Request-Method: PUT' \
    -H 'Access-Control-Request-Headers: authorization,content-type,x-xp-hero-key' "$API/records" 2> /dev/null)"
  ok "the preflight for PUT /records from another origin is allowed" \
    '.status == 200 and (.headers | test("access-control-allow-origin: http://localhost:8100"))
     and (.headers | test("access-control-allow-methods:[^\n]*put")) and (.headers | test("access-control-allow-headers:[^\n]*authorization"))
     and (.headers | test("access-control-allow-headers:[^\n]*x-xp-hero-key"))'

  # --- the reset clears the records, the counter and the turns --------------------------------
  turn "$PARENT_AUTH" play h-3 "$D1" "the family tablet"
  reset_family || return 0
  local left
  left="$(sql "SELECT (SELECT COUNT(*) FROM ${PREFIX}xp_family_records WHERE family_id = '$F1'),
    (SELECT records_seq FROM ${PREFIX}xp_families WHERE family_id = '$F1'),
    (SELECT COUNT(*) FROM ${PREFIX}xp_family_heroes WHERE family_id = '$F1' AND turn_device_id IS NOT NULL);" | tr -d '\r')"
  if [ "$left" = "$(printf '0\t0\t0')" ]; then
    pass "reset_family clears the records, the counter and the turns"
  else
    fail "reset_family clears the records, the counter and the turns" "rows/counter/turns: $left"
  fi

  # --- the override goes: the family is unentitled again --------------------------------------
  if wp option delete "$PRO_OVERRIDE" > /dev/null 2>&1; then
    OVERRIDE_SET=0
  else
    fail "the rehearsal Pro override is removed" "wp option delete $PRO_OVERRIDE failed"
  fi
  req "$PARENT_AUTH" PUT /records "$first"
  ok "with the override removed, PUT /records -> 403 xp_family_pro_required again" '.status == 403 and .body.code == "xp_family_pro_required"'
  req "$PARENT_AUTH" POST /events "$(quests 1 h-3 "$D2")"
  ok "...and a parent's events from D2, which is not home, -> 409 xp_family_moved again" '.status == 409 and .body.code == "xp_family_moved"'
}
