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
// 初始化一个Channel服务端
$channel_server = new Channel\Server('0.0.0.0', 18005);
// 新建websocket监听器
$ws_worker = new Worker("websocket://0.0.0.0:18015");
// 1个进程
$ws_worker->count = 1;
// 起个名字好订阅属于自己的事件
$ws_worker->name = 'messenger';
// 新增加一个属性，用来保存deviceid到connection的映射(deviceid是客户端唯一标识)
$ws_worker->deviceid_connections = array();
// 向所有客户端推送数据
function ws_broadcast($json_data)
{
    global $ws_worker;
    foreach($ws_worker->deviceid_connections as $connection)
    {
         $connection->send($json_data);
    }
    // 响应日志
    echo "[Broadcast] " . $json_data . "\n";
};
// 向deviceid推送数据
function ws_unicast($json_data,$deviceid,$signal_id)
{
    global $ws_worker;
    if(!isset($ws_worker->deviceid_connections[$deviceid]))
    {
        echo "[Warning] deviceid ' . $deviceid . ' not login\n";
        return;
    }
    // 响应日志
    echo "[Unicast] to deviceid " . $deviceid . " : " . $json_data . "\n";
    $connection = $ws_worker->deviceid_connections[$deviceid];
    // 填入signal_id等回调
    $connection->signal_id = $signal_id;
    // 返回结果
    $connection->send($json_data);
};
// 当ws_worker启动时开始订阅主动推送的通道
$ws_worker->onWorkerStart = function($worker)
{
    // 自己作为Channel客户端连接到Channel服务端
    Channel\Client::connect('127.0.0.1', 18005);
    // 订阅广播事件
    $event_broadcast = 'messenger_broadcast';
    // 收到广播事件后向当前进程内所有客户端连接发送广播数据
    Channel\Client::on($event_broadcast, function($event_data)use($worker){
        $json_data = $event_data['json_data'];
        echo "[Recieve] broadcast : " . $json_data . "\n";
        ws_broadcast($json_data);
    });
    // 订阅单播事件
    $event_unicast = 'messenger_unicast';
    // 收到单播事件后向当前进程内名为deviceid客户端连接发送单播数据
    Channel\Client::on($event_unicast, function($event_data)use($worker){
        $json_data = $event_data['json_data'];
        $deviceid = $event_data['deviceid'];
        $signal_id = $event_data['signal_id'];
        echo "[Recieve] unicast to deviceid " . $deviceid . ", data : " . $json_data . "\n";
        ws_unicast($json_data,$deviceid,$signal_id);
    });
};
// 当有websocket协议客户端连上时会触发onConnect
// 根据协议文档，应在此发送adk_signature
$ws_worker->onConnect = function($connection)
{
    global $ws_worker;
    $time = time();
    echo "\n[Event] 1 connection from websocket \n";
    $ret = '{ "command": "ack_signature", "timestamp": ' . $time . ' }';
    echo "[Return] " . $ret . "\n";
    $connection->send( $ret );
};

// 客户端主动消息转发接口
$main_server_message_url  = "http://test.17ltao.cn/mapi/index.php?r_type=2&ctl=Bbs&act=message";
// 客户端响应消息转发接口，需附上signal_id
$main_server_callback_url = "http://test.17ltao.cn/mapi/index.php?r_type=2&ctl=Bbs&act=callback";
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
    global $ws_worker;
    global $main_server_message_url;
    global $main_server_callback_url;
    // 打印完整消息
    // echo "[Testing] data : " . $data . "\n";
    // json转字典
    $dict = json_decode($data);

    if(!isset($dict->{'command'}))
    {
	// 简单信息
	echo "\n[Event] 1 message from websocket\n" ;
        echo "[Warning] not a legal event. command : unknown. data : " . $data . "\n";
        return;
    }

    // 获取command
    $command = $dict->{'command'};
    // 打印command
    // 根据协议响应不同command
    switch ($command)
    {
    case 'ack_login' : // 客户端登录
	echo "\n[Event] 1 message from websocket\n" ;
	// 打印添加前信息
	echo "[Login] data : " . $data . "\n";
        $deviceid = $dict->{'deviceid'};
        // 新连接的客户端，存储deviceid和connection的键值对
        if(!isset($connection->deviceid))
        {
            // 保存deviceid到connection的映射，这样可以方便的通过deviceid查找connection，实现针对特定deviceid推送数据
            $connection->deviceid = $deviceid;
            $ws_worker->deviceid_connections[$connection->deviceid] = $connection;
        }
        echo "[Logined] deviceid " . $connection->deviceid . " login. transmit to " . $main_server_message_url . "\n";
        list($ret_code, $ret_content) = main_server_call($main_server_message_url, $data);
        // 访问主服务器日志
        echo "[Main] server return code : " . $ret_code . " content : " . $ret_content . "\n";
        //$ret_login = '{"command":"reply_login", "errcode":0, "errmsg":"ok" }';
        // 响应日志
        echo "[Return] " . $ret_content . "\n";
        // 返回结果给客户端
        $connection->send( $ret_content );
        break;
    case 'ack_alive' :
        // 客户端保持心跳
        $deviceid = $dict->{'deviceid'};
	// 添加deviceid到原始data
        $dict->{'deviceid'} = $deviceid;
	// 字典转json
	$data = json_encode($dict);
	// 打印添加后信息
        echo "[Alive] deviceid " . $connection->deviceid . " alive\n";
        list($ret_code, $ret_content) = main_server_call($main_server_message_url, $data);
        //echo "[Main] server return code : " . $ret_code . " content : " . $ret_content . "\n";
        //$ret_content = '{ "command": "reply_alive", "errcode": 0, "errmsg": "ok" }';
        //echo "[Return] " . $ret_content . "\n";
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
        echo "[Upload] deviceid " . $deviceid . " upload\n";
        list($ret_code, $ret_content) = main_server_call($main_server_message_url, $data);
        //echo "[Main] server return code : " . $ret_code . " content : " . $ret_content . "\n";
        //$ret_content = '{ "command": "reply_alive", "errcode": 0, "errmsg": "ok" }';
        //echo "[Return] " . $ret_content . "\n";
        // 返回结果给客户端
        $connection->send( $ret_content );
        break;
    case 'iccard_add_echo' :
	echo "\n[Event] 1 message from websocket\n" ;
        // 服务端下发IC卡之后客户端回应
        $signal_id = $connection->signal_id;
        $deviceid = $connection->deviceid;
	// 添加deviceid和signal_id到原始data
        $dict->{'signal_id'} = $signal_id;
        $dict->{'deviceid'} = $deviceid;
	// 字典转json
	$data = json_encode($dict);
	// 打印添加后信息
	echo "[ICCARD_ADD_ECHO] " . $data . "\n";
        list($ret_code, $ret_content) = main_server_call($main_server_callback_url, $data);
        // 访问主服务器日志
        echo "[Main] server return code : " . $ret_code . " content : " . $ret_content . "\n";
        break;
    case 'iccard_remove_echo' :
	echo "\n[Event] 1 message from websocket\n" ;
        // 服务端删除IC卡之后客户端回应
        $signal_id = $connection->signal_id;
        $deviceid = $connection->deviceid;
	// 添加deviceid和signal_id到原始data
        $dict->{'signal_id'} = $signal_id;
        $dict->{'deviceid'} = $deviceid;
	// 字典转json
	$data = json_encode($dict);
	// 打印添加后信息
	echo "[ICCARD_REMOVE_ECHO] " . $data . "\n";
        list($ret_code, $ret_content) = main_server_call($main_server_callback_url, $data);
        // 访问主服务器日志
        echo "[Main] server return code : " . $ret_code . " content : " . $ret_content . "\n";
        break;
    case 'openlock_echo' :
	echo "\n[Event] 1 message from websocket\n" ;
        // 服务端强行开门之后客户端回应
        $signal_id = $connection->signal_id;
        $deviceid = $connection->deviceid;
	// 添加deviceid和signal_id到原始data
        $dict->{'signal_id'} = $signal_id;
        $dict->{'deviceid'} = $deviceid;
	// 字典转json
	$data = json_encode($dict);
	// 打印添加后信息
	echo "[OPENLOCK_ECHO] " . $data . "\n";
        list($ret_code, $ret_content) = main_server_call($main_server_callback_url, $data);
        // 访问主服务器日志
        echo "[Main] server return code : " . $ret_code . " content : " . $ret_content . "\n";
        break;
    default :
	echo "\n[Event] 1 message from websocket\n" ;
        // 请求日志
        echo "[Data] " . $data . "\n";
        //$ret_openlock_callback = '{"deviceid":"' . $deviceid . '","status":0,"signal_id":"' . $signal_id . '"}';
        list($ret_code, $ret_content) = main_server_call($main_server_message_url, $data);
        echo "[Main] server return code : " . $ret_code . " content : " . $ret_content . "\n";
        // 响应日志
        echo "[Return] " . $ret_content . "\n";
        // 返回结果
        $connection->send( $ret_content );
        break;
    }
};
// 当有客户端连接断开时
$ws_worker->onClose = function($connection)
{
    global $ws_worker;
    if(isset($connection->deviceid))
    {
        echo "\n[Event] close 1 connection from websocket. deviceid : " . $connection->deviceid . "\n";
        // 连接断开时删除映射
        unset($ws_worker->deviceid_connections[$connection->deviceid]);
    }else{
        echo "\n[Event] close 1 connection from websocket. deviceid : unknown\n";
    }
};
// 微服务指令接口
$http_worker = new Worker("http://0.0.0.0:18010");
// 1个进程
$http_worker->count = 1;
// 起个名字好订阅属于自己的事件
$http_worker->name = 'commander';
// 当http_worker启动时开始连接消息推送服务器
$http_worker->onWorkerStart = function()
{
    Channel\Client::connect('127.0.0.1', 18005);
};
// 当有网页客户端发来消息时触发onMessage
$http_worker->onMessage = function($connection, $data)
{
    echo "\n[Event] 1 message from http\n";
    // var_dump($_GET, $_POST);
    $connection->send('ok');
    
    // 按约定必须要有cast
    if(!isset($_GET['cast']))
    {
        echo "[Warning] not a legal event. cast : unknown\n";
        return;
    }
    $cast = $_GET['cast'];
    // 按约定必须要有command
    if(!isset($_GET['data']))
    {
        echo "[Warning] not a legal event. data : unknown\n";
        return;
    }
    $json_data = $_GET['data'];
    $event_name = 'unknown';
    $deviceid = 'unknown';
    switch ($cast)
    {
    case 'broadcast' : // 广播
        $event_name = 'messenger_broadcast';
        http_broadcast($json_data);
        echo "[Publish] broadcast : " . $json_data . "\n";
        $event_name = 'messenger_broadcast';
        Channel\Client::publish($event_name, array(
           'json_data'  => $json_data
        ));
        break;
    case 'unicast' : // 单播
        $event_name = 'messenger_unicast';
        if(!isset($_GET['deviceid']))
        {
            echo "[Warning] not a legal unicast. deviceid : unknown\n";
            return;
        }
        // 只有unicast会设置deviceid和signal_id
        $deviceid = $_GET['deviceid'];
        $signal_id = $_GET['signal_id'];
        echo "[Publish] unicast to deviceid " . $deviceid . ", data : " . $json_data . " (signal_id:" . $signal_id . ")\n";
        $event_name = 'messenger_unicast';
        Channel\Client::publish($event_name, array(
           'deviceid'  => $deviceid,
           'json_data'  => $json_data,
           'signal_id'  => $signal_id
        ));
        break;
    default :
        echo "[Warning] not a legal event. cast : " . $cast . ", data : " . $json_data . ", deviceid : " . $deviceid . "\n";
        return;
    }
};
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
