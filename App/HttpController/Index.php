<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/11/21
 * Time: 14:45
 */

namespace App\HttpController;

use App\Model\User;
use EasySwoole\EasySwoole\ServerManager;
use EasySwoole\Http\AbstractInterface\Controller;

class Index extends Controller{
    function index(){
        $this->response()->write("hello word");
    }
}