#/bin/bash

curl \
    -H "Content-Type: application/json" \
    -X POST \
    --data \
    \
    "{ \
        \"cast\":\"unicast\", \
        \"command\":\"openlock\", \
        \"deviceid\":\"0001\", \
        \"signal_id\":\"1\" \
    }" \
\
http://120.79.69.80:18010/

