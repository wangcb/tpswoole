<?php

namespace app\swoole\controller;

use server\SwooleSocket;
use server\SwooleTable;
use server\Response;
use server\Request;
class Socket extends SwooleSocket {
    protected $sessions     = array();
    protected $keepalive    = true;
    protected $request_timeout = 50;
    /**
     * 等待数据
     * @var array
     */
    protected $wait_requests = array();
    
    protected $fd_session_map = array();
    
    protected $object;
    protected $port = 9502;
    protected $option = [
        'worker_num' => 1,
        'daemonize' => 0,
        'backlog' => 128,
    ];
    
    function init(){
        cache('users',null);
        cache('room',null);
        cache('admin_fd_map',null);
        return new SwooleTable();
    }
    function onWorkerStart($serv, $worker_id){
        if ($worker_id < $serv->setting['worker_num']){
            $serv->tick(1000, array($this, 'onTimer'));
        }
    }
    
    function onHandShake($request, $response){
        if (isset($request->get['a'])){
            $action = $request->get['a'];
            $this->object = controller('index/'.$action,'controller');
            if ($this->object && method_exists($this->object, 'onHandShake')){
                $this->object->onHandShake($this->swoole, $request, $response);
            }
        }
    }

    function onMessage($serv, $frame) {
        if ($this->object && method_exists($this->object, 'onMessage')){
            $this->object->onMessage($serv, $frame);
        }
    }
    
    function onClose($serv, $fd) {
        if ($this->object && method_exists($this->object, 'onClose')){
            $this->object->onClose($serv, $fd);
        }
    }
    
    
    //http 长连接
    function onRequest($request, $response){
        var_dump($request->post, $response);
        if (empty($request->post['session_id'])){
            $response->header('Access-Control-Allow-Origin', 'http://v.123.com.cn');
            $response->header('KeepAlive', 'on');
            $session        = $this->createNewSession();
            $response->end(json_encode(array('success' => 1,'session_id'=>$session->id)));
            return false;
        }
        var_dump($request);
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
    
    function response($request, Response $response)
    {
        if (!isset($response->head['date']))
        {
            $response->head['Date'] = gmdate("D, d M Y H:i:s T");
        }
        if (!isset($response->head['connection']))
        {
            //keepalive
            if ($this->keepalive and (isset($request->header['connection']) and strtolower($request->header['connection']) == 'keep-alive'))
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
        return $ret;
    }
    
    /**
     * 发送JSON数据
     * @param $client_id
     * @param $array
     */
    function sendJson($client_id, $array){
        $msg = json_encode($array);
        if ($this->send($client_id, $msg) === false)
        {
            $this->swoole->close($client_id);
        }
    }
    
    function send($session_id, $data){
        $session = $this->getSession($session_id);
        if (!$session){
            return false;
        }else{
            $session->pushMessage($data);
        }
        //有等待的Request可以直接发送数据
        if (isset($this->wait_requests[$session_id])){
            return $this->sendMessage($session);
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
    
    //长连接
    function onXhrMessage($client_id, $post){
        $msg = json_decode($post['message'], true);
    
        $func = 'cmd_'.$msg['cmd'];
        if (method_exists($this, $func))
        {
            $this->$func($client_id, $msg);
        }
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
    
    
    /**
     * 定时器，检查某些连接是否已超过最大时间
     * @param $timerId
     */
    function onTimer($timerId)
    {
        $now = time();
        //echo "timer $interval\n";
        foreach ($this->wait_requests as $id => $request)
        {
            if ($request->server['request_time'] < $now - $this->request_timeout)
            {
                $response = new Response();
                $response->setHeader('Access-Control-Allow-Origin', 'http://v.123.com.cn');
                $response->body = json_encode(array('success' => 0, 'text' => 'timeout'));
                $this->response($request, $response);
                unset($this->wait_requests[$id]);
            }
        }
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