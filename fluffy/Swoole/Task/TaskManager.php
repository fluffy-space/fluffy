<?php

namespace Fluffy\Swoole\Task;

use Fluffy\Domain\CronTab\CronTab;
use Fluffy\Domain\CronTab\CronTabItem;
use Fluffy\Domain\CronTab\TimerInfo;
use Fluffy\Services\UtilsService;
use Closure;
use ReflectionFunction;
use Swoole\Event;
use Swoole\Timer;
use Swoole\WebSocket\Server;

use function Co\go;

class TaskManager
{
    private int $workerId;
    private string $uniqueId;
    private array $timers = [];
    /**
     * 
     * @var int|false[]
     */
    private array $lates = [];
    /**
     * 
     * @var int[]
     */
    private array $lastRuns = [];

    public function __construct(private \AppServer $appServer)
    {
        $this->workerId = $appServer->server->worker_id;
        $this->uniqueId = $appServer->uniqueId;
    }

    public function dispatch(Closure $action, ...$params)
    {
        $refl = new ReflectionFunction($action);
        $this->appServer->server->task([$refl->getClosureCalledClass()->getName(), $refl->getName(), $params]);
    }

    public function dispatchArray(array $action, ...$params)
    {
        [$class, $method] = $action;
        $this->appServer->server->task([$class, $method, $params]);
    }

    public function dispatchArrayAndRepeat(array $action, ...$params)
    {
        [$class, $method] = $action;
        $this->appServer->server->task([$class, $method, $params, true]);
    }

    public function dispatchArrayCallback(array $action, callable $onFinish, ...$params)
    {
        [$class, $method] = $action;
        $this->appServer->server->task([$class, $method, $params]);
    }

    public function dispatchArrayAndWait(array $action, ...$params)
    {
        [$class, $method] = $action;
        return $this->appServer->server->taskwait([$class, $method, $params], 10);
    }

    public function dispatchAfter(array $action, $params)
    {
        Timer::after(1000, function () use ($action, $params) {
            $this->dispatchArray($action, ...$params);
        });
    }

    public function scheduleCronJob(CronTabItem $crontabItem, bool $runImmediately = false)
    {
        if ($this->appServer->stopped) {
            return;
        }

        $jobKey = $this->appServer->uniqueId . '|' . implode('|', $crontabItem->task);

        // Seed the monitoring row once so every job shows up (even before its first run).
        $statusKey = $crontabItem->id();
        if (!$this->appServer->cronStatusTable->exists($statusKey)) {
            $this->appServer->cronStatusTable->set($statusKey, [
                'name' => substr($statusKey, 0, 159),
                'schedule' => substr($crontabItem->schedule, 0, 63),
                'lastOk' => -1,
            ]);
        }

        $currentRunTimestamp = time();
        $nextRunTimestamp = CronTab::getNextRun($crontabItem->schedule, $currentRunTimestamp);

        /** @var int|false $missedRunTime */
        $missedRunTime = $this->lates[$jobKey] ?? false;

        $crontabItem->currentRunTimerInfo = new TimerInfo($crontabItem->schedule, !!$missedRunTime, $this->lastRuns[$jobKey] ?? null, $currentRunTimestamp, $nextRunTimestamp, $missedRunTime ? $missedRunTime : null);

        if ($runImmediately) {
            $this->onCronTimer($crontabItem);
            // print_r($crontabItem->currentRunTimerInfo);
            return;
        }
        $currentRunTimestamp = CronTab::getNextRun($crontabItem->schedule);
        $nextRunTimestamp = CronTab::getNextRun($crontabItem->schedule, $currentRunTimestamp);
        $nextRun = ($currentRunTimestamp - time()) * 1000;
        $crontabItem->currentRunTimerInfo->currentRun = $currentRunTimestamp;
        $crontabItem->currentRunTimerInfo->nextRun = $nextRunTimestamp;
        $this->appServer->cronStatusTable->set($statusKey, ['nextRun' => $currentRunTimestamp]);
        // print_r($crontabItem->currentRunTimerInfo);
        // echo '[TM scheduleCronJob] runCronJob scheduled. ' . ($nextRun / 1000) . PHP_EOL;
        $timerId = Timer::after($nextRun, function () use ($crontabItem) {
            $this->onCronTimer($crontabItem);
        });
        $this->timers[$jobKey] = $timerId;
    }

    function onCronTimer(CronTabItem $crontabItem)
    {
        $taskMessage = new TaskMessage($this->appServer->uniqueId, $crontabItem);
        $jobKey = $this->appServer->uniqueId . '|' . implode('|', $crontabItem->task);

        // Global admin pause: skip dispatch but keep the timer cycle going so jobs resume cleanly
        // on the next tick after resume — no server restart needed.
        $control = $this->appServer->cronControlTable->get('global');
        if ($control && $control['paused'] === 1) {
            $this->scheduleCronJob($crontabItem);
            return;
        }

        $statusKey = $crontabItem->id();
        $jobRow = $this->appServer->crontabTable->get($jobKey);
        if (!$jobRow || $jobRow['isRunning'] === 0) {
            $this->appServer->crontabTable->set($jobKey, ['isRunning' => 1]);
            $this->appServer->cronStatusTable->set($statusKey, ['isRunning' => 1, 'lastStart' => time()]);
            $this->lates[$jobKey] = false;
            $this->lastRuns[$jobKey] = time();
            // echo "[TaskManager] $jobKey runCronJob dispatch." . PHP_EOL;
            go(function () use ($taskMessage) {
                $this->sendToWorker($taskMessage);
            });
        } else {
            $this->lates[$jobKey] = time();
        }
        // echo '[TaskManager] runCronJob next.' . PHP_EOL;
        $this->scheduleCronJob($crontabItem);
    }

    public function processMessage(TaskMessage $message)
    {
        // across multiple workers
        if ($message->data instanceof CronTabItem) {
            [$class, $method] = $message->data->task;
            $jobKey = $message->dispatcherUID . '|' . implode('|', $message->data->task);
            $statusKey = $message->data->id();
            $timestamp = time();
            $time = date('Y-m-d H:i:s', $timestamp);
            echo "[Crontab] +$time $jobKey starting on worker {$this->workerId} [{$this->uniqueId}]" . PHP_EOL;
            $startMs = (int)(microtime(true) * 1000);
            $result = $this->appServer->app->runTask($class, $method, [...$message->data->params, $message->data->currentRunTimerInfo]);
            $durationMs = (int)(microtime(true) * 1000) - $startMs;
            $finishTs = time();
            // $this->appServer->crontabTable->delete($jobKey);
            $this->appServer->crontabTable->set($jobKey, ['lastRun' => $finishTs, 'isRunning' => 0]);

            // Monitoring: record finish + outcome (failures are no longer swallowed).
            $this->appServer->cronStatusTable->set($statusKey, [
                'isRunning' => 0,
                'lastFinish' => $finishTs,
                'lastDurationMs' => $durationMs,
                'lastOk' => $result['ok'] ? 1 : 0,
            ]);
            $this->appServer->cronStatusTable->incr($statusKey, 'runCount');
            if ($result['ok']) {
                $this->appServer->cronStatusTable->set($statusKey, ['lastError' => '']);
            } else {
                $this->appServer->cronStatusTable->set($statusKey, [
                    'lastError' => substr((string)$result['error'], 0, 255),
                    'lastFailure' => $finishTs,
                ]);
                $this->appServer->cronStatusTable->incr($statusKey, 'failCount');
            }
            $time = date('Y-m-d H:i:s', time());
            echo "[Crontab] -$time $jobKey finished on worker {$this->workerId} [{$this->uniqueId}]." . PHP_EOL;
            $this->appServer->iteration->sub();
            $this->sendToWorker(new TaskMessage($this->uniqueId, [TaskManager::class, 'onCronJobFinish', [$message->data]]), $message->workerId);
        } elseif ($message->data instanceof TimerTask) {
            Timer::after(($message->data->expireAtSec - time()) * 1000, function () use ($message) {
                // echo "[RateLimit] resetting limit for key {$message->data->key} at {$this->workerId} [{$this->uniqueId}]." . PHP_EOL;
                $this->appServer->timeTable->del($message->data->key);
            });
        } else {
            [$class, $method, $params] = $message->data;
            $this->appServer->app->task($class, $method, $params);
        }
    }

    public function sendToWorker(TaskMessage $message, ?int $workerId = null)
    {
        // can't send messages to self
        $nextWorker = $workerId;
        if ($nextWorker === null) {
            $iteration = $this->appServer->iteration->add();
            if ($iteration < 0 || $iteration > 100000000) {
                $this->appServer->iteration->set(1);
            }
            $nextWorker = $this->appServer->requestWorkersCount + $iteration % $this->appServer->taskWorkersCount;
        }
        $message->workerId = $this->appServer->server->worker_id;
        if ($nextWorker !== $this->appServer->server->worker_id) {
            $this->appServer->server->sendMessage($message, $nextWorker);
        } else {
            $this->processMessage($message);
        }
    }

    public function setLimitTimer(string $key, int $expireAtSec)
    {
        $this->sendToWorker(new TaskMessage($this->uniqueId, new TimerTask($key, $expireAtSec)), $this->appServer->timerWorkerId);
    }

    public function onCronJobFinish(CronTabItem $crontabItem)
    {
        // reset the timer, check if job has missed the run and run with IsPastDue=true
        $jobKey = $this->appServer->uniqueId . '|' . implode('|', $crontabItem->task);
        $jobRow = $this->appServer->crontabTable->get($jobKey);
        // print_r([$jobKey, $this->lates]);
        if (isset($this->lates[$jobKey]) && $this->lates[$jobKey]) {
            // the job is running late, cancel timer and run immediately
            $timerId = $this->timers[$jobKey];
            if (Timer::exists($timerId)) {
                Timer::clear($timerId);
            }
            $time = date('Y-m-d H:i:s', time());
            echo "[Crontab:LATE] $time running late job $jobKey [{$this->uniqueId}]." . PHP_EOL;
            $this->scheduleCronJob($crontabItem, true);
        }
    }

    public function resetCronTable()
    {
        $keys = [];
        foreach ($this->appServer->crontabTable as $key => $_) {
            $keys[] = $key;
        }
        foreach ($keys as $key) {
            $this->appServer->crontabTable->del($key);
        }
        // Clear stuck "running" flags left by a crashed/reloaded cron worker; keep counters/history.
        // cronControlTable (pause flag) is intentionally NOT touched so an admin pause survives reloads.
        foreach ($this->appServer->cronStatusTable as $statusKey => $_) {
            $this->appServer->cronStatusTable->set($statusKey, ['isRunning' => 0]);
        }
        $this->appServer->iteration->set(0);
    }

    public function sendMessage($message, $worker_id)
    {
        $this->appServer->server->sendMessage($message, $worker_id);
    }
}
