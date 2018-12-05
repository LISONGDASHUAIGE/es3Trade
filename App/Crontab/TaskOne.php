<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/11/22
 * Time: 13:33
 */


namespace App\Crontab;

use EasySwoole\EasySwoole\Crontab\AbstractCronTask;

class TaskOne extends AbstractCronTask{

    public static function getRule(): string
    {
        // TODO: Implement getRule() method.
        // 定时周期 （每小时）
        return '*/1 * * * *';
    }

    public static function getTaskName(): string
    {
        // TODO: Implement getTaskName() method.
        // 定时任务名称
        return 'taskOne';
    }

    static function run(\swoole_server $server, int $taskId, int $fromWorkerId)
    {
        // TODO: Implement run() method.
        // 定时任务处理逻辑
//        var_dump('run once per hour');
    }
}