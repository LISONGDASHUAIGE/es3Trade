<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/5/28
 * Time: 下午6:33
 */

namespace EasySwoole\EasySwoole;


use App\Crontab\TaskOne;
use App\Process\HotReload;
use App\Socket\WebSocketParser;
use App\Utility\Pool\MysqlPool;
use App\Utility\Pool\RedisPool;
use App\Socket\WebSocketEvent;
use EasySwoole\Component\Pool\PoolManager;
use EasySwoole\EasySwoole\Crontab\Crontab;
use EasySwoole\EasySwoole\Swoole\EventRegister;
use EasySwoole\EasySwoole\AbstractInterface\Event;
use EasySwoole\Http\Message\Status;
use EasySwoole\Socket\Dispatcher;
use EasySwoole\Http\Request;
use EasySwoole\Http\Response;
use App\Process\ProcessTest;

class EasySwooleEvent implements Event
{

    public static function initialize()
    {
        // TODO: Implement initialize() method.
        date_default_timezone_set('Asia/Shanghai');
        PoolManager::getInstance()->register(MysqlPool::class, Config::getInstance()->getConf('MYSQL.POOL_MAX_NUM'));
        PoolManager::getInstance()->register(RedisPool::class, Config::getInstance()->getConf('REDIS.POOL_MAX_NUM'));
    }

    public static function mainServerCreate(EventRegister $register)
    {
        // TODO: Implement mainServerCreate() method.
        ServerManager::getInstance()->getSwooleServer()->addProcess((new ProcessTest('one_process'))->getProcess());
        Crontab::getInstance()->addTask(TaskOne::class);

        /**
         * *************** WebSocket ***************
         */

        // 创建一个 Dispatcher 配置
        $conf = new \EasySwoole\Socket\Config();
        // 设置 Dispatcher 为 WebSocket 模式
        $conf->setType($conf::WEB_SOCKET);
        // 设置解析器对象
        $conf->setParser(new WebSocketParser());

        // 创建 Dispatcher 对象 并注入 config 对象
        $dispatch = new Dispatcher($conf);

        // 给server 注册相关事件 在 WebSocket 模式下  message 事件必须注册 并且交给 Dispatcher 对象处理
        $register->set(EventRegister::onMessage, function (\swoole_websocket_server $server, \swoole_websocket_frame $frame) use ($dispatch) {
            $dispatch->dispatch($server, $frame->data, $frame);
        });
        //自定义握手
        $websocketEvent = new WebSocketEvent();
        $register->set(EventRegister::onHandShake, function (\swoole_http_request $request, \swoole_http_response $response) use ($websocketEvent) {
            $websocketEvent->onHandShake($request, $response);
        });

        ServerManager::getInstance()->getSwooleServer()->addProcess((new HotReload('HotReload'))->getProcess());
        /**
         *  **************** Udp *******************
         */
        $server = ServerManager::getInstance()->getSwooleServer();
        $subPort = $server->addListener('0.0.0.0','9601',SWOOLE_UDP);
        $subPort->on('packet',function (\swoole_server $server, string $data, array $client_info){
            var_dump($data);
        });

        //添加自定义进程做定时udp发送
        $server->addProcess(new \swoole_process(function (\swoole_process $process){
            //服务正常关闭
            $process::signal(SIGTERM,function ()use($process){
                $process->exit(0);
            });
            //默认5秒广播一次
            \Swoole\Timer::tick(5000,function (){
                if($sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP))
                {
                    socket_set_option($sock,SOL_SOCKET,SO_BROADCAST,true);
                    $msg= '123456';
                    socket_sendto($sock,$msg,strlen($msg),0,'255.255.255.255',9602);//广播地址
                    socket_close($sock);
                }
            });
        }));
    }

    public static function onRequest(Request $request, Response $response): bool
    {
        // TODO: Implement onRequest() method.
        $response->withHeader('Content-type', 'text/html,charset=utf-8');
        $response->withHeader('Access-Control-Allow-Origin', '*');
        $response->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->withHeader('Access-Control-Allow-Credentials', 'true');
        $response->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        if ($request->getMethod() === 'OPTIONS') {
            $response->withStatus(Status::CODE_OK);
            $response->end();
        }
        return true;
    }

    public static function afterRequest(Request $request, Response $response): void
    {
        // TODO: Implement afterAction() method.
    }

    public static function onReceive(\swoole_server $server, int $fd, int $reactor_id, string $data):void
    {

    }

}