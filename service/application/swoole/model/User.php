<?php
namespace app\swoole\model;
/**
 * @author wangcb	
 * @date: 2017年6月29日 上午10:48:39
 * 
 */
class User{
    
    
    /**
     * 判断用户
     * @author wangcb
     */
    function login($cookie){
        $member = explode('|', decode(str_replace('@|/|@', '@|\/|@', $cookie)));
        if(count($member) == 4 && $member[1]){
            $data['id'] = $member[1];
            $data['name'] = $member[2];
            $data['avatar'] = $member[3];
            return $data;
        }else{
            return false;
        }
    }
    
    /**
     * 保存登录用户
     * @author wangcb
     * @param unknown $user
     */
    function save($table, $user){
        $exit = $table->users->exist($user['id']);
        if(!$exit){
            $table->users->set($user['id'], $user);
        }
    }
}