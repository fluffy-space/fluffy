<?php

namespace Fluffy\Swoole\Task;

/**
 * Log output for cron tasks that run far more often than anyone wants to read about.
 *
 * A job on a ten-second schedule that reports what it did produces six lines a minute — and on a
 * blue/green box, from two instances. That is not a log anybody reads, which means a line that
 * mattered would not be read either. `summary()` keeps the count and prints ONE line per hour
 * instead, so the information survives and the volume does not.
 *
 * Counters are per worker process (static, and Swoole workers do not share memory), so a busy box
 * can print a handful of these an hour rather than exactly one. That is deliberate: the alternative
 * is shared state in a Swoole\Table for something whose only purpose is to be readable.
 *
 * NOT for failures. Anything that went wrong should be logged as it happens — see
 * TaskManager::processMessage, which logs every failed cron run whatever its schedule.
 */
class TaskLog
{
    /** @var array<string, array{total: int, ticks: int, lastLog: int}> */
    private static array $counters = [];

    /**
     * Add to a running total, and print a summary at most once every $everySec.
     *
     * The FIRST call prints immediately and starts the window — otherwise a freshly deployed box
     * would say nothing for an hour, which is exactly when someone is watching to see the job work.
     *
     * @param string $key      log prefix and counter identity, e.g. 'DrainClickQueueTask'
     * @param int    $amount   how much this run did; zero or less is not worth counting or printing
     * @param callable $message fn(int $total, int $ticks): string — the line, given the totals
     */
    public static function summary(string $key, int $amount, callable $message, int $everySec = 3600): void
    {
        if ($amount <= 0) {
            return;
        }
        $state = self::$counters[$key] ?? ['total' => 0, 'ticks' => 0, 'lastLog' => 0];
        $state['total'] += $amount;
        $state['ticks']++;

        $now = time();
        if ($state['lastLog'] === 0 || $now - $state['lastLog'] >= $everySec) {
            echo "[$key] " . date('Y-m-d H:i:s', $now) . ' ' . $message($state['total'], $state['ticks']) . PHP_EOL;
            $state = ['total' => 0, 'ticks' => 0, 'lastLog' => $now];
        }
        self::$counters[$key] = $state;
    }
}
