<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/11/22
 * Time: 14:13
 */

namespace App\Socket\Websocket;

use EasySwoole\EasySwoole\ServerManager;
use EasySwoole\Socket\AbstractInterface\Controller;

class Test extends Controller{
    public function index(){
        //$this->response()->setMessage('your fd is '. $this->caller()->getClient()->getFd());
        $this->response()->setStatus($this->response()::STATUS_RESPONSE_DETACH);
        ServerManager::getInstance()->getSwooleServer()->push($this->caller()->getClient()->getFd(),'push in http at '.time(),WEBSOCKET_OPCODE_BINARY);
    }
}