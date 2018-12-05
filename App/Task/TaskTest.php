<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/11/21
 * Time: 15:22
 */

namespace App\Task;

use App\Utility\Pool\RedisPool;
use EasySwoole\EasySwoole\Swoole\Task\AbstractAsyncTask;
use App\Utility\Pool\MysqlPool;
use EasySwoole\Component\Pool\PoolManager;
use EasySwoole\EasySwoole\Config;

class TaskTest extends AbstractAsyncTask {
    function run($taskData, $taskId, $fromWorkerId)
    {
        go(function (){
//            $db = PoolManager::getInstance()->getPool(MysqlPool::class)->getObj(Config::getInstance()->getConf('MYSQL.POOL_TIME_OUT'));
//            $db->insert('user',['username'=>rand(1000,9999),'password'=>rand(1000,9999),'addtime'=>time()]);
//            var_dump(json_encode($db->get('user')));
            $redis = PoolManager::getInstance()->getPool(RedisPool::class)->getObj(Config::getInstance()->getConf('REDIS.POOL_TIME_OUT'));
//            $redis->set('name', 'blank');
            $name = $redis->get('cute_girl');
            var_dump($name);
            PoolManager::getInstance()->getPool(RedisPool::class)->recycleObj($redis);

        });
//        echo "执行task模板任务\n";
        return 1;
        // TODO: Implement run() method.
    }

    function finish($result, $task_id)
    {
        echo "task模板任务完成\n";
        return 1;
        // TODO: Implement finish() method.
    }

}