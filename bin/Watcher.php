<?php

use function Swoole\Coroutine\run;
use function Swoole\Coroutine\go;
use Swoole\Coroutine;
use Swoole\Runtime;
use Swoole\Process;

class Watcher
{
    private Process $runProcess;
    private Process $watchProcess;
    private Process $beforeProcess;
    private $watchStreamResource;
    private array $watchList;
    private int $refreshCount = 0;
    private $timerId = false;
    private int $parentPid = 0;

    public function __construct(private string $baseDir, private $argv, private array $config)
    {
        $this->watchList = [];
        $this->parentPid = getmypid();
    }

    public function start()
    {
        if (count($this->argv) > 0) {
            if ($this->argv[0] === '--onchange') {
                $this->runChange();
            }
            return;
        }
        echo "[Watcher] PID: {$this->parentPid}" . PHP_EOL;
        register_shutdown_function([$this, 'onShutdown']);

        $this->beforeProcess = new Process([$this, 'onBefore'], true, 2, false);
        $this->beforeProcess->start();
        run(function () {
            go(function () {
                $socket = $this->beforeProcess->exportSocket();
                while (($echo = $socket->recv(65535, -1))) {
                    echo $echo;
                    if (strpos($echo, '[[END]]') !== false) {
                        break;
                    }
                }
                // echo "[Watcher] socket has timed out for 'before' process." . PHP_EOL;
                // echo "[Watcher] socket has been closed for 'before' process." . PHP_EOL;
                // var_dump($socket->errCode);
                // var_dump($socket->errMsg);
            });
        });
        // $this->beforeProcess->wait(false);
        echo "[Watcher] after before" . PHP_EOL;

        // no coroutine here
        $this->runProcess = new Process([$this, 'onRun'], true, 2, false);
        $this->runProcess->start();

        // no coroutine for watcher
        $this->watchProcess = new Process([$this, 'onWatch'], true, 2, false);
        $this->watchProcess->start();
        // pipe echo
        run(function () {
            go(function () {
                while (1) {
                    $socket = $this->runProcess->exportSocket();
                    while (1) {
                        while (($echo = $socket->recv(65535, -1))) {
                            echo $echo;
                        }
                        echo "[Watcher] socket has timed out for run process." . PHP_EOL;
                    }
                    echo "[Watcher] socket has been closed for run process." . PHP_EOL;
                    var_dump($socket->errCode);
                    var_dump($socket->errMsg);
                }
            });
            go(function () {
                $socket = $this->watchProcess->exportSocket();
                while (1) {
                    while (($echo = $socket->recv(65535, -1))) {
                        echo $echo;
                    }
                    echo "[Watcher] socket has timed out for watch process." . PHP_EOL;
                }
                echo "[Watcher] socket has been closed for watch process." . PHP_EOL;
                var_dump($socket->errCode);
                var_dump($socket->errMsg);
            });
        });

        $this->runProcess->wait();
        echo "[Watcher] run process ended." . PHP_EOL;
        $this->watchProcess->wait();
        echo "[Watcher] watch process ended." . PHP_EOL;
    }

    public function onBefore()
    {
        if (isset($this->config['before']) && is_callable($this->config['before'])) {
            $before = $this->config['before'];
            echo "[Watcher] executing 'before' action.." . PHP_EOL;
            ($before)($this->baseDir);
        }
        echo "[Watcher] 'before' action done. [[END]]" . PHP_EOL;
    }

    public function runChange()
    {
        if (isset($this->config['onChange']) && is_callable($this->config['onChange'])) {
            $action = $this->config['onChange'];
            echo "[Watcher] executing 'onChange' action.." . PHP_EOL;
            ($action)($this->baseDir);
        }
    }
    private function watchDirectory(string $dir)
    {
        if (isset($this->config['ignore'])) {
            foreach ($this->config['ignore'] as $pattern) {
                $rootPattern = $this->baseDir . str_replace("@", '\@', $pattern);
                $match = preg_match("@$rootPattern@", $dir);
                if ($match > 0) {
                    // echo "[Watcher] Ignoring $dir $pattern" . PHP_EOL;
                    return;
                }
            }
        }

        $watch_descriptor = inotify_add_watch($this->watchStreamResource, $dir, IN_MODIFY | IN_CREATE | IN_DELETE | IN_MOVE | IN_MOVED_TO);
        $this->watchList[$dir] = $watch_descriptor;
        $this->watchList[$watch_descriptor] = $dir;
        $files = scandir($dir);
        foreach ($files as $key => $value) {
            $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
            if ($value != "." && $value != ".." && is_dir($path)) {
                $this->watchDirectory($path);
            }
        }
    }

    public function onNotify()
    {
        $events = inotify_read($this->watchStreamResource);
        $refresh = false;
        foreach ($events as $event) {
            if (isset($event['name']) && isset($event['wd'])) {
                $wd = $event['wd'];
                $name = $event['name'];
                $path = $this->watchList[$wd] . DIRECTORY_SEPARATOR . $name;

                if (isset($this->config['ignore'])) {
                    $break = false;
                    foreach ($this->config['ignore'] as $pattern) {
                        $rootPattern = $this->baseDir . str_replace("@", '\@', $pattern);
                        $match = preg_match("@$rootPattern@", $path);
                        if ($match > 0) {
                            // echo "[Watcher] Ignoring $path $pattern" . PHP_EOL;
                            $break = true;
                            break;
                        }
                    }
                }
                if ($break) {
                    continue;
                }
                if (is_dir($path)) {
                    $this->watchDirectory($path);
                }
                // print_r($events);
                // print_r($this->config['ignore']);
                echo "[Watcher] Changes detected: $path" . PHP_EOL;
                $refresh = true;
                break;
            }
        }
        if ($refresh && isset($this->config['onChange']) && is_callable($this->config['onChange'])) {
            if ($this->timerId && Swoole\Timer::exists($this->timerId)) {
                Swoole\Timer::clear($this->timerId);
            }
            $this->timerId = Swoole\Timer::after(100, function () {
                $onChange = $this->config['onChange'];
                $this->refreshCount++;
                echo "[Watcher] Refreshing ({$this->refreshCount}).." . PHP_EOL;
                // ($onChange)($this->baseDir);
                $command = "php fluffy watch --onchange";
                // echo "[Watcher] running: $command" . PHP_EOL;
                System($command);
            });
        }
    }

    public function onWatch(Process $worker)
    {
        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);

        run(function () {
            // go(function () {
            $this->watchStreamResource = inotify_init();
            $this->watchDirectory($this->baseDir);
            echo '[Watcher] Watching..' . PHP_EOL;

            Swoole\Event::add($this->watchStreamResource, [$this, 'onNotify']);
            // });
        });

        Process::wait(false);
        echo '[Watcher] exited' . PHP_EOL;
        // inotify_rm_watch($this->watchStreamResource, $watch_descriptor);
        // fclose($this->watchStreamResource);
    }

    public function onRun(Process $worker)
    {
        if (isset($this->config['onStart']) && is_callable($this->config['onStart'])) {
            $onStart = $this->config['onStart'];
            echo "[Watcher] executing onStart.." . PHP_EOL;
            ($onStart)($this->baseDir);
            echo "[Watcher] onStart complete." . PHP_EOL;
        }
    }

    private function onShutdown()
    {
        $currentPid = getmypid();
        if ($currentPid === $this->parentPid) {
            echo '[Watcher] Shutting down parent..' . PHP_EOL;

            if (isset($this->watchProcess)) {
                echo '[Watcher] Shutting down watcher..' . PHP_EOL;
                $this->watchProcess->kill($this->watchProcess->pid);
            }
            if (isset($this->runProcess)) {
                echo '[Watcher] Shutting down runner..' . PHP_EOL;
                $this->runProcess->kill($this->runProcess->pid);
            }
        }
    }
}
