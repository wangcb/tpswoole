<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8">
        <title>swoole+php-即时通讯</title>
        <script type="text/javascript" src="http://cdn.static.123.com.cn/CloudStatic/common/common_js/jquery-1.7.2.min.js"></script>
    </head>
    <body>
        <?php
        session_start();
        $uid = session_id();
        ?>
        <div id="msglist"></div>
        <span>欢迎：<?php echo $uid; ?></span>
        <input id="msg"/>
        <button type="button" onclick="pushMsg()">发送</button>
        <script src="/js/chat.js" type="text/javascript"></script>
        <script src="/js/comet.js" type="text/javascript"></script>
        <script type="text/javascript">
            var serverReturn = false;
            $(document).ready(function () {
                chat.init({
                    server: 'ws://127.0.0.1:9503/',
                    open: function (evt) {
                        msg = new Object();
                        msg.cmd = 'login';
                        msg.r = '87a43f76a9b4988a9e015eb014f788e7';
                        msg.l = $.cookie('_member');
                        chat.send(JSON.stringify(msg));
                    },
                    message: function (evt) {
                        serverReturn = true;
                        var data = eval('(' + evt.data + ')');
                        console.log(data);
                        if (data['cmd'] == 'login') {
                            var msghtml = '';
                            $.each(data['msglog'], function (i, item) {
                                msghtml += '<li id="' + item['msgid'] + '"><h2><img src="' + (item['avatar'] ? item['avatar'] : 'http://cdn.static.6543210.com/Public/images/liveroom/list-pic.png') + '" width="20" height="20" alt=""/><span>' + item['name'] + '：</span></h2>\
                                <p>' + item['msg'].replace(/&lt;br \/&gt;/g, "</p><p>") + '</p>\
                            </li>';
                            })
                            $("#msg-list").html(msghtml);
                            var html = '';
                            $.each(data['users'], function (i, item) {
                                html += '<li><em>' + (i + 1) + '.</em><img src="' + (item['avatar'] ? item['avatar'] : 'http://member.123.com.cn/Public/images/m1.jpg') + '" width="20" height="20" alt=""/><span>' + item['name'] + '</span></li>';
                            })
                            $("#user-list").html(html);
                        }
                        if (data['cmd'] == 'logout') {
                            $("#" + data['id']).remove();
                        }
                        if (data['cmd'] == 'delete') {
                            $.each(data['msgid'], function (i, item) {
                                $("#" + item).remove();
                            });
                        }
                        if (data['cmd'] == 'message') {
                            var html = '<li id="' + data['msgid'] + '"><h2><img src="' + (data['avatar'] ? data['avatar'] : 'http://cdn.static.6543210.com/Public/images/liveroom/list-pic.png') + '" width="20" height="20" alt=""/><span>' + data['name'] + '：</span></h2>\
                                <p>' + data['msg'].replace(/&lt;br \/&gt;/g, "</p><p>") + '</p>\
                            </li>';
                            $("#msg-list").append(html);
                        }
                        $('.introduce-list').mCustomScrollbar('scrollTo', 'last');
                    },
                    close: function (evt) {
                    },
                    error: function (evt) {
                        layui.use('layer', function () {
                            var layer = layui.layer;
                            layer.alert('连接失败，重新加载页面页面？', {
                                icon: 3,
                                skin: 'layer-ext-moon',
                                btn: ['确定', '取消'],
                                btn1: function () {
                                    location.reload();
                                }
                            });
                        });
                    }
                });
            });
            function pushMsg() {
                var msg = $('#message').val();
                if (msg.length > 0) {
                    if (msg.length > 50) {
                        msg = msg.substr(0, 50) + '...';
                    }
                    msg = msg.replace(/\r\n/g, "<br />");
                    msg = msg.replace(/\n/g, "<br />");
                    msg = msg.replace(/"/g, "“");
                    var cookie_uname = '5a3a0845c6214';
                    var json = '{"cmd":"message","msg":"' + msg + '","l":"' + $.cookie('_member') + '","u":"' + cookie_uname + '"}';
                    chat.send(json);
                    $("#message").val('');
                }
                setTimeout(function () {
                    if (!serverReturn) {
                        layui.use('layer', function () {
                            var layer = layui.layer;
                            layer.msg('请重试');
                        });
                    }
                }, 5000);
            }
        </script>
    </body>
</html>