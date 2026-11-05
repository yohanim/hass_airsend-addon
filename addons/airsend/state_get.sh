#!/usr/bin/with-contenv bashio
# Print the state of one entity (JSON) through the Supervisor proxy.
# Prints nothing if the entity does not exist.

curl -s -f -X GET -H "Authorization: Bearer ${SUPERVISOR_TOKEN}" -H "Content-Type: application/json" "http://supervisor/core/api/states/$1"
