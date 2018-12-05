<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/11/30
 * Time: 15:33
 */

namespace App\common;

class Common{
    public static function getInt($num,$round=8){
        if(false !== stripos($num, "E")){
            $a = explode("e",strtolower($num));
            return bcmul($a[0], bcpow(10, $a[1], $round), $round);
        }
        return number_format($num,$round,'.','');
    }
}

