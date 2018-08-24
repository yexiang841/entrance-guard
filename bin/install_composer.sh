#!/bin/bash

cd /opt/entrance-guard/
MAIN_PATH=`pwd`
echo $MAIN_PATH

cd $MAIN_PATH/src/

curl -sS https://getcomposer.org/installer | php

php composer.phar install
