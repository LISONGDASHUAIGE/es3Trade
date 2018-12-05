<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/12/1
 * Time: 10:44
 */

namespace App\HttpController\validate;

use think\Validate;

class Trade extends Validate{
    protected $rule =   [
        'order_type'  => 'require|in:1,2',
        'trade_type'  => 'require|in:1,2',
        'market_id'  => 'require|number',
        'num'  => 'require|number',
    ];

    protected $message  =   [
        'order_type.require' => '请选择订单类型',
        'order_type.in'     => '订单类型错误',
        'trade_type.require' => '请选择交易类型',
        'trade_type.in'     => '交易类型错误',
        'market_id.require' => '请选择交易市场',
        'market_id.number'     => '市场错误',
        'num.require' => '请输入交易数额',
        'num.number'     => '交易数额只能是数字',
    ];
}