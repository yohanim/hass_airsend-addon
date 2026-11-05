#!/usr/bin/with-contenv bashio
# Post the state of one entity through the Supervisor proxy.
# $1: entity_id, $2: base64 encoded JSON payload (decoded as is, never
# re-interpreted by the shell).

printf '%s' "$2" | base64 -d | curl -s --data-binary @- -X POST -H "Authorization: Bearer ${SUPERVISOR_TOKEN}" -H "Content-Type: application/json" "http://supervisor/core/api/states/$1"
