<?php

namespace Fluffy\Swoole\RateLimit;

use Fluffy\Data\Connector\RedisConnector;

class RedisRateLimitService implements IRateLimitService
{
    public function __construct(private RedisConnector $redisConnector) {}

    public function limit(string $key, int $max, int $lifetime): bool
    {
        $redisKey = "RL:$key";
        $redis = $this->redisConnector->get();
        $final = $redis->incr($redisKey);
        // print_r([$key, $final]);
        if ($final === 1) {
            $redis->expire($redisKey, $lifetime);
        }
        // TODO: test overflow
        if ($final > $max) {
            return false;
        }
        return true;
    }
}
