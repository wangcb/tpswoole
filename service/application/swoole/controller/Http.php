<?php
namespace app\swoole\controller;
use server\Response;
use server\Request;
use server\Parser;
/**
 * @author wangcb	
 * @date: 2017年6月27日 上午10:04:11
 * 
 */
class Http{
    const OPCODE_CONTINUATION_FRAME = 0x0;
    const OPCODE_TEXT_FRAME         = 0x1;
    const OPCODE_BINARY_FRAME       = 0x2;
    const OPCODE_CONNECTION_CLOSE   = 0x8;
    const OPCODE_PING               = 0x9;
    const OPCODE_PONG               = 0xa;
    
    const CLOSE_NORMAL              = 1000;
    const CLOSE_GOING_AWAY          = 1001;
    const CLOSE_PROTOCOL_ERROR      = 1002;
    const CLOSE_DATA_ERROR          = 1003;
    const CLOSE_STATUS_ERROR        = 1005;
    const CLOSE_ABNORMAL            = 1006;
    const CLOSE_MESSAGE_ERROR       = 1007;
    const CLOSE_POLICY_ERROR        = 1008;
    const CLOSE_MESSAGE_TOO_BIG     = 1009;
    const CLOSE_EXTENSION_MISSING   = 1010;
    const CLOSE_SERVER_ERROR        = 1011;
    const CLOSE_TLS                 = 1015;
    
    public      $swoole;
    protected   $host   = '0.0.0.0';
    protected   $port   = 9503;
    protected   $option = [
                    'worker_num' => 1,
                    'daemonize' => 0,
                    'backlog' => 128,
                ];
    
    
    public $requests = array();//保存请求头信息
    public $buffer_header  = array();
    const HTTP_EOF = "\r\n\r\n";
    const HTTP_HEAD_MAXLEN = 8192; //http头最大长度不得超过2k

    const ST_FINISH = 1; //完成，进入处理流程
    const ST_WAIT   = 2; //等待数据
    const ST_ERROR  = 3; //错误，丢弃此包
    
    public $parser;
    
    
    protected $sessions     = array();
    protected $keepalive    = true;
    /**
     * 等待数据
     * @var array
     */
    public $frame_list = array();
    public $connections = array();
    protected $wait_requests = array();
    protected $fd_session_map = array();
    
    protected $users;
    
    const GUID              = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    
    
    function __construct(){
        $this->swoole = new \swoole_server($this->host, $this->port);
        
        // 设置参数
        if (!empty($this->option)) {
            $this->swoole->set($this->option);
        }
        
        //监听数据接收事件
        $this->swoole->on('receive', array($this,'onReceive'));
        
        //此事件在worker进程/task进程启动时发生。这里创建的对象可以在进程生命周期内使用
        //$this->swoole->on('workerStart',array($this,'onWorkerStart'));
        
        //监听连接关闭事件
        $this->swoole->on('close', array($this,'onClose'));
        
        $this->parser = new Parser();
    }
    
    
    /**
     * 监听数据接收事件
     * @author wangcb
     * @param unknown $serv
     * @param unknown $fd
     * @param unknown $from_id
     * @param unknown $data 请求头信息和内容
     */
    function onReceive($serv, $fd, $from_id, $data) {
        //file_put_contents('log.txt', $data."\n\r",FILE_APPEND);
        $ret    =   $this->checkData($fd, $data);
        switch($ret){
            //错误的请求
            case self::ST_ERROR;
                $this->server->close($fd);
                return;
            //请求不完整，继续等待
            case self::ST_WAIT:
                return;
            default:
                break;
        }
        $request = $this->requests[$fd];
        $request->fd = $fd;
        
        /**
         * Socket连接信息
         */
        $info = $serv->connection_info($fd);
        $request->server['SWOOLE_CONNECTION_INFO'] = $info;
        $request->remote_ip = $info['remote_ip'];
        $request->remote_port = $info['remote_port'];
        /**
         * Server变量
        */
        $request->server['REQUEST_URI'] = $request->meta['uri'];
        $request->server['REMOTE_ADDR'] = $request->remote_ip;
        $request->server['REMOTE_PORT'] = $request->remote_port;
        $request->server['REQUEST_METHOD'] = $request->meta['method'];
        $request->server['REQUEST_TIME'] = $request->time;
        $request->server['SERVER_PROTOCOL'] = $request->meta['protocol'];
        if (!empty($request->meta['query']))
        {
            $_SERVER['QUERY_STRING'] = $request->meta['query'];
        }
        $request->setGlobal();
        $this->parseRequest($request);
        
        $response = $this->onRequest($request);
        //var_dump($request,$response);
        
        if ($response && $response instanceof Response){
            //发送response
            $this->response($request, $response);
        }
        /* var_dump($fd,$data);
        $response = new Response();
        $response->setHeader('Access-Control-Allow-Origin', 'http://v.123.com.cn');
        $response->setHeader('KeepAlive', 'on');
        $response->body = '<h1>hello world!</h1>';
        $serv->send($fd, $response->getHeader().$response->body); */
    }
    
    /**
     * Request come
     * @param Swoole\Request $request
     * @return Swoole\Response
     */
    function onRequest($request){
        return $request->isWebSocket() ? $this->onWebSocketRequest($request) : $this->onHttpRequest($request);
    }
    
    /**
     * 长轮询
     * @author wangcb
     * @param unknown $request
     */
    function onHttpRequest($request){
        if (empty($request->post['session_id'])){
            $response   = new Response();
            $response->setHeader('Access-Control-Allow-Origin', 'http://v.123.com.cn');
            $response->setHeader('KeepAlive', 'on');
            $session    = $this->createNewSession();
            $response->body = json_encode(array('success' => 1,'session_id'=>$session->id));
            return $response;
        }
        $session_id = $request->post['session_id'];
        $session    = $this->getSession($session_id);
        if ($request->post['type'] == 'pub') {
            $response       = new Response();
            $response->setHeader('Access-Control-Allow-Origin', 'http://v.123.com.cn');
            $response->body = json_encode(array('success' => 1));
            $this->response($request, $response);
            $this->onXhrMessage($session_id, $request->post);
        }
        if($request->post['type'] == 'sub'){
            $this->wait_requests[$session_id]   = $request;
            $this->fd_session_map[$request->fd] = $session_id;
            //var_dump($session);
            //var_dump($session->getMessageCount());
            if ($session->getMessageCount() > 0){
                $this->sendMessage($this->swoole, $session);
            }
        }
    }

    /**
     * websocket请求
     * @author wangcb
     */
    function onWebSocketRequest($request){
        $response   = new Response();
        $this->doHandShake($request, $response);
        return $response;
    }
    
    public function doHandShake($request, Response $response){
        /* if($request->header['origin'] != 'http://v.123.com.cn'){
            return false;
        } */
        if (!isset($request->header['Sec-WebSocket-Key'])){
            return false;
        }
        
        $key = $request->header['Sec-WebSocket-Key'];
        if (0 === preg_match('#^[+/0-9A-Za-z]{21}[AQgw]==$#', $key) || 16 !== strlen(base64_decode($key))){
            return false;
        }
        
        $response->setHttpStatus(101);
        $response->addHeaders(array(
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Accept' => base64_encode(sha1($key . static::GUID, true)),
            'Sec-WebSocket-Version' => 13,
        ));
        return true;
    }
    
    //长连接
    function onXhrMessage($client_id, $post){
        $msg = json_decode($post['message'], true);
    
        $func = 'cmd_'.$msg['cmd'];
        if (method_exists($this, $func))
        {
            $this->$func($client_id, $msg);
        }
    }
    
    function cmd_message($client_id, $msg){
        $resMsg = $msg;
        $resMsg['action'] = 'message';
        
        $this->sendJson($client_id, $resMsg);
    }
    
    function cmd_login($client_id, $msg){
        $info['name']   = $msg['name'];
        $info['avatar'] = $msg['avatar'];
    
        //回复给登录用户
        $resMsg = array(
            'cmd'       => 'login',
            'fd'        => $client_id,
            'name'      => $info['name'],
            'avatar'    => $info['avatar'],
        );
    
        //把会话存起来
        $this->users[$client_id] = $resMsg;
    
        $this->sendJson($client_id, $resMsg);
    }
    
    function sendJson($client_id, $array){
        $msg = json_encode($array);
        if ($this->send($client_id, $msg) === false)
        {
            $this->swoole->close($client_id);
        }
    }
    
    function send($session_id, $data, $opcode = OPCODE_TEXT_FRAME, $end = true){
        if (!$this->isCometClient($session_id))
        {
            return parent::send($session_id, $data, $opcode, $end);
        }else{
            $session = $this->getSession($session_id);
            if (!$session){
                return false;
            }else{
                $session->pushMessage($data);
            }
        }
        //有等待的Request可以直接发送数据
        if (isset($this->wait_requests[$session_id])){
            return $this->sendMessage($session);
        }
    }
    
    public function wssend($client_id, $message, $opcode = self::OPCODE_TEXT_FRAME, $end = true)
    {
        if ((self::OPCODE_TEXT_FRAME  === $opcode or self::OPCODE_CONTINUATION_FRAME === $opcode) and false === (bool) preg_match('//u', $message))
        {
            return false;
        }
        else
        {
            $out = $this->newFrame($message, $opcode, $end);
            return $this->server->send($client_id, $out);
        }
    }
    
    
    function sendMessage($session){
        $request        = $this->wait_requests[$session->id];
        $response       = new Response();
        $response->setHeader('Access-Control-Allow-Origin', 'http://v.123.com.cn');
        $response->body = json_encode(array('success' => 1, 'data' => $session->popMessage()));
        unset($this->wait_requests[$session->id]);
        $out = $response->getHeader().$response->body;
        $bool = $this->swoole->send($request->fd,$out);
    }
    function isCometClient($client_id)
    {
        return strlen($client_id) === 32;
    }
    function response($request, Response $response)
    {
        if (!isset($response->head['Date']))
        {
            $response->head['Date'] = gmdate("D, d M Y H:i:s T");
        }
        if (!isset($response->head['Connection']))
        {
            //keepalive
            if ($this->keepalive and (isset($request->header['Connection']) and strtolower($request->header['Connection']) == 'keep-alive'))
            {
                $response->head['KeepAlive'] = 'on';
                $response->head['Connection'] = 'keep-alive';
            }
            else
            {
                $response->head['KeepAlive'] = 'off';
                $response->head['Connection'] = 'close';
            }
        }
        $out = $response->getHeader().$response->body;
        $ret = $this->swoole->send($request->fd, $out);
        $this->afterResponse($request, $response);
        return $ret;
    }
    
    function afterResponse($request, $response)
    {
        if (!$this->keepalive or $response->head['Connection'] == 'close')
        {
            $this->server->close($request->fd);
        }
        $request->unsetGlobal();
        //清空request缓存区
        unset($this->requests[$request->fd]);
        unset($request);
        unset($response);
    }
    
    function createNewSession(){
        $session = new CometSession();
        $this->sessions[$session->id] = $session;
        return $session;
    }
    
    function getSession($session_id){
        if(!isset($this->sessions[$session_id])){
            return false;
        }
        return $this->sessions[$session_id];
    }
    
    /**
     * @param $client_id
     * @param $http_data
     * @return bool|Swoole\Request
     */
    function checkHeader($client_id, $http_data)
    {
        //新的连接
        if (!isset($this->requests[$client_id]))
        {
            if (!empty($this->buffer_header[$client_id]))
            {
                $http_data = $this->buffer_header[$client_id].$http_data;
            }
            //HTTP结束符
            $ret = strpos($http_data, self::HTTP_EOF);
            //没有找到EOF，继续等待数据
            if ($ret === false)
            {
                return false;
            }
            else
            {
                $this->buffer_header[$client_id] = '';
                $request = new Request();
                //GET没有body
                list($header, $request->body) = explode(self::HTTP_EOF, $http_data, 2);
                $request->header = $this->parser->parseHeader($header);
                //使用head[0]保存额外的信息
                $request->meta = $request->header[0];
                unset($request->header[0]);
                //保存请求
                $this->requests[$client_id] = $request;
                //解析失败
                if ($request->header == false)
                {
                    return false;
                }
            }
        }
        //POST请求需要合并数据
        else
        {
            $request = $this->requests[$client_id];
            $request->body .= $http_data;
        }
        return $request;
    }
    
    /**
     * @param Swoole\Request $request
     * @return int
     */
    function checkPost(Request $request)
    {
        if (isset($request->header['Content-Length']))
        {
            //超过最大尺寸
            if (intval($request->header['Content-Length']) > 2000000)
            {
                return self::ST_ERROR;
            }
            //不完整，继续等待数据
            if (intval($request->header['Content-Length']) > strlen($request->body))
            {
                return self::ST_WAIT;
            }
            //长度正确
            else
            {
                return self::ST_FINISH;
            }
        }
        //POST请求没有Content-Length，丢弃此请求
        return self::ST_ERROR;
    }
    
    function checkData($client_id, $http_data)
    {
        if (isset($this->buffer_header[$client_id]))
        {
            $http_data = $this->buffer_header[$client_id].$http_data;
        }
        //检测头
        $request = $this->checkHeader($client_id, $http_data);
        //错误的http头
        if ($request === false)
        {
            $this->buffer_header[$client_id] = $http_data;
            //超过最大HTTP头限制了
            if (strlen($http_data) > self::HTTP_HEAD_MAXLEN)
            {
                return self::ST_ERROR;
            }
            //等待
            else
            {
                return self::ST_WAIT;
            }
        }
        //POST请求需要检测body是否完整
        if ($request->meta['method'] == 'POST')
        {
            return $this->checkPost($request);
        }
        //GET请求直接进入处理流程
        else
        {
            return self::ST_FINISH;
        }
    }
    
    /**
     * 解析请求
     * @param $request Swoole\Request
     * @return null
     */
    function parseRequest($request)
    {
        $url_info = parse_url($request->meta['uri']);
        $request->time = time();
        $request->meta['path'] = $url_info['path'];
        if (isset($url_info['fragment'])) $request->meta['fragment'] = $url_info['fragment'];
        if (isset($url_info['query']))
        {
            parse_str($url_info['query'], $request->get);
        }
        //POST请求,有http body
        if ($request->meta['method'] === 'POST')
        {
            $this->parser->parseBody($request);
        }
        //解析Cookies
        if (!empty($request->header['Cookie']))
        {
            $this->parser->parseCookie($request);
        }
    }
    
    public function newFrame($message, $opcode = self::OPCODE_TEXT_FRAME, $end = true)
    {
        $fin = true === $end ? 0x1 : 0x0;
        $rsv1 = 0x0;
        $rsv2 = 0x0;
        $rsv3 = 0x0;
        $length = strlen($message);
        $out = chr(($fin << 7) | ($rsv1 << 6) | ($rsv2 << 5) | ($rsv3 << 4) | $opcode);
    
        if (0xffff < $length)
        {
            $out .= chr(0x7f) . pack('NN', 0, $length);
        }
        elseif (0x7d < $length)
        {
            $out .= chr(0x7e) . pack('n', $length);
        }
        else
        {
            $out .= chr($length);
        }
        $out .= $message;
        return $out;
    }
    
    /**
     * 监听连接关闭事件
     * @author wangcb
     * @param unknown $serv
     * @param unknown $fd
     */
    function onClose($serv, $fd) {
        echo "Client: {$fd} Close.\n";
    }
    
    /**
     * 启动服务
     * @author wangcb
     */
    public function start() {
        $this->swoole->start();
    }
}

class CometSession extends \SplQueue
{
    public $id;
    /**
     * @var \SplQueue
     */
    protected $msg_queue;

    function __construct()
    {
        $this->id = md5(uniqid('', true));
    }

    function getMessageCount()
    {
        return count($this);
    }

    function pushMessage($msg)
    {
        return $this->enqueue($msg);
    }

    function popMessage()
    {
        return $this->dequeue();
    }
}