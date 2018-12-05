<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/11/30
 * Time: 14:58
 */

namespace App\HttpController;


use App\Model\TradeOrder;
use App\HttpController\validate\Trade as T;
use EasySwoole\Http\AbstractInterface\Controller;

class Trade extends Controller{
    protected $uid = 1;

    public function index(){
    }

    public function releass(){
        $request = $this->request();
//        $data = $request->getParsedBody();   // 获取POST参数
        $data = $request->getQueryParams();    // 获取GET参数
        $validate = new T();
        if(!$validate->check($data)) {
            $this->response()->writeJson(['msg'=>$validate->getError(),'code'=>400]);
            return;
        }
        $trade = new TradeOrder();
        switch ($data['order_type']){
            case 1:
                if(!isset($data['price']) || $data['price'] <= 0){
                    $this->response()->writeJson(['msg'=>'价格不能低于0','code'=>400]);
                    return;
                }
                $rs = $trade->restrictTrade($data,$this->uid);
                $this->response()->writeJson($rs);
                break;
            case 2:
                $rs[] = $trade->marketTrade($data,$this->uid);
        }
    }
}