#!/bin/bash

cd /opt/entrance-guard/
MAIN_PATH=`pwd`
echo $MAIN_PATH
php $MAIN_PATH/src/workerman-server.php start

