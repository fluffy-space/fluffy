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

    /**
     * Forget $key's open window, as if it had never been hit.
     *
     * For TESTS: a limiter is shared process state, so a suite that exercises a limited endpoint
     * poisons every later suite (and its own next run) for the rest of the window. Exposed through
     * the dev-only test facility, never on a production build.
     */
    function reset(string $key): void;
}
