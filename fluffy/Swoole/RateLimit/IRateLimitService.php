<?php

namespace Fluffy\Swoole\RateLimit;

interface IRateLimitService
{
    function limit(string $key, int $max, int $lifetime): bool;

    /**
     * Current hit count for $key in the open window, without counting a hit.
     * 0 when no window is open. Lets callers report remaining quota (e.g.
     * X-RateLimit-Remaining) after calling limit().
     */
    function peek(string $key): int;
}
