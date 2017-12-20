<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: 流年 <liu21st@gmail.com>
// +----------------------------------------------------------------------

// 应用公共文件
/**
 * 关键词过滤
 * @author wangcb
 */

function getKeywords(){
    $data = (array)cache('keywords');
    if(!$data){
        $res = curl('http://113.107.150.180:52004/api/SensitiveWord/SelectSensitiveWord?wordType=3&pageIndex=1&pageSize=10000','');
        $res = json_decode($res,true);
        if($res['code'] == 100){
            array_walk($res['data'], function(&$v){$v = preg_replace('/\s+?/','', $v);});
            $data  = array_chunk(array_column($res['data'], 'SWords'), 200);
            cache('keywords',$data,300);
        }
    }
    return $data;
}

function keywords($str = null) {
    if($str){
        //dump($data);
        $data   = getKeywords();
        foreach ($data as $value){
            foreach ($value as &$v){
                $v = '('.implode(')[^0-9a-zA-Z\x{4e00}-\x{9fa5}]*?(', ch2arr($v)).')';
            }
            $preg   = implode('|', $value);
            $str    = preg_replace_callback(
                '/'.$preg.'/iu',
                function($match){
                    $str = $match[0];
                    foreach ($match as $k=>$v){
                        if($k > 0 && !empty($v)){
                            $str = str_replace($v, '*', $str);
                        }
                    }
                    return $str;
                }, $str);
        }
        return $str;
    }
}
//字符串转数组
function ch2arr($str)
{
    $length = mb_strlen($str, 'utf-8');
    $array = [];
    for ($i=0; $i<$length; $i++){
        $array[] = str_replace(array('\\','/','*','.','+','?','(',')'), array('\\\\','\/','\*','\.','\+','\?','\(','\)'), mb_substr($str, $i, 1, 'utf-8'));
    }
    return $array;
}

function curl($url, $data, $header=null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    if (!empty($header))
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    //要求结果为字符串且输出到屏幕上
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    //post提交方式
    if (!empty($data)) {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }
    //运行curl
    $data = curl_exec($ch);
    //返回结果
    curl_close($ch);
    return $data;
}

//登录解密
function decode($string = '', $key = '67e7f45b468a56f5942df0f1c91a0e2d')
{
    //还原
    $string = str_split(str_replace('@|\/|@', '=', $string));
    //编码KEY
    $encode_key = str_split(base64_encode($key));
    //取得KEY的长度
    $key_length = count($encode_key);
    //遍历已加密字符
    foreach ($string as $k => $v)
    {
        if ($k >= $key_length)
        {
            break;
        }
        if ( ! isset($string[$k+$k+1]))
        {
            break;
        }
        if ($string[$k+$k+1] == $encode_key[$k])
        {
            unset($string[$k+$k+1]);
        }
    }
    //反编译
    return base64_decode(implode('', $string));
}