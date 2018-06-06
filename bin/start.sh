#!/bin/bash

cd /opt/entrance-guard/
MAIN_PATH=`pwd`
echo $MAIN_PATH
echo "" > $MAIN_PATH/log/ws.std
nohup php $MAIN_PATH/src/workerman-server.php start >> $MAIN_PATH/log/ws.std 2>&1 &
tail -f $MAIN_PATH/log/ws.std

