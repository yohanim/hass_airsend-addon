#!/usr/bin/env bash
# Functional test of the AirSend callback (hass_cb.php) against a mocked
# Home Assistant REST API. Requires php (with curl), curl and jq.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
export MOCK_DIR="$WORK/ha"
mkdir -p "$MOCK_DIR" "$WORK/cb"
cp "$ROOT/addons/airsend/hass_cb.php" "$ROOT/addons/airsend/hassapi.class.php" "$WORK/cb/"

HA_PORT=18123
CB_PORT=18080
FAILED=0

uid_of() { printf '%d' "0x$(printf '%s' "$1" | sha256sum | cut -c1-12)"; }
uid_hex_of() { printf '0x%s' "$(printf '%s' "$1" | sha256sum | cut -c1-12)"; }

# Initial Home Assistant states: a few AirSend entities and many others
python3 - "$MOCK_DIR/states.json" <<'PY'
import json, sys
states = {
    "cover.volet_salon": {"entity_id": "cover.volet_salon", "state": "closed",
        "attributes": {"channel": {"id": 25455, "source": 94311}, "supported_features": 15}},
    "sensor.capteur_temp": {"entity_id": "sensor.capteur_temp", "state": "unknown",
        "attributes": {"channel": {"id": 6000, "source": 4}, "device_class": "temperature"}},
    "button.portail": {"entity_id": "button.portail", "state": "unknown", "attributes": {}},
}
for i in range(2000):
    states[f"light.filler_{i}"] = {"entity_id": f"light.filler_{i}", "state": "off",
        "attributes": {"friendly_name": f"Filler {i}"}}
json.dump(states, open(sys.argv[1], "w"))
PY

php -S 127.0.0.1:$HA_PORT "$ROOT/tests/mock_ha.php" >"$WORK/ha.log" 2>&1 &
HA_PID=$!
(cd "$WORK/cb" && HASS_HOST="127.0.0.1:$HA_PORT" HASS_TOKEN=test-token HASS_AUTOINCLUDE=1 \
    php -S 127.0.0.1:$CB_PORT hass_cb.php >"$WORK/cb.log" 2>&1) &
CB_PID=$!
trap 'kill $HA_PID $CB_PID 2>/dev/null || true; rm -rf "$WORK"' EXIT
sleep 1

send() { curl -s -o /dev/null -w '%{http_code}' -X POST --data "$1" "http://127.0.0.1:$CB_PORT/"; }
last_post() { grep "^$1 " "$MOCK_DIR/posts.log" | tail -n1 | cut -d' ' -f2-; }
check() {
    # usage: check <label> <actual> <expected>
    if [ "$2" == "$3" ]; then echo "ok   - $1"; else echo "FAIL - $1: expected '$3', got '$2'"; FAILED=1; fi
}

# 1. Transfer answer for the cover (uid as a JSON number) -> open, position 100
send "{\"events\":[{\"channel\":{\"id\":25455,\"source\":94311},\"type\":3,\"timestamp\":1,\"thingnotes\":{\"uid\":$(uid_of cover.volet_salon),\"notes\":[{\"method\":1,\"type\":0,\"value\":\"UP\"}]}}]}" >/dev/null
check "cover state from transfer uid" "$(last_post cover.volet_salon | jq -r .state)" "open"
check "cover position" "$(last_post cover.volet_salon | jq -r .attributes.current_position)" "100"
check "cover channel attribute kept" "$(last_post cover.volet_salon | jq -c .attributes.channel)" '{"id":25455,"source":94311}'

# 2. Sensor interrupt matched by channel and device class -> 22 °C
send '{"events":[{"channel":{"id":6000,"source":4},"type":3,"reliability":32,"timestamp":2,"thingnotes":{"notes":[{"method":1,"type":2,"value":295.15}]}}]}' >/dev/null
check "temperature from channel" "$(last_post sensor.capteur_temp | jq -r .state | xargs printf '%g')" "22"

# 3. Button toggles (uid as "0x..." string); a repeated press gets a "+"
send "{\"events\":[{\"channel\":{\"id\":4000,\"source\":2},\"type\":3,\"timestamp\":3,\"thingnotes\":{\"uid\":\"$(uid_hex_of button.portail)\",\"notes\":[{\"method\":1,\"type\":0,\"value\":\"TOGGLE\"}]}}]}" >/dev/null
check "button pressed" "$(last_post button.portail | jq -r .state)" "pressed"
send "{\"events\":[{\"channel\":{\"id\":4000,\"source\":2},\"type\":3,\"timestamp\":4,\"thingnotes\":{\"uid\":\"$(uid_hex_of button.portail)\",\"notes\":[{\"method\":1,\"type\":0,\"value\":\"TOGGLE\"}]}}]}" >/dev/null
check "button pressed again" "$(last_post button.portail | jq -r .state)" "pressed+"

# 4. Unknown uid -> nothing posted
before=$(wc -l <"$MOCK_DIR/posts.log")
send '{"events":[{"channel":{"id":1,"source":1},"type":3,"timestamp":5,"thingnotes":{"uid":123456,"notes":[{"method":1,"type":0,"value":"UP"}]}}]}' >/dev/null
check "unknown uid ignored" "$(wc -l <"$MOCK_DIR/posts.log")" "$before"

# 5. Unreliable reception ignored
send '{"events":[{"channel":{"id":6000,"source":4},"type":3,"reliability":2,"timestamp":6,"thingnotes":{"notes":[{"method":1,"type":2,"value":300.15}]}}]}' >/dev/null
check "unreliable reception ignored" "$(wc -l <"$MOCK_DIR/posts.log")" "$before"

# 6. New channel with auto include -> new sensor created, then found by channel
send '{"events":[{"channel":{"id":7000,"source":5},"type":3,"reliability":32,"timestamp":7,"thingnotes":{"notes":[{"method":1,"type":2,"value":283.15}]}}]}' >/dev/null
check "auto included sensor" "$(last_post sensor.7000_5_temp | jq -r .state | xargs printf '%g')" "10"
check "auto included device class" "$(last_post sensor.7000_5_temp | jq -r .attributes.device_class)" "temperature"
send '{"events":[{"channel":{"id":7000,"source":5},"type":3,"reliability":32,"timestamp":8,"thingnotes":{"notes":[{"method":1,"type":2,"value":284.15}]}}]}' >/dev/null
check "new sensor updated" "$(last_post sensor.7000_5_temp | jq -r .state | xargs printf '%g')" "11"

# Performance: full state downloads are cached (one initial, one after the
# new sensor was created) instead of one per event / per toggle.
full=$(grep -c '^GET /api/states$' "$MOCK_DIR/requests.log" || true)
echo "info - 9 events, $full full /api/states download(s), $(grep -c '^GET /api/states/' "$MOCK_DIR/requests.log") single state read(s)"
check "full state downloads" "$full" "2"

if [ "$FAILED" -ne 0 ]; then
    echo "--- callback log"; cat "$WORK/cb.log"
    echo "--- requests"; cat "$MOCK_DIR/requests.log"
    exit 1
fi
echo "All callback tests passed"
