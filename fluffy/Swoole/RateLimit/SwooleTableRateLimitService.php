<?php

namespace Fluffy\Swoole\RateLimit;

use Fluffy\Swoole\Task\TaskManager;

class SwooleTableRateLimitService implements IRateLimitService
{
    public function __construct(private \AppServer $appServer, private TaskManager $taskManager)
    {
    }

    public function limit(string $key, int $max, int $lifetime): bool
    {
        $final = $this->appServer->timeTable->incr($key, 'value');
        // print_r([$key, $final]);
        if ($final === 1) {
            $expireSec = time() + $lifetime;
            $this->appServer->timeTable->incr($key, 'time', $expireSec);
            $this->taskManager->setLimitTimer($key, $expireSec);
        }
        if ($final > $max) {
            // do not allow overflow
            $this->appServer->timeTable->set($key, ['value' => $max + 1]);
            return false;
        }
        return true;
    }
}
