<?php
/**
 * @author wangcb	
 * @date: 2017年6月14日 下午1:57:05
 * 
 */
namespace app\index\controller;
use think;
use think\Cache;

class Video{
    protected $domain   = 'v.123.com.cn';
    protected $serv;
    const GUID          = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    
    
    public function onHandShake($serv, $request, $response){
        $this->serv = $serv;
        if($request->header['origin'] != 'http://'.$this->domain){
            return false;
        }
        //获取登录信息
        $user = $this->isLogin($request->get['l']);
        if(!$user){
            return false;
        }
        
        if (!isset($request->header['sec-websocket-key']))
        {
            return false;
        }
        $key = $request->header['sec-websocket-key'];
        if (0 === preg_match('#^[+/0-9A-Za-z]{21}[AQgw]==$#', $key) || 16 !== strlen(base64_decode($key)))
        {
            return false;
        }
        
        
        /**
         * @TODO
         *   ? Origin;
         *   ? Sec-WebSocket-Protocol;
         *   ? Sec-WebSocket-Extensions.
         */
        $response->status(101);
        $response->header('Upgrade', 'websocket');
        $response->header('Connection', 'Upgrade');
        $response->header('Sec-WebSocket-Accept',base64_encode(sha1($key . static::GUID, true)));
        $response->header('Sec-WebSocket-Version', 13);
        
        $this->saveFd($user['id'], $request->fd, $request->get['r']);
        $serv->defer(function () use ($serv,$request,$user){
            $event = controller('index/video', 'controller');
            $event->onOpen($serv,$request,$user);
        });
        $this->saveUser($user);
        return true;
    }
    
    function onOpen($serv,$request,$user){
        $data['action'] = 'open';
        //根据fd查出该房间的所有fd
        $allfd          = $this->getFdFromRoom($request->fd,$rid);

        //查询历史消息记录
        $data['msglog'] = cache('message_'.$rid);
        
        //获取改房间的所有用户
        $users          = cache('users');
        $row            = array_intersect($users, $allfd);
        $list           = array();
        foreach ($row as $k=>$v){
            $re         = explode('_', $k);
            $_user      = $serv->table->users->get($re[0]);
            $_user['id']= $v;
            $list[]     = $_user;
        }
        $data['list']   = $list;
        foreach ($allfd as $v){
            $serv->push($v, json_encode($data));
        }
    }
    
    function onMessage($serv, $frame) {
       //获取用户id
        $data   = json_decode($frame->data,true);
        
        $user = $this->isLogin($data['l']);
        if(!$user){
            $serv->close($frame->fd);
            return false;
        }
        $send['action'] = 'message';
        $send['name']   = $user['name'];
        $send['avatar'] = $user['avatar'];
        $send['msg']    = htmlspecialchars(keywords($data['msg']));
        $send['time']   = date('Y-m-d H:i:s');
        $send['id']     = rand(10000,99999).time().$user['id'];
        
        $fd     = $frame->fd;
        $allfd  = $this->getFdFromRoom($fd,$rid);
        
        $cache      = Cache::init();
        // 获取缓存对象句柄
        $Redis      = $cache->handler();
        $sends  = $send;
        $sends['rid'] = $rid;
        $sends['uid'] = $user['id'];
        $message = cache('message_'.$rid);
        $message[] = $sends;
        $message = (array)cache('message_'.$rid, $message, 604800);
        
        //将消息发送后台审核
        $admin  = (array)cache('admin_fd_map');
        $admfd  = array_keys($admin,$rid);
        if(is_array($admfd)){
            foreach ($admfd as $v){
                $serv->push($v, json_encode($send));
            }
        }
        //发送到前端
        foreach ($allfd as $v){
            $serv->push($v, json_encode($send));
        }
    }
    
    function onClose($serv,$fd){
        //删除房间里的fd
        $room = cache('room');
        unset($room[$fd]);
        
        //删除swoole表用户信息
        $users = (array)cache('users');
        $arr   = array_keys($users,$fd);
        if($arr){
            $uid = explode('_', $arr[0]);
            $serv->table->users->del($uid[0]);
        }
        
        //给该房间用户发送消息
        $data['action'] = 'close';
        $data['id']     = $fd;
        $allfd          = $this->getFdFromRoom($fd,$rid);
        foreach ($allfd as $v){
            if($v != $fd){
                $serv->push($v, json_encode($data));
            }
        }
        cache('room', $room, 86400);
    }
    
    
    /**
     * 判断用户
     * @author wangcb
     */
    private function isLogin($user){
        $member = explode('|', decode(str_replace('@|/|@', '@|\/|@', $user)));
        if(count($member) == 4 && $member[1]){
            $data['id'] = $member[1];
            $data['name'] = $member[2];
            $data['avatar'] = $member[3];
            return $data;
        }else{
            return false;
        }
    }
    
    private function saveUser($user){
        $exit = $this->serv->table->users->exist($user['id']);
        if(!$exit){
            $this->serv->table->users->set($user['id'], $user);
        }
    }
    
    /**
     * 
     * @author wangcb
     * @param unknown $uid 用户id
     * @param unknown $fd   连接id
     * @param unknown $rid 房间id
     */
    private function saveFd($uid, $fd, $rid) {
        //uid与fd关联 不用fd作为键名是防止同个直播间打开多个页面增加链接数量
        $user   = cache('users');
        $key    = $uid.'_'.$rid;
        if (isset($user[$key])){
            $bool = $this->serv->connection_info($user[$key]);
            if($bool){
                $this->serv->close($user[$key]);
            }
        }
        $user[$key] = $fd;
        cache('users', $user, 86400);
        
        //fd与rid关联
        $room       = cache('room');
        $room[$fd]  = $rid;
        cache('room', $room, 86400);
    }
    /**
     * 根据单个连接id（fd）查找房间所有的连接id
     * @author wangcb
     * @param unknown $fd
     */
    private function getFdFromRoom($fd,&$rid) {
        $room   = cache('room');
        $rid    = isset($room[$fd]) ? $room[$fd] : 0;
        $fds    = [];
        if($room && $rid){
            $fds = array_keys($room,$rid);
        }
        return $fds;
    }
}

