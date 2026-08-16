<?php

namespace Fluffy\Swoole\Database;

/**
 * Gives a Swoole connection pool a way to forget a connection WITHOUT opening its replacement
 * on the spot.
 *
 * `ConnectionPool::put(null)` - the documented way to report a dead connection - decrements the
 * counter and immediately calls `make()`. That is the wrong moment to open a connection: the
 * caller is throwing one away precisely because the server just misbehaved, every worker tends
 * to reach that point at the same instant, and `make()` throws when the server is unreachable,
 * so the failure surfaces during request disposal. Releasing the slot instead leaves the pool
 * one under its size, and the next `get()` opens a connection when something actually needs one.
 *
 * `$num` and `$size` are protected on Swoole\ConnectionPool, so this only touches its own state.
 */
trait ReleasesPoolSlots
{
    /**
     * Give the slot back without reconnecting. Safe to call on a closed pool.
     */
    public function release(): void
    {
        if ($this->pool === null) {
            return;
        }
        if ($this->num > 0) {
            $this->num--;
        }
    }
}
