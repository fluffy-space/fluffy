<?php

namespace Fluffy\Swoole\Lock;

/**
 * A short mutual-exclusion lock shared by every worker and server (blue and green alike).
 * Not a rate limiter: "only one at a time", with an expiry so a crashed holder frees it.
 */
interface ILockService
{
    /**
     * Take the lock on $key for at most $ttlSeconds. With $waitSeconds > 0, wait up to that long for
     * the current holder to finish (a hold) instead of giving up at once (a try-lock).
     * @return string|null a token for release(), or null when someone else still holds the lock
     */
    function acquire(string $key, int $ttlSeconds, float $waitSeconds = 0): ?string;

    /** Release the lock, but only if $token still holds it (it may have expired and moved on). */
    function release(string $key, string $token): void;
}
