var chat = {
    opt: {
        server: null,
        ws: null,
        open: null,
        message: null,
        close: null,
        error: null
    },
    init: function (opts) {
        if (typeof opts.open == 'function') {
            this.opt.open = opts.open;
        }
        if (typeof opts.message == 'function') {
            this.opt.message = opts.message;
        }
        if (typeof opts.close == 'function') {
            this.opt.close = opts.close;
        }
        if (typeof opts.error == 'function') {
            this.opt.error = opts.error;
        }
        this.opt.server = opts.server
        this.opt.input = opts.input
        this.ws();
    },
    ws: function () {
        //使用原生WebSocket
        if (window.WebSocket || window.MozWebSocket){
        	this.opt.ws = new WebSocket(this.opt.server);
        }else{
         //使用http xhr长轮循
        	this.opt.ws = new Comet(this.opt.server);
        }
        //this.opt.ws = new Comet(this.opt.server);
        this.wsOpen();
        this.wsMessage();
        this.wsOnClose();
        this.wsError();
    },
    send: function (data) {
        this.opt.ws.send(data);
    },
    wsOpen: function () {
        this.opt.ws.onopen = function (evt) {
            if (typeof chat.opt.open == 'function') {
                chat.opt.open(evt);
            }
        }
    },
    wsMessage: function () {
        this.opt.ws.onmessage = function (evt) {
            if (typeof chat.opt.message == 'function') {
                chat.opt.message(evt);
            }
        }
    },
    wsOnClose: function () {
        this.opt.ws.onclose = function (evt) {
            if (typeof chat.opt.close == 'function') {
                chat.opt.close(evt);
            }
        };
    },
    wsError: function () {
        this.opt.ws.onerror = function (evt, e) {
            if (typeof chat.opt.error == 'function') {
                chat.opt.error(evt);
            }
        };
    }
}