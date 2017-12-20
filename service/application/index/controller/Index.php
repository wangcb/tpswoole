<?php

namespace app\index\controller;

class Index {

    public function index() {
        /* $message = cache('message_c4ca4238a0b923820dcc509a6f75849b');
        krsort($message);
        dump($message); */
        $data['a'] = 'admin';
        $data['id']= array('9403514979475261014143','7892314979476161014143');
        echo json_encode($data);
    }
    
    public function login() {
        //cache('fd',null);
        /* $fd = cache('users',null);
        $fd = cache('room',null); */
        //echo cache('users','123234');
       /*  $fp = fsockopen('192.168.1.221','9502');
        if (!$fp) {
            echo "$errstr ($errno)<br />\n";
        } else {
            $out = "GET / HTTP/1.1\r\n";
            $out .= "Host: www.example.com\r\n";
            $out .= "Connection: Close\r\n\r\n";
            fwrite($fp, $out);
            while (!feof($fp)) {
                echo fgets($fp, 128);
            }
            fclose($fp);
        } */
        /* $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket < 0) {
            echo "socket创建失败原因: " . socket_strerror($socket) . "\n";
        } else {
            echo "OK，HE HE.\n";
        }
        $result = socket_connect($socket, '192.168.1.221', '9502');
        if ($result < 0) {
            echo "SOCKET连接失败原因: ($result) " . socket_strerror($result) . "\n";
        } else {
            echo "OK.\n";
        }
        //发送命令
        $in = "HEAD / HTTP/1.1\r\n";
        $in .= "Connection: Close\r\n\r\n";
        $out = '';
        echo "Send Command..........";
        $in = "sun\n";
        $res = socket_write($socket, $in, strlen($in));
        dump($res); */
        /* echo "OK.\n";
        echo "Reading Backinformatin:\n\n";
        while ($out = socket_read($socket, 2048)) {
            echo $out;
        } */
    }
}
