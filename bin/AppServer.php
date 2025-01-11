<?php

use Fluffy\Domain\App\BaseApp;
use Fluffy\Domain\Message\HttpContext;
use Fluffy\Services\UtilsService;
use Fluffy\Swoole\Message\SwooleHttpRequest;
use Fluffy\Swoole\Message\SwooleHttpResponse;
use Swoole\Atomic;
use Swoole\Constant;
use Swoole\Coroutine\Channel;
use Swoole\Http\Request;
use Swoole\Table;
use Swoole\Timer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

class AppServer
{
    const PING_DELAY_MS = 25000;
    public Server $server;
    public BaseApp $app;
    public Table $crontabTable;
    public Table $syncTable;
    public Table $timeTable;
    public Atomic $iteration;
    public Table $serverTable;
    public int $requestWorkersCount;
    public int $taskWorkersCount;
    public string $uniqueId;
    public bool $stopped = false;
    public int $timerWorkerId = 0;
    public Channel $channel;
    /**
     * 
     * @param int|string $port 
     * @param array $config 
     * @return void 
     */
    public function __construct(private $port, private $config) {}

    public function setApp(BaseApp $app)
    {
        // var_dump(isset($this->app));
        $workerId = $this->server->getWorkerId();
        $this->app = $app;
        echo "[Server] app set up for $workerId" . PHP_EOL;
    }

    public function getHostConfig(): array
    {
        return $this->config['HOST_CONFIG'];
    }

    public function run()
    {
        $this->iteration = new Atomic();
        $this->crontabTable = new Swoole\Table(1024);
        $this->crontabTable->column('isRunning', Swoole\Table::TYPE_INT);
        $this->crontabTable->column('lastRun', Swoole\Table::TYPE_INT);
        $this->crontabTable->create();

        $this->syncTable = new Swoole\Table(1024);
        $this->syncTable->column('value', Swoole\Table::TYPE_INT);
        $this->syncTable->create();

        $this->timeTable = new Swoole\Table(1024);
        $this->timeTable->column('time', Swoole\Table::TYPE_INT);
        $this->timeTable->column('value', Swoole\Table::TYPE_INT);
        $this->timeTable->create();

        $this->serverTable = new Swoole\Table(1024);
        $this->serverTable->column('data', Swoole\Table::TYPE_STRING, 64);
        $this->serverTable->create();

        $this->server = new Server("0.0.0.0", $this->port, SWOOLE_PROCESS);
        $this->server->set($this->config['swoole']);

        $this->server->on('Open', [$this, 'onOpen']);
        $this->server->on('Message', [$this, 'onMessage']);
        $this->server->on('Close', [$this, 'onClose']);
        $this->server->on('start', [$this, 'onServerStart']);
        $this->server->on('workerstart', [$this, 'onWorkerStart']);
        $this->server->on('WorkerStop', function (Server $server, $workerId) {
            Timer::clearAll();
            $this->stopped = true;
            echo "[Server] Stop for $workerId" . PHP_EOL;
        });
        $this->server->on('request', [$this, 'onRequest']);
        $this->server->on('Task', [$this, 'onTask']);
        $this->server->on('finish', [$this, 'onFinish']);
        $this->server->on('pipeMessage', [$this, 'onPipeMessage']);

        $this->server->on('BeforeReload', function ($server) {
            echo "[Server] Reloading..\n";
            // file_put_contents($this->config['BASE_DIR'] . '/reload.run', '1');
            // var_dump(get_included_files());
        });

        $this->server->on('AfterReload', function ($server) {
            echo "[Server] Reloaded\n";
            // unlink($this->config['BASE_DIR'] . '/reload.run');
            // var_dump(get_included_files());
        });

        $this->server->start();
    }

    public function onServerStart(Server $server)
    {
        echo "CPU numbers: " . swoole_cpu_num() . "\n";
        echo "Swoole http server is started at http://0.0.0.0:{$this->port}\n";
        // go(function () {
        //     while (1) {
        //         echo "[Timer] time table watcher waiting.\n";
        //         $this->waiter->wait(-1);
        //         [$key, $lifetime] = ['ss', 10];
        //         echo "[Timer] received.\n";
        //         print_r([$key, $lifetime]);
        //         if ($key) {
        //             var_dump([$key, $lifetime]);
        //             // set up clean up timer
        //             \Swoole\Timer::after($lifetime * 1000, function () use ($key) {
        //                 $this->timeTable->del($key);
        //             });
        //         } else {
        //             echo "[Server] time table watcher stopped.\n";
        //             break;
        //         }
        //     }
        // });
    }

    public function onWorkerStart(Server $server, $workerId)
    {
        $this->channel = new Channel(1);
        // var_dump(get_included_files());
        try {
            if (function_exists('apc_clear_cache')) {
                apc_clear_cache();
                apc_clear_cache('user');
                apc_clear_cache('opcode');
            }
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }
            // vendor autoload
            require $this->config['BASE_DIR'] . '/vendor/autoload.php';
            // $server->taskworker true for task worker
            $workerType = $server->taskworker ? 'Task' : 'Request';
            $this->uniqueId = UtilsService::randomString(8);
            // var_dump(get_included_files());

            // you have autoload here
            if (!isset($this->config['app_factory'])) {
                throw new Exception("app_factory is not set in configs/server.php");
            }
            /**
             * @var BaseApp $app
             */
            $app = $this->config['app_factory']();
            $app->setUp();
            $app->setAppDependencies($this);
            $this->setApp($app);

            // Swoole\Timer::tick(5000, function () use ($workerId, $workerType) {
            //     echo "Memory usage $workerType $workerId: " . memory_get_usage(true) . "\n";
            // });
            echo "Worker started $workerType $workerId\n";
            // Files which won't be reloaded
            // var_dump(get_included_files());
            // require __DIR__ . '/vendor/autoload.php';
            // print_r(['worker id', $workerId]);
            // foreach($this->serverTable as $key => $data) {
            //     print_r([$key, $data]);
            // }
            if ($this->serverTable->exists('id' . $workerId)) {
                $oldUid = $this->serverTable->get('id' . $workerId);
                if (isset($oldUid['data'])) {
                    $this->serverTable->delete($oldUid['data']);
                }
            }
            $this->serverTable->set('id' . $workerId, ['data' => $this->uniqueId]);
            $this->serverTable->set($this->uniqueId, ['data' => $workerId . '']);

            $this->requestWorkersCount = $this->config['swoole'][Constant::OPTION_WORKER_NUM];
            $this->taskWorkersCount = $this->config['swoole'][Constant::OPTION_TASK_WORKER_NUM];
            // Use ping or your connection will get closed after ~1min of inactivity
            if ($workerId === ($this->taskWorkersCount + $this->requestWorkersCount - 1)) {
                $this->timerWorkerId = $workerId;
                Swoole\Timer::tick(self::PING_DELAY_MS, function () use ($server) {
                    foreach ($server->connections as $fd) {
                        if ($server->isEstablished($fd)) {
                            // echo "[Server] Ping $fd" . PHP_EOL;
                            $server->push($fd, 'ping', WEBSOCKET_OPCODE_PING);
                        }
                    }
                });

                $now = time();
                $toDelete = [];
                foreach ($this->timeTable as $key => $row) {
                    if ($row['time'] < $now) {
                        // expired
                        $toDelete[] = $key;
                    } else {
                        Timer::after(($row['time'] - $now) * 1000, function () use ($key) {
                            $this->timeTable->del($key);
                        });
                    }
                }
                foreach ($toDelete as $key) {
                    $this->timeTable->del($key);
                }

                // Cron jobs
                // last request/task worker
                echo "[Task] starting cron in $workerType $workerId\n";
                $this->app->startCrontab();

                // test
                // CronTabTest::TEST();
            }
        } catch (Throwable $t) {
            echo $t->__toString() . PHP_EOL;
        }
    }

    public function onRequest(Swoole\Http\Request $request, Swoole\Http\Response $response)
    {
        while (!isset($this->app)) {
            echo "[Server] Incoming Request waiting for initializing {$this->server->worker_id}..\n";
            Swoole\Coroutine::sleep(0.01);
        }
        $httpResponse = new SwooleHttpResponse($response);
        $this->app->handle(new HttpContext(
            new SwooleHttpRequest(
                $request,
                $request->server['request_method'],
                $request->server['request_uri'],
                $request->header ?? [],
                $request->get ?? [],
                $request->server
            ),
            $httpResponse
        ));
        //$response->end("Hello world {$this->server->worker_id} {$this->server->myServerId}");        
    }

    public function onOpen($server, Request $request)
    {
        echo "server: handshake success with fd{$request->fd}\n";
        // print_r([$server->worker_id, $this->uniqueId, new SwooleHttpRequest(
        //     $request,
        //     $request->server['request_method'],
        //     $request->server['request_uri'],
        //     $request->header ?? [],
        //     $request->get ?? [],
        //     $request->server
        // ), $request]);
    }

    public function onMessage(Server $server, Frame $frame)
    {
        while (!isset($this->app)) {
            echo "[Server] Incoming WS Message waiting for initializing {$this->server->worker_id}..\n";
            Swoole\Coroutine::sleep(0.01);
        }
        $this->app->onSocketMessage($frame->data, $frame->fd, $frame->opcode, $frame->finish, $frame->flags);
        // $server->push($frame->fd, "[Server] {$frame->data}");
        // echo "receive from {$frame->fd},opcode:{$frame->opcode},fin:{$frame->finish}\n";
        // print_r([$frame->data, $server->worker_id]);
        // foreach ($server->connections as $fd) {
        //     if ($server->isEstablished($fd)) {
        //         $server->push($fd, "[Server] {$frame->data}");
        //     }
        // }

        // performance test: send package (100k messages)
        //                               2023-07-01T11:42:00.870Z
        // bundle.js?v=230701144155:1673 2023-07-01T11:42:00.871Z
        // bundle.js?v=230701144155:1673 2023-07-01T11:42:00.947Z
        // bundle.js?v=230701144155:1673 2023-07-01T11:42:01.059Z
        // bundle.js?v=230701144155:1673 2023-07-01T11:42:01.167Z
        // bundle.js?v=230701144155:1673 2023-07-01T11:42:01.286Z
        // bundle.js?v=230701144155:1676 2023-07-01T11:42:01.397Z
    }

    public function onClose($server, $fd)
    {
        // event for connection close (socket and http)
        // echo "client {$fd} closed\n";
    }

    public function onPipeMessage($server, $src_worker_id, $message)
    {
        $this->app->onPipeMessage($message);
    }

    public function onTask(Swoole\WebSocket\Server $server, Swoole\Server\Task $task)
    {
        while (!isset($this->app)) {
            echo "[Server] Incoming Task waiting for initializing {$this->server->worker_id}..\n";
            Swoole\Coroutine::sleep(0.01);
        }

        [$class, $method, $params] = $task->data;
        $this->app->task($class, $method, $params);
        return [];
    }

    public function onFinish(Swoole\WebSocket\Server $server, Swoole\Server\Task $task)
    {
        // var_dump($task);
        return [];
    }
}
