<?php
namespace app\swoole\controller;
use server\CometServer;
use app\swoole\model\User;
use think\Cache;
/**
 * @author wangcb	
 * @date: 2017年6月28日 下午10:30:49
 * 
 */
class Server extends CometServer{
    
    //保存登录信息
    protected $user;
    protected $admin_fd;//boss后台fd
    /**
     * 接收客户端发送的消息
     * @see \server\WebSocket::onMessage()
     */
    function onMessage($client_id, $post){
        $msg        = json_decode($post['message'], true);
        if(isset($msg['l'])){
            $Users      = new User();
            $this->user = $Users->login($msg['l']);
            if($this->user){
                $func = 'cmd_'.$msg['cmd'];
                if (method_exists($this, $func)){
                    $this->$func($client_id, $msg);
                }else{
                    $this->close($client_id);
                }
            }else{
                $this->close($client_id);
            }
        }elseif (isset($msg['a']) && $msg['a'] == 'admin'){
            $func = 'cmd_'.$msg['cmd'];
            if (method_exists($this, $func)){
                $this->admin_fd[$client_id] = $msg['rid'];
                $this->$func($client_id, $msg);
            }
        }else{
            $this->close($client_id);
        }
    }
    /**
     * 下线回调函数
     * @see \server\WebSocket::onExit()
     */
    function onExit($client_id){
        //后台的连接id
        if(isset($this->admin_fd[$client_id])){
            unset($this->admin_fd[$client_id]);
        }else{
            $users  = (array)cache('users');
            $room   = (array)cache('room');
            if (isset($this->fd_session_map[$client_id])){
                $fd = $this->fd_session_map[$client_id];
                $session_id = $this->fd_session_map[$client_id];
                unset($this->fd_session_map[$client_id], $this->wait_requests[$session_id], $this->sessions[$session_id]);
            }else{
                $fd = $client_id;
            }
            //var_dump('room',$room);
            unset($room[$fd]);
            $users  = array_filter($users,function ($v) use($fd){
                return $v == $fd ? false : true;
            });
            //var_dump('room',$room);
            //var_dump('user',$users);
            $cache      = Cache::init();
            // 获取缓存对象句柄
            $Redis      = $cache->handler();
            $u_time     = $Redis->pttl(config('cache.prefix').'users');
            $r_time     = $Redis->pttl(config('cache.prefix').'room');
            
            $resMsg['cmd']  = 'logout';
            $resMsg['id']   = $fd;
            
            $fds = $this->getFdFromRoom($fd);
            foreach ($fds as $v){
                if($fd != $v){
                    $this->sendJson($v, $resMsg);
                }
            }
            
            cache('users',$users,$u_time);
            cache('room',$room,$r_time);
        }
    }
    /**
     * 发送消息
     * @author wangcb
     * @param unknown $client_id
     * @param unknown $msg
     */
    function cmd_message($client_id, $msg){
        $resMsg['cmd']      = $msg['cmd'];
        $resMsg['msgid']    = rand(10000,99999).time().$this->user['id'];
        $resMsg['msg']      = htmlspecialchars($msg['msg']);
        $resMsg['name']     = $this->user['name'];
        $resMsg['avatar']   = $this->user['avatar'];
        $resMsg['time']     = date('Y-m-d H:i:s');
        
        $room       = cache('room');
        $rid        = $room[$client_id];
        $message    = cache('message:'.$rid);
        $message[]  = $resMsg;
        cache('message:'.$rid, $message, 604800);
        
        //广播其他人
        $fds = $this->getFdFromRoom($client_id);
        foreach ($fds as $fd){
            $this->sendJson($fd, $resMsg);
        }
        
        //发送到后台审核
        if(is_array($this->admin_fd)){
            $adm_fd = array_keys($this->admin_fd, $rid);
            foreach ($adm_fd as $v){
                $this->sendJson($v, $resMsg);
            }
        }
    }
    
    /**
     * 后台获取聊天历史记录
     * @author wangcb
     * @param unknown $client_id
     * @param unknown $msg
     */
    function cmd_msglog($client_id,$msg){
        //$room               = cache('room');
        $message            = cache('message:'.$msg['rid']);
        krsort($message);
        $resMsg['cmd']      = 'open';
        $resMsg['msglog']   = $message;  
        $this->sendJson($client_id, $resMsg);
    }
    
    /**
     * 删除消息
     * @author wangcb
     * @param unknown $cliend_id
     * @param unknown $msg
     */
    function cmd_delete($cliend_id, $msg){
        
        //获取该房间所有在线
        $rid    = $msg['rid'];
        $room   = (array)cache('room');
        $fd     = array_keys($room, $rid);
        
        //广播通知前台
        $resMsg['cmd']  = 'delete';
        $resMsg['msgid']= $msg['msgid']; 
        foreach ($fd as $v){
            $this->sendJson($v, $resMsg);
        }
        
        //发送到后台审核
        if(is_array($this->admin_fd)){
            $adm_fd = array_keys($this->admin_fd, $rid);
            foreach ($adm_fd as $v){
                $this->sendJson($v, $resMsg);
            }
        }
        
        
        $msgid      = (array)$msg['msgid'];
        $key        = 'message:'.$rid;
        $message    = (array)cache($key);
        $message    = array_filter($message,function ($v) use ($msgid){
            return !in_array($v['msgid'], $msgid);
        });
        $cache      = Cache::init();
        // 获取缓存对象句柄
        $Redis      = $cache->handler();
        $time       = $Redis->pttl(config('cache.prefix').$key);
        cache($key,$message,$time);
    }
    
    /**
     * 登录
     * @author wangcb
     * @param unknown $client_id
     * @param unknown $msg
     */
    function cmd_login($client_id, $msg){
        //保存登录用户
        $User   = new User();
        $User->save($this->swoole_table, $this->user);
        
        //保存用户、房间号、client_id关联
        $this->map($this->user['id'],$client_id,$msg['r']);
        
        //发送消息到客户端
        $resMsg['cmd']      = 'login';
        
        //获取该房间所有用户
        $fd                 = $this->getFdFromRoom($client_id);
        $users              = cache('users');
        $row                = array_intersect($users, $fd);
        $list               = array();
        foreach ($row as $k=>$v){
            $re             = explode('_', $k);
            $_user          = $this->swoole_table->users->get($re[0]);
            $_user['id']    = $v;
            $list[]         = $_user;
        }
        $resMsg['users']    = $list;
        
        $room               = cache('room');
        $rid                = $room[$client_id];
        $resMsg['msglog']   = cache('message:'.$rid);
        
        $fds = $this->getFdFromRoom($client_id);
        foreach ($fds as $fd){
            $this->sendJson($fd, $resMsg);
        }
    }
    
    /**
     * 发送json
     * @author wangcb
     * @param unknown $client_id
     * @param unknown $array
     */
    function sendJson($client_id, $array){
        $msg = json_encode($array);
        if ($this->send($client_id, $msg) === false){
            $this->close($client_id);
        }
    }
    
    /**
     * 保存三者之间的关系
     * @author wangcb
     * @param unknown $uid 用户id
     * @param unknown $client_id 客户端标识
     * @param unknown $roomid 房间号
     */
    function map($uid, $client_id, $rid){
        $user   = cache('users');
        $key    = $uid.'_'.$rid; //同个用户多个房间
        $user[$key] = $client_id;
        cache('users', $user, 86400);
    
        //fd与rid关联
        $room       = cache('room');
        $room[$client_id]  = $rid;
        cache('room', $room, 86400);
    }
    
    /**
     * 根据单个连接id（fd）查找房间所有的连接id
     * @author wangcb
     * @param unknown $fd
     */
    function getFdFromRoom($client_id) {
        $room   = cache('room');
        $rid    = isset($room[$client_id]) ? $room[$client_id] : 0;
        $fds    = [];
        if($room && $rid){
            $fds = array_keys($room,$rid);
        }
        return $fds;
    }
}