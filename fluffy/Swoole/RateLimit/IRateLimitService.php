<?php

namespace Fluffy\Swoole\RateLimit;

interface IRateLimitService
{
    function limit(string $key, int $max, int $lifetime): bool;
}
