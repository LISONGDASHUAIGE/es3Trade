<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/11/21
 * Time: 14:56
 */

namespace App\Process;

use App\common\Common;
use EasySwoole\Component\Pool\PoolManager;
use EasySwoole\EasySwoole\Swoole\Process\AbstractProcess;
use Swoole\Process;
use EasySwoole\EasySwoole\Swoole\Time\Timer;
use EasySwoole\EasySwoole\Config;
use App\Utility\Pool\MysqlPool;
use App\Utility\Pool\RedisPool;

class ProcessTest extends AbstractProcess{
    protected $tradeOrder = 'trade_order';
    protected $market = 'market';
    protected $userCoin = 'user_coin';
    protected $tradeLog = 'trade_log';

    public function run(Process $process)
    {
        go(function (){
            // 定时器
            Timer::loop(1, function (){
                $db = PoolManager::getInstance()->getPool(MysqlPool::class)->getObj();
                $redis = PoolManager::getInstance()->getPool(RedisPool::class)->getObj();
                if(!$redis || !$db) {
                    PoolManager::getInstance()->getPool(MysqlPool::class)->recycleObj($db);
                    PoolManager::getInstance()->getPool(RedisPool::class)->recycleObj($redis);
                    return false;
                }
                $id = $redis->rpop('matching_order_id');
                if(!$id) {
                    PoolManager::getInstance()->getPool(MysqlPool::class)->recycleObj($db);
                    PoolManager::getInstance()->getPool(RedisPool::class)->recycleObj($redis);
                    return false;
                }
                $order = $db->where('id',$id)->getOne($this->tradeOrder);
                $market = $db->where('id',$order['market_id'])->getOne($this->market); //获取市场设置
                if($order['order_type'] == 1){
                    for (; true;){
                        if($order['trade_type'] == 1){
                            $buy_order = $db->where('id',$id)->getOne($this->tradeOrder);
                            $sell_order = $db
                                ->where('market_id',$order['market_id'])
                                ->where('trade_type',2)
                                ->where('status',0)
                                ->where('id',$id,'<')
                                ->where('price',$order['price'],'<=')
                                ->getOne($this->tradeOrder);
                        }else{
                            $sell_order = $db->where('id',$id)->getOne($this->tradeOrder);
                            $buy_order = $db
                                ->where('market_id',$order['market_id'])
                                ->where('trade_type',1)
                                ->where('status',0)
                                ->where('id',$id,'<')
                                ->where('price',$order['price'],'>=')
                                ->getOne($this->tradeOrder);
                        }
                        if($buy_order && $sell_order){
                            $price = $order['trade_type'] == 1 ? $sell_order['price'] : $buy_order['price'];

                            $buy_amount = Common::getInt($buy_order['num'] - $buy_order['deal'],$market['num_round']);
                            $sell_amount = Common::getInt($sell_order['num'] - $sell_order['deal'],$market['num_round']);
                            if($buy_amount <= 0){
                                $db->where('id',$buy_order['id'])->setValue($this->tradeOrder,'status',1);
                                continue;
                            }
                            if($sell_amount <= 0){
                                $db->where('id',$sell_order['id'])->setValue($this->tradeOrder,'status',1);
                                continue;
                            }
                            if($buy_amount <= $sell_amount){
                                $num = $buy_amount;
                                $mum = Common::getInt($num * $price,$market['price_round']);
                                if($mum <0){
                                    $db->where('id',$buy_order['id'])->setValue($this->tradeOrder,'status',1);
                                    continue;
                                }
                            }else{
                                $num = $sell_amount;
                                $mum = Common::getInt($num * $price,$market['price_round']);
                                if($mum <0){
                                    $db->where('id',$sell_order['id'])->setValue($this->tradeOrder,'status',1);
                                    continue;
                                }
                            }

                            $buy_fee = Common::getInt($num / 100 * $buy_order['fee']);    //买家应扣手续费
                            $sell_fee = Common::getInt($mum / 100 * $sell_order['fee']);  //卖家应扣手续费

                            //买家扣除币种资金
                            $buyCoin = $db->where('user_id',$buy_order['user_id'])->where('coin_id',$market['buy_coin'])->getOne($this->userCoin);
                            if($buyCoin['freeze'] < $mum){
                                $db->where('id',$buy_order['id'])->setValue($this->tradeOrder,'status',1);
                                continue;
                            }

                            //卖家扣除币种资金
                            $sellCoin = $db->where('user_id',$sell_order['user_id'])->where('coin_id',$market['sell_coin'])->getOne($this->userCoin);
                            if($sellCoin['freeze'] < $num){
                                $db->where('id',$sell_order['id'])->setValue($this->tradeOrder,'status',1);
                                continue;
                            }

                            $db->startTransaction();
                            try{
                                // 买家扣除buy_coin 获得sell_coin
                                $rs[] = $db->where('user_id',$buy_order['user_id'])->where('coin_id',$market['buy_coin'])->setDec($this->userCoin,'freeze',$mum);
                                $rs[] = $db->where('user_id',$buy_order['user_id'])->where('coin_id',$market['sell_coin'])->setInc($this->userCoin,'usable',$num-$buy_fee);
                                // 卖家扣除sell_coin 获得 buy_coin
                                $rs[] = $db->where('user_id',$sell_order['user_id'])->where('coin_id',$market['sell_coin'])->setDec($this->userCoin,'freeze',$num);
                                $rs[] = $db->where('user_id',$sell_order['user_id'])->where('coin_id',$market['buy_coin'])->setInc($this->userCoin,'usable',$mum - $sell_fee);

                                // 修改已成交数量
                                $rs[] = $db->where('id',$buy_order['id'])->setInc($this->tradeOrder,'deal',$num);
                                $rs[] = $db->where('id',$buy_order['id'])->setInc($this->tradeOrder,'turnover',$mum);
                                $rs[] = $db->where('id',$sell_order['id'])->setInc($this->tradeOrder,'deal',$num);
                                $rs[] = $db->where('id',$sell_order['id'])->setInc($this->tradeOrder,'turnover',$mum);

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
                                $rs[] = $db->insert($this->tradeLog,$list);

                                // 订单数量成交完成修改订单状态
                                $buy_log = $db->where('id',$buy_order['id'])->getOne($this->tradeOrder);
                                if($buy_log['num'] <= $buy_log['deal']){
                                    $rs[] = $db->where('id',$buy_log['id'])->setValue($this->tradeOrder,'status',1);
                                }
                                $sell_log = $db->where('id',$sell_order['id'])->getOne($this->tradeOrder);
                                if($sell_log['num'] <= $sell_log['deal']){
                                    $rs[] = $db->where('id',$sell_log['id'])->setValue($this->tradeOrder,'status',1);
                                }

                                if($buy_order['price'] > $price){
                                    $untread = Common::getInt(($buy_order['price'] - $price) * $num,$market['price_round']);
                                    if($untread > 0){
                                        $rs[] = $db->where('user_id',$buy_order['user_id'])->where('coin_id',$market['buy_coin'])->setinc($this->userCoin,'usable',$untread);
                                        $rs[] = $db->where('user_id',$buy_order['user_id'])->where('coin_id',$market['buy_coin'])->setDec($this->userCoin,'freeze',$untread);
                                    }
                                }
                                if($this->check_arr($rs)){
                                    $db->commit();
                                    break;
                                }
                                $db->rollback();
                                break;
                            }catch (\Throwable $t){
                                $db->rollback();
                                break;
                            }
                        }else{
                            break;
                        }
                    }
                }
                PoolManager::getInstance()->getPool(MysqlPool::class)->recycleObj($db);
                PoolManager::getInstance()->getPool(RedisPool::class)->recycleObj($redis);
            });
        });


        // TODO: Implement run() method.
    }

    public function onShutDown()
    {
        echo "process is onShutDown.\n";
        // TODO: Implement onShutDown() method.
    }

    public function onReceive(string $str)
    {
        echo "process is onReceive.\n";
        // TODO: Implement onReceive() method.
    }

    public function check_arr($rs){
        foreach ($rs as $v) {
            if (!$v) {
                return false;
            }
        }
        return true;
    }
}