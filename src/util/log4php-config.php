<?php
return array(
    'rootLogger' => array(
        'appenders' => array('daily'),
        'level' => 'info',
    ),
    'appenders' => array(
        'daily' => array(
            'class' => 'LoggerAppenderDailyFile',
            'layout' => array(
                'class' => 'LoggerLayoutPattern',
                'params' => array(
                    'conversionPattern' => '%date{H:i:s,u} %4L %5level %msg%n'
                )
            ),
            'params' => array(
                'datePattern' => 'Y-m-d',
                'file' => '/opt/entrance-guard/log/ws-%s.log',
            ),
        ),
    ),
);
