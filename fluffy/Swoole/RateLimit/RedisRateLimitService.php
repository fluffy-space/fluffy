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
        $final = $redis->incr($redisKey); // to test overflow , 9223372036854775807
        // print_r([$key, $final]);
        if ($final === 1) {
            $redis->expire($redisKey, $lifetime);
        }
        // $final is false on overflow
        if (!$final || $final > $max) {
            return false;
        }
        return true;
    }
}
