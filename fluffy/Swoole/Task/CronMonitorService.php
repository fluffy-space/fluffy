<?php

namespace Fluffy\Swoole\Task;

use AppServer;

/**
 * Read/control surface over the cron Swoole tables for admin monitoring.
 *
 * Because the tables are Swoole\Table (shared memory created before server start), this works from
 * ANY worker: a request worker reads the status the cron worker wrote, and a pause flag written here
 * is seen by the cron worker on its next tick — no IPC needed.
 */
class CronMonitorService
{
    /** Heartbeat older than this (seconds) → the cron loop is considered stale/dead. */
    private const HEARTBEAT_STALE_SEC = 15;
    /** A job stuck "running" longer than this (seconds) is flagged for attention. */
    private const RUNNING_STUCK_SEC = 300;

    public function __construct(private AppServer $appServer) {}

    /** All known cron jobs with their live status + derived flags, sorted by name. */
    public function list(): array
    {
        $now = time();
        $jobs = [];
        foreach ($this->appServer->cronStatusTable as $id => $row) {
            $isRunning = (int)$row['isRunning'] === 1;
            $jobs[] = [
                'id'             => $id,
                'name'           => $row['name'],
                'schedule'       => $row['schedule'],
                'isRunning'      => $isRunning,
                'lastStart'      => (int)$row['lastStart'],
                'lastFinish'     => (int)$row['lastFinish'],
                'lastDurationMs' => (int)$row['lastDurationMs'],
                'lastOk'         => (int)$row['lastOk'], // 1 ok / 0 failed / -1 never run
                'lastError'      => $row['lastError'],
                'lastFailure'    => (int)$row['lastFailure'],
                'runCount'       => (int)$row['runCount'],
                'failCount'      => (int)$row['failCount'],
                'nextRun'        => (int)$row['nextRun'],
                'isStuck'        => $isRunning && (int)$row['lastStart'] > 0 && ($now - (int)$row['lastStart']) > self::RUNNING_STUCK_SEC,
            ];
        }
        usort($jobs, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $jobs;
    }

    /** Global pause + cron-loop liveness. */
    public function health(): array
    {
        $now = time();
        $control = $this->appServer->cronControlTable->get('global');
        $paused = $control ? (int)$control['paused'] === 1 : false;
        $heartbeat = $control ? (int)$control['heartbeat'] : 0;
        return [
            'paused'    => $paused,
            'heartbeat' => $heartbeat,
            'stale'     => $heartbeat === 0 || ($now - $heartbeat) > self::HEARTBEAT_STALE_SEC,
            'updatedBy' => $control ? (string)$control['updatedBy'] : '',
        ];
    }

    public function pauseAll(string $by = ''): void
    {
        $this->appServer->cronControlTable->set('global', ['paused' => 1, 'updatedBy' => substr($by, 0, 63)]);
    }

    public function resumeAll(string $by = ''): void
    {
        $this->appServer->cronControlTable->set('global', ['paused' => 0, 'updatedBy' => substr($by, 0, 63)]);
    }
}
