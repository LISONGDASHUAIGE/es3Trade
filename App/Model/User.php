<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/11/23
 * Time: 11:24
 */

namespace App\Model;

class User extends Base{
    protected $table = 'user';

    public function getUser(){
        return $this->db->get($this->table);
    }
}