<?php

namespace Fluffy\Swoole\RateLimit;

use Fluffy\Data\Connector\RedisConnector;

class RedisRateLimitService implements IRateLimitService
{
    public function __construct(private RedisConnector $redisConnector) {}

    /**
     * Callers key buckets by client IP ("unlock:1.2.3.4"). The key is hashed before it reaches
     * Redis so no IP address is ever stored there, not even for the bucket's lifetime. Every method
     * goes through here, so limit/peek/reset keep addressing the same bucket.
     */
    private function redisKey(string $key): string
    {
        return 'RL:' . hash('sha256', $key);
    }

    public function limit(string $key, int $max, int $lifetime): bool
    {
        $redisKey = $this->redisKey($key);
        $redis = $this->redisConnector->get();
        $final = $redis->incr($redisKey); // to test overflow , 9223372036854775807
        // print_r([$key, $final]);
        // Also when the key has no TTL (-1): a crash between INCR and EXPIRE would otherwise leave the
        // bucket full forever. The next hit heals it.
        if ($final === 1 || $redis->ttl($redisKey) === -1) {
            $redis->expire($redisKey, $lifetime);
        }
        // $final is false on overflow
        if (!$final || $final > $max) {
            return false;
        }
        return true;
    }

    public function reset(string $key): void
    {
        $this->redisConnector->get()->del($this->redisKey($key));
    }

    public function peek(string $key): int
    {
        $value = $this->redisConnector->get()->get($this->redisKey($key));
        return ($value === false || $value === null) ? 0 : (int) $value;
    }
}
