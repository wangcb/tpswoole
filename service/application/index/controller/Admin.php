<?php
/**
 * 视频后台连接控制
 * @author wangcb	
 * @date: 2017年6月20日 下午1:41:31
 * 
 */
namespace app\index\controller;
use think\Cache;
class Admin{
    protected $cache_key   = 'admin_fd_map';
    const GUID          = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    
    public function onHandShake($serv, $request, $response){
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
        
        $rid = $request->get['r'];
        $fd_map[$request->fd]   = $rid;
        cache($this->cache_key,$fd_map,86400);
        
        $serv->defer(function () use ($serv,$request,$rid){
            $event = controller('index/Admin', 'controller');
            $event->onOpen($serv,$request,$rid);
        });
        
        return true;
    }
    
    function onOpen($serv,$request,$rid){
        $data['action'] = 'open';
        $message        = cache('message_'.$rid);
        krsort($message);
        $data['list']   = $message;
        $admin          = cache($this->cache_key);
        $admfd          = array_keys($admin,$rid);
        if($admfd){
            foreach ($admfd as $v){
                $serv->push($v, json_encode($data));
            }
        }
    }
    
    function onMessage($serv, $frame) {
        
        $data   = json_decode($frame->data,true);
        $mid    = $data['id'];//消息id
        $rid    = $data['r'];//房间号
        $this->delMsg($rid, $mid);
        $msg['action']  = 'delete';
        $msg['list']    = $mid;
        
        //前台发送
        $room   = (array)cache('room');
        $allfd  = array_keys($room, $rid);
        if($allfd){
            foreach ($allfd as $v){
                $serv->push($v, json_encode($msg));
            }
        }
        
        //服务端发送
        $admin  = cache($this->cache_key);
        $admfd  = array_keys($admin,$rid);
        if($admfd){
            foreach ($admfd as $v){
                $serv->push($v, json_encode($msg));
            }
        }
    }
    
    function onClose($serv,$fd){
        $fd_map = cache($this->cache_key);
        unset($fd_map[$fd]);
        cache($this->cache_key,$fd_map,86400);
    }
    
    /**
     * 删除消息id
     * @author wangcb
     * @param unknown $mid
     */
    public function delMsg($rid, $mid){
        $mid        = (array)$mid;
        $key        = 'message_'.$rid; 
        $message    = (array)cache($key);
        $message    = array_filter($message,function ($v) use ($mid){
            return !in_array($v['id'], $mid);
        });
        $cache      = Cache::init();
        // 获取缓存对象句柄
        $Redis      = $cache->handler();
        $time       = $Redis->pttl(config('cache.prefix').$key);
        cache($key,$message,$time);
    }
}
