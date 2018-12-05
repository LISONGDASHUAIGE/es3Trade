<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/11/30
 * Time: 15:08
 */

namespace App\Model;


use App\common\Common;

class TradeOrder extends Base{
    protected $tradeOrder = 'trade_order';
    protected $market = 'market';
    protected $userCoin = 'user_coin';
    protected $tradeLog = 'trade_log';

    public function restrictTrade($data,$uid){
        $market = $this->getDb()->where('id',$data['market_id'])->getOne($this->market); //获取市场设置
        if(!$market){
            return array(['msg'=>'市场不存在','code'=>400]);
        }
        $num = Common::getInt($data['num'],$market['num_round']);
        $price = Common::getInt($data['price'],$market['price_round']);
        $mum = Common::getInt($num * $price,$market['price_round']);

        // 交易时间
        if($market['begin_time'] != "00:00:00" && $market['end_time'] != "00:00:00"){
            $current = date('H:i:s',time()); //当前时间
            if($current < $market['begin_time'] || $current > $market['end_time']){
                return array(['msg'=>'该市场交易时间为'.$market['begin_time'].'-'.$market['end_time'],'code'=>400]);   // 不在交易时间内
            }
        }

        // 价格数量
        if($market['max_price'] > 0){
            if($price < $market['min_price'] || $price > $market['max_price']) {
                return array(['msg'=>'价格只能在'.$market['min_price'].'-'.$market['max_price'].'内','code'=>400]);//不在价格区间内
            }
        }
        if($market['max_num'] > 0){
            if($num < $market['min_num'] || $num > $market['max_num']) {
                return array(['msg'=>'交易数量只能在'.$market['min_num'].'-'.$market['max_num'].'内','code'=>400]);//不在数量区间内
            }return false;
        }

        // 涨跌幅限制
        if($market['last_price'] > 0 && $market['rise'] > 0){
            $risePrice = ($market['last_price']/100) *(100 + $market['rise']);
            if($risePrice < $price) {
                return array(['msg'=>'交易价格超过涨幅限制','code'=>400]);// 超过涨幅限制
            }
        }
        if($market['last_price'] > 0 && $market['fall'] > 0){
            $fallPrice = ($market['last_price']/100) *(100 + $market['fall']);
            if($fallPrice >$price) {
                return array(['msg'=>'交易价格低于跌幅限制','code'=>400]);//超过跌幅限制
            }
        }

        $this->getDb()->startTransaction();
        try{
            switch ($data['trade_type']){
                case 1:
                    $userCoin = $this->getDb()->where('user_id',$uid)->where('coin_id',$market['buy_coin'])->getOne($this->userCoin);
                    if($mum > $userCoin['usable']) {
                        return array(['msg'=>'资金不足','code'=>400]);
                    }
                    $rs[] = $this->getDb()->where('user_id',$uid)->where('coin_id',$market['buy_coin'])->setDec($this->userCoin,'usable',$mum);
                    $rs[] = $this->getDb()->where('user_id',$uid)->where('coin_id',$market['buy_coin'])->setInc($this->userCoin,'freeze',$mum);
                    $fee = $market['buy_fee'];
                    break;
                case 2:
                    $userCoin = $this->getDb()->where('user_id',$uid)->where('coin_id',$market['sell_coin'])->getOne($this->userCoin);
                    if($num > $userCoin['usable']) {
                        return array(['msg'=>'资金不足','code'=>400]);
                    }
                    $rs[] = $this->getDb()->where('user_id',$uid)->where('coin_id',$market['sell_coin'])->setDec($this->userCoin,'usable',$num);
                    $rs[] = $this->getDb()->where('user_id',$uid)->where('coin_id',$market['sell_coin'])->setInc($this->userCoin,'freeze',$num);
                    $fee = $market['sell_fee'];
                    break;
                default :
                    return array(['msg'=>'交易类型错误','code'=>400]);
                    break;
            }
            $list = [
                'user_id'=>$uid,
                'market_id'=>$market['id'],
                'price'=>$price,
                'num'=>$num,
                'mum'=>$mum,
                'fee'=>$fee,
                'trade_type'=>$data['trade_type'],
                'order_type'=>$data['order_type'],
                'begin_time'=>time(),
            ];
            $rs[0] = $this->getDb()->insert($this->tradeOrder,$list);
            if(self::check_arr($rs)){
                $this->getDb()->commit();
                $this->getRedis()->lPush('matching_order_id',$rs[0]);
                return array(['msg'=>'发布成功','code'=>400]);
            }
            $this->getDb()->commit();
            return array(['msg'=>'发布失败','code'=>400]);
        }catch (\Throwable $t){
            return array(['msg'=>'系统错误，请重试','code'=>400]);
        }
    }


    public function marketTrade($data,$uid){
        $market = $this->getDb()->where('id',$data['market_id'])->getOne($this->market); //获取市场设置
        if(!$market){
            return array(['msg'=>'市场不存在','code'=>400]);
        }

        $amount = Common::getInt($data['num'],$market['price_round']);

        // 交易时间
        if($market['begin_time'] != "00:00:00" && $market['end_time'] != "00:00:00"){
            $current = date('H:i:s',time()); //当前时间
            if($current < $market['begin_time'] || $current > $market['end_time']){
                return array(['msg'=>'该市场交易时间为'.$market['begin_time'].'-'.$market['end_time'],'code'=>400]);   // 不在交易时间内
            }
        }

        if($market['max_num'] > 0){
            if($amount < $market['min_num'] || $amount > $market['max_num']) {
                return array(['msg'=>'交易数量只能在'.$market['min_num'].'-'.$market['max_num'].'内','code'=>400]);//不在数量区间内
            }return false;
        }
        $this->getDb()->startTransaction();
        if($data['trade_type'] ==1){
            $last_mum = $this->getDb()
                ->where('trade_type',2)
                ->where('status',0)
                ->where('market_id',$data['market_id'])
                ->where('order_type',1)
                ->sum($this->tradeOrder,'mum');
            if($last_mum < $amount) return array(['msg'=>'挂单数量不足，最大交易量'.$last_mum,'code'=>400]);
            $userCoin = $this->getDb()->where('user_id',$uid)->where('coin_id',$market['buy_coin'])->getOne($this->userCoin);
        }
    }








    public function matching($id){
        $order = $this->getDb()->where('id',$id)->getOne($this->tradeOrder);
        $market = $this->getDb()->where('id',$order['market_id'])->getOne($this->market); //获取市场设置
        if($order['order_type'] == 1){
            for (; true;){
                if($order['trade_type'] == 1){
                    $buy_order = $this->getDb()->where('id',$id)->getOne($this->tradeOrder);
                    $sell_order = $this->getDb()
                        ->where('market_id',$order['market_id'])
                        ->where('trade_type',2)
                        ->where('status',0)
                        ->where('price',$order['price'],'<=')
                        ->getOne($this->tradeOrder);
                }else{
                    $sell_order = $this->getDb()->where('id',$id)->getOne($this->tradeOrder);
                    $buy_order = $this->getDb()
                        ->where('market_id',$order['market_id'])
                        ->where('trade_type',1)
                        ->where('status',0)
                        ->where('price',$order['price'],'>=')
                        ->getOne($this->tradeOrder);
                }
                if($buy_order && $sell_order){
                    $price = $order['trade_type'] == 1 ? $sell_order['price'] : $buy_order['price'];

                    $buy_amount = Common::getInt($buy_order['num'] - $buy_order['deal'],$market['num_round']);
                    $sell_amount = Common::getInt($sell_order['num'] - $sell_order['deal'],$market['num_round']);
                    if($buy_amount <= 0){
                        $this->getDb()->where('id',$buy_order['id'])->setValue($this->tradeOrder,'status',1);
                        continue;
                    }
                    if($sell_amount <= 0){
                        $this->getDb()->where('id',$sell_order['id'])->setValue($this->tradeOrder,'status',1);
                        continue;
                    }
                    if($buy_amount <= $sell_amount){
                        $num = $buy_amount;
                        $mum = Common::getInt($num * $price,$market['price_round']);
                        if($mum <0){
                            $this->getDb()->where('id',$buy_order['id'])->setValue($this->tradeOrder,'status',1);
                            continue;
                        }
                    }else{
                        $num = $sell_amount;
                        $mum = Common::getInt($num * $price,$market['price_round']);
                        if($mum <0){
                            $this->getDb()->where('id',$sell_order['id'])->setValue($this->tradeOrder,'status',1);
                            continue;
                        }
                    }

                    $buy_fee = Common::getInt($num / 100 * $buy_order['fee']);    //买家应扣手续费
                    $sell_fee = Common::getInt($mum / 100 * $sell_order['fee']);  //卖家应扣手续费

                    //买家扣除币种资金
                    $buyCoin = $this->getDb()->where('user_id',$buy_order['user_id'])->where('coin_id',$market['buy_coin'])->getOne($this->userCoin);
                    if($buyCoin['freeze'] < $mum){
                        $this->getDb()->where('id',$buy_order['id'])->setValue($this->tradeOrder,'status',1);
                        continue;
                    }

                    //卖家扣除币种资金
                    $sellCoin = $this->getDb()->where('user_id',$sell_order['user_id'])->where('coin_id',$market['sell_coin'])->getOne($this->userCoin);
                    if($sellCoin['freeze'] < $num){
                        $this->getDb()->where('id',$sell_order['id'])->setValue($this->tradeOrder,'status',1);
                        continue;
                    }

                    $this->getDb()->startTransaction();
                    try{
                        // 买家扣除buy_coin 获得sell_coin
                        $rs[] = $this->getDb()->where('user_id',$buy_order['user_id'])->where('coin_id',$market['buy_coin'])->setDec($this->userCoin,'freeze',$mum);
                        $rs[] = $this->getDb()->where('user_id',$buy_order['user_id'])->where('coin_id',$market['sell_coin'])->setInc($this->userCoin,'usable',$num-$buy_fee);
                        // 卖家扣除sell_coin 获得 buy_coin
                        $rs[] = $this->getDb()->where('user_id',$sell_order['user_id'])->where('coin_id',$market['sell_coin'])->setDec($this->userCoin,'freeze',$num);
                        $rs[] = $this->getDb()->where('user_id',$sell_order['user_id'])->where('coin_id',$market['buy_coin'])->setInc($this->userCoin,'usable',$mum - $sell_fee);

                        // 修改已成交数量
                        $rs[] = $this->getDb()->where('id',$buy_order['id'])->setInc($this->tradeOrder,'deal',$num);
                        $rs[] = $this->getDb()->where('id',$buy_order['id'])->setInc($this->tradeOrder,'turnover',$mum);
                        $rs[] = $this->getDb()->where('id',$sell_order['id'])->setInc($this->tradeOrder,'deal',$num);
                        $rs[] = $this->getDb()->where('id',$sell_order['id'])->setInc($this->tradeOrder,'turnover',$mum);

                        $list = [
                            'buy_id'=>$buy_order['id'],
                            'sell_id'=>$sell_order['id'],
                            'buy_user'=>$buy_order['user_id'],
                            'sell_user'=>$sell_order['user_id'],
                            'market'=>$market['id'],
                            'price'=>$price,
                            'num'=>$num - $buy_fee,
                            'mum'=>$mum - $sell_fee,
                            'buy_fee'=>$buy_fee,
                            'sell_fee'=>$sell_fee,
                            'time'=>time()
                        ];
                        // 添加交易记录
                        $rs[] = $this->getDb()->insert($this->tradeLog,$list);

                        // 订单数量成交完成修改订单状态
                        $buy_log = $this->getDb()->where('id',$buy_order['id'])->getOne($this->tradeOrder);
                        if($buy_log['num'] <= $buy_log['deal']){
                            $rs[] = $this->getDb()->where('id',$buy_log['id'])->setValue($this->tradeOrder,'status',1);
                        }
                        $sell_log = $this->getDb()->where('id',$sell_order['id'])->getOne($this->tradeOrder);
                        if($sell_log['num'] <= $sell_log['deal']){
                            $rs[] = $this->getDb()->where('id',$sell_log['id'])->setValue($this->tradeOrder,'status',1);
                        }

                        if($buy_order['price'] > $price){
                            $untread = Common::getInt(($buy_order['price'] - $price) * $num,$market['price_round']);
                            if($untread > 0){
                                $rs[] = $this->getDb()->where('user_id',$buy_order['user_id'])->where('coin_id',$market['buy_coin'])->setinc($this->userCoin,'usable',$untread);
                                $rs[] = $this->getDb()->where('user_id',$buy_order['user_id'])->where('coin_id',$market['buy_coin'])->setDec($this->userCoin,'freeze',$untread);
                            }
                        }
                        if(self::check_arr($rs)){
                            $this->getDb()->commit();
                            break;
                        }
                        $this->getDb()->rollback();
                        break;
                    }catch (\Throwable $t){
                        $this->getDb()->rollback();
                        break;
                    }
                }else{
                    break;
                }
            }
        }
    }
}