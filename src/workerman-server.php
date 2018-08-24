<?php
/* 
 * 运行前先检查环境
 * curl -Ss http://www.workerman.net/check.php | php
 */
// 引入Workerman，文件来自(https://github.com/walkor/Workerman.git)
use Workerman\Worker;
// 引入启动文件，请按照真实环境修改此路径
require_once '/opt/Workerman/Autoloader.php';
// 引入进程通信组件，请按照真实环境修改此路径，文件来自(https://github.com/walkor/Channel.git)
require_once '/opt/Channel/src/Server.php';
require_once '/opt/Channel/src/Client.php';
// 引入composer的自动加载，以便加载log4php等第三方日志工具
require 'vendor/autoload.php';

// 正式环境端口
$port_prd = array(
    "channel"=>18005,
    "websocket"=>18015,
    "http"=>18010
);
// 测试环境端口
$port_dev = array(
    "channel"=>28005,
    "websocket"=>28015,
    "http"=>28010
);

// 客户端主动消息转发接口
$main_server_message_url  = "http://test.17ltao.cn/mapi/index.php?r_type=2&ctl=Bbs&act=message";
// 客户端响应消息转发接口，需附上signal_id
$main_server_callback_url = "http://test.17ltao.cn/mapi/index.php?r_type=2&ctl=Bbs&act=callback";

\Logger::configure(__DIR__ . '/util/log4php-config.php');
$log = \Logger::getLogger('daily');

echo "[init] starting Logger\n";
$log->info( "[init] Logger started" );

// 初始化一个Channel服务端
$channel_server = new Channel\Server('0.0.0.0', $port_prd["channel"]);
// 新建websocket监听器
$ws_worker = new Worker("websocket://0.0.0.0:".$port_prd["websocket"]);
// 1个进程
$ws_worker->count = 1;
// 新增加一个属性，用来保存deviceid到connection的映射(deviceid是客户端唯一标识)
$ws_worker->deviceid_connections = array();
// 向所有客户端推送数据
function ws_broadcast($json_data)
{
    global $ws_worker,$log;
    foreach($ws_worker->deviceid_connections as $connection)
    {
         $connection->send($json_data);
    }
    // 响应日志
    $log->info( "[broadcast] " . $json_data );
};
// 向deviceid推送数据
function ws_unicast($json_data,$deviceid,$signal_id)
{
    global $ws_worker,$log;
    if(!isset($ws_worker->deviceid_connections[$deviceid]))
    {
        $log->warn( "[unauthorized] deviceid ' . $deviceid . ' not login" );
        return;
    }
    // 响应日志
    $log->info( "[unicast] to deviceid " . $deviceid . " : " . $json_data );
    $connection = $ws_worker->deviceid_connections[$deviceid];
    // 填入signal_id等回调
    $connection->signal_id = $signal_id;
    // 返回结果
    $connection->send($json_data);
};
// 当ws_worker启动时开始订阅主动推送的通道
$ws_worker->onWorkerStart = function($worker)
{
    global $port_prd;
    // 自己作为Channel客户端连接到Channel服务端
    Channel\Client::connect('127.0.0.1', $port_prd["channel"]);
    // 订阅广播事件
    $event_broadcast = 'messenger_broadcast';
    // 收到广播事件后向当前进程内所有客户端连接发送广播数据
    Channel\Client::on($event_broadcast, function($event_data)use($worker){
        $json_data = $event_data['json_data'];
        ws_broadcast($json_data);
    });
    // 订阅单播事件
    $event_unicast = 'messenger_unicast';
    // 收到单播事件后向当前进程内名为deviceid客户端连接发送单播数据
    Channel\Client::on($event_unicast, function($event_data)use($worker){
        $json_data = $event_data['json_data'];
        $deviceid = $event_data['deviceid'];
        $signal_id = $event_data['signal_id'];
        ws_unicast($json_data,$deviceid,$signal_id);
    });
};
// 当有websocket协议客户端连上时会触发onConnect
// 根据协议文档，应在此发送adk_signature
$ws_worker->onConnect = function($connection)
{
    global $ws_worker,$log;
    $time = time();
    $log->info( "[open] connection from websocket." );
    $ret = '{ "command": "ack_signature", "timestamp": ' . $time . ' }';
    $log->info( "[return] " . $ret );
    $connection->send( $ret );
};

/**
 * PHP发送Json对象数据
 * 
 * @param $url 请求url
 * @param $jsonStr 发送的json字符串
 * @return array
 */
function main_server_call($url,$jsonStr)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonStr);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json; charset=utf-8',
            'Content-Length: ' . strlen($jsonStr)
        )
    );
    $ret_content = curl_exec($ch);
    $ret_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array($ret_code, $ret_content);
};
// 当有websocket协议客户端发来消息时onMessage
$ws_worker->onMessage = function($connection, $data)
{
    global $ws_worker,$log;
    global $main_server_message_url;
    global $main_server_callback_url;
    // 打印完整消息
    $log->debug( "[data] : " . $data );
    // json转字典
    $dict = json_decode($data);

    if(!isset($dict->{'command'}))
    {
	// 简单信息
	$log->info( "[MESSAGE] from websocket" );
        $log->warn( "[illegal] event. command : unknown. data : " . $data );
        return;
    }

    // 获取command
    $command = $dict->{'command'};
    // 打印command
    // 根据协议响应不同command
    switch ($command)
    {
    case 'ack_login' : // 客户端登录
	$log->info( "[MESSAGE] from websocket" );
	// 打印添加前信息
	$log->info( "[data] : " . $data );
        $deviceid = $dict->{'deviceid'};
        // 新连接的客户端，存储deviceid和connection的键值对
        if(!isset($connection->deviceid))
        {
            // 保存deviceid到connection的映射，这样可以方便的通过deviceid查找connection，实现针对特定deviceid推送数据
            $connection->deviceid = $deviceid;
            $ws_worker->deviceid_connections[$connection->deviceid] = $connection;
        }
        $log->info( "[login] connection from websocket. deviceid : " . $connection->deviceid );
        list($ret_code, $ret_content) = main_server_call($main_server_message_url, $data);
        // 访问主服务器日志
        $log->info( "[main] server return code : " . $ret_code . " content : " . $ret_content );
        //$ret_login = '{"command":"reply_login", "errcode":0, "errmsg":"ok" }';
        // 响应日志
        $log->info( "[return] " . $ret_content );
        // 返回结果给客户端
        $connection->send( $ret_content );
        break;
    case 'ack_alive' :
        // 客户端保持心跳
        $deviceid = $connection->deviceid;
	// 添加deviceid到原始data
        $dict->{'deviceid'} = $deviceid;
	// 字典转json
	$data = json_encode($dict);
	// 打印添加后信息
        $log->info( "[alive] deviceid : " . $deviceid );
        list($ret_code, $ret_content) = main_server_call($main_server_message_url, $data);
        $log->debug( "[main] server return code : " . $ret_code . " content : " . $ret_content );
        $log->debug( "[return] " . $ret_content );
        // 返回结果给客户端
        $connection->send( $ret_content );
        break;
    case 'ack_upload' :
        // 客户端上报门禁卡开锁
        $deviceid = $connection->deviceid;
	// 添加deviceid到原始data
        $dict->{'deviceid'} = $deviceid;
	// 字典转json
	$data = json_encode($dict);
	// 打印添加后信息
        $log->info( "[upload] deviceid : " . $deviceid );
        list($ret_code, $ret_content) = main_server_call($main_server_message_url, $data);
        $log->debug( "[main] server return code : " . $ret_code . " content : " . $ret_content );
        $log->debug( "[return] " . $ret_content );
        // 返回结果给客户端
        $connection->send( $ret_content );
        break;
    case 'iccard_add_echo' :
	$log->info( "[MESSAGE] from websocket" );
        // 服务端下发IC卡之后客户端回应
        $signal_id = $connection->signal_id;
        $deviceid = $connection->deviceid;
	// 添加deviceid和signal_id到原始data
        $dict->{'signal_id'} = $signal_id;
        $dict->{'deviceid'} = $deviceid;
	// 字典转json
	$data = json_encode($dict);
	// 打印添加后信息
	$log->info( "[iccard_add_echo] " . $data );
        list($ret_code, $ret_content) = main_server_call($main_server_callback_url, $data);
        // 访问主服务器日志
        $log->info( "[Main] server return code : " . $ret_code . " content : " . $ret_content );
        break;
    case 'iccard_remove_echo' :
	$log->info( "[MESSAGE] from websocket" );
        // 服务端删除IC卡之后客户端回应
        $signal_id = $connection->signal_id;
        $deviceid = $connection->deviceid;
	// 添加deviceid和signal_id到原始data
        $dict->{'signal_id'} = $signal_id;
        $dict->{'deviceid'} = $deviceid;
	// 字典转json
	$data = json_encode($dict);
	// 打印添加后信息
	$log->info( "[iccard_remove_echo] " . $data );
        list($ret_code, $ret_content) = main_server_call($main_server_callback_url, $data);
        // 访问主服务器日志
        $log->info( "[main] server return code : " . $ret_code . " content : " . $ret_content );
        break;
    case 'openlock_echo' :
	$log->info( "[MESSAGE] from websocket" );
        // 服务端强行开门之后客户端回应
        $signal_id = $connection->signal_id;
        $deviceid = $connection->deviceid;
	// 添加deviceid和signal_id到原始data
        $dict->{'signal_id'} = $signal_id;
        $dict->{'deviceid'} = $deviceid;
	// 字典转json
	$data = json_encode($dict);
	// 打印添加后信息
	$log->info( "[openlock_echo] " . $data );
        list($ret_code, $ret_content) = main_server_call($main_server_callback_url, $data);
        // 访问主服务器日志
        $log->info( "[main] server return code : " . $ret_code . " content : " . $ret_content );
        break;
    default :
	$log->info( "[MESSAGE] from websocket" );
        // 请求日志
        $log->info( "[data] : " . $data );
        //$ret_openlock_callback = '{"deviceid":"' . $deviceid . '","status":0,"signal_id":"' . $signal_id . '"}';
        list($ret_code, $ret_content) = main_server_call($main_server_message_url, $data);
        $log->info( "[main] server return code : " . $ret_code . " content : " . $ret_content );
        // 响应日志
        $log->info( "[return] " . $ret_content );
        // 返回结果
        $connection->send( $ret_content );
        break;
    }
};
// 当有客户端连接断开时
$ws_worker->onClose = function($connection)
{
    global $ws_worker,$log;
    if(isset($connection->deviceid))
    {
        $log->info( "[close] connection from websocket. deviceid : " . $connection->deviceid );
        // 连接断开时删除映射
        unset($ws_worker->deviceid_connections[$connection->deviceid]);
    }else{
        $log->info( "[close] connection from websocket. deviceid : unknown" );
    }
};
// 微服务指令接口
$http_worker = new Worker("http://0.0.0.0:".$port_prd["http"]);
// 1个进程
$http_worker->count = 1;
// 当http_worker启动时开始连接消息推送服务器
$http_worker->onWorkerStart = function()
{
    global $port_prd;
    Channel\Client::connect('127.0.0.1', $port_prd["channel"]);
};
// 当有网页客户端发来消息时触发onMessage
$http_worker->onMessage = function($connection, $data)
{
    global $log;
    $log->info( "[MESSAGE] from http" );
    // var_dump($_GET, $_POST);
    $connection->send('ok');
    
    // 按约定必须要有cast
    if(!isset($_GET['cast']))
    {
        $log->warn( "[illegal] event. cast : unknown" );
        return;
    }
    $cast = $_GET['cast'];
    // 按约定必须要有command
    if(!isset($_GET['data']))
    {
        $log->warn( "[illegal] event. data : unknown" );
        return;
    }
    $json_data = $_GET['data'];
    $event_name = 'unknown';
    $deviceid = 'unknown';
    $signal_id = 'unknown';
    switch ($cast)
    {
    case 'broadcast' : // 广播
        $event_name = 'messenger_broadcast';
        http_broadcast($json_data);
        $log->info( "[publish] broadcast : " . $json_data );
        $event_name = 'messenger_broadcast';
        Channel\Client::publish($event_name, array(
           'json_data'  => $json_data
        ));
        break;
    case 'unicast' : // 单播
        if(!isset($_GET['deviceid']))
        {
            $log->warn( "[illegal] unicast. deviceid : unknown" );
            return;
        }
        if(!isset($_GET['signal_id']))
        {
            $log->warn( "[illegal] unicast. signal_id : unknown" );
            return;
        }
        $event_name = 'messenger_unicast';
        // 只有unicast会设置deviceid和signal_id
        $deviceid = $_GET['deviceid'];
        $signal_id = $_GET['signal_id'];
        $log->info( "[publish] unicast to deviceid " . $deviceid . ", data : " . $json_data . " (signal_id:" . $signal_id . ")" );
        $event_name = 'messenger_unicast';
        Channel\Client::publish($event_name, array(
           'deviceid'  => $deviceid,
           'json_data'  => $json_data,
           'signal_id'  => $signal_id
        ));
        break;
    default :
        $log->warn( "[illegal] event. cast : " . $cast . ", data : " . $json_data . ", deviceid : " . $deviceid . ", signal_id : " . $signal_id );
        return;
    }
};
echo "[init] starting Worker\n";
$log->info( "[init] Worker started" );
// 运行worker
Worker::runAll();


/* 
 *【测试】
 * 本机测试ip = 127.0.0.1
 * 服务器测试ip = 120.79.69.80
 */

/* 客户端打开js控制台输入
 * ws = new WebSocket("ws://ip:18015");
 * 可建立本地连接
 * ws.onmessage = function(e) { alert("server return : " + e.data); };
 * 可alert输出服务器反馈结果
 * ws.send('{ "command": "ack_login", "signature": "c3b05643b7b20c5d2e62eca2aa887704a5aa79f1", "nonce": "2975254920", "timestamp": "1527688645", "community_guid": "5011", "devicetype": "gate", "deviceid": "00010001", "password": "123456", "msgid": "5edef5e8-972e-487c-a659-80fd32a7e815", "software": 2017101717, "reboot_login": false }');
 * 可模拟一个设备号为0001的设备登录并注册在广播列表中
 */

/* postman模拟get请求：
 * http://ip:18010?cast=unicast&deviceid=00050005&signal_id=201805302249586604&data={"command":"openlock","msgid":"201805302249586604"}
 * 可模拟一个强制开门事件
 */
