<?php

namespace Fluffy\Swoole\Lock;

use Fluffy\Data\Connector\RedisConnector;
use Swoole\Coroutine;

class RedisLockService implements ILockService
{
    private const RETRY_SECONDS = 0.02;

    // Compare-and-delete in one step, so a holder whose lock expired cannot delete the next one's.
    private const RELEASE_SCRIPT = <<<'LUA'
    if redis.call('get', KEYS[1]) == ARGV[1] then
        return redis.call('del', KEYS[1])
    end
    return 0
    LUA;

    public function __construct(private RedisConnector $redisConnector) {}

    /** Keys may carry user text (a folder name); hashing bounds their length. */
    private function redisKey(string $key): string
    {
        return 'LK:' . hash('sha256', $key);
    }

    public function acquire(string $key, int $ttlSeconds, float $waitSeconds = 0): ?string
    {
        $token = bin2hex(random_bytes(16));
        $redisKey = $this->redisKey($key);
        $redis = $this->redisConnector->get();
        $deadline = microtime(true) + $waitSeconds;
        do {
            // SET NX EX takes the lock and sets its expiry atomically: no key is ever left without a TTL.
            if ($redis->set($redisKey, $token, ['nx', 'ex' => $ttlSeconds])) {
                return $token;
            }
            if (microtime(true) >= $deadline) {
                return null;
            }
            // Yields the coroutine, so a waiting request does not block its worker.
            Coroutine::sleep(self::RETRY_SECONDS);
        } while (true);
    }

    public function release(string $key, string $token): void
    {
        $this->redisConnector->get()->eval(self::RELEASE_SCRIPT, [$this->redisKey($key), $token], 1);
    }
}
