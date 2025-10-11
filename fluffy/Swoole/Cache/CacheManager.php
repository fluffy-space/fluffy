<?php

namespace Fluffy\Swoole\Cache;

use Swoole\ConnectionPool;
use Swoole\Coroutine\Barrier;
use Swoole\Coroutine\Channel;

class CacheManager
{
    /**
     * 
     * @var CacheItem[]
     */
    private array $cacheItems = [];
    /**
     * 
     * @var Channel[]
     */
    private array $channels = [];

    public function __construct(private \AppServer $appServer)
    {
        $this->appServer->channel->push(1);
        // echo '[CacheManager] Channel ready ' . $this->appServer->server->getWorkerId() . ' ' . $this->appServer->uniqueId . ' ' .  PHP_EOL;
    }

    public function get(string $key): mixed
    {
        if (!isset($this->cacheItems[$key])) {
            return null;
        }

        $syncRow = $this->appServer->syncTable->get($key);
        if ($syncRow !== false && $syncRow['value'] !== $this->cacheItems[$key]->syncKey) {
            // outdated, remove from cache
            unset($this->cacheItems[$key]);
            return null;
        }

        return $this->cacheItems[$key]->data;
    }

    public function clearAll()
    {
        foreach ($this->appServer->syncTable as $key => $row) {
            // print_r([$key, $row]);
            $this->appServer->syncTable->incr($key, 'value');
        }
    }

    public function delete(string $key)
    {
        if ($this->appServer->syncTable->exists($key)) {
            $this->appServer->syncTable->incr($key, 'value');
        }
    }

    public function set(string $key, callable $action): mixed
    {
        $this->appServer->channel->pop();
        if (!isset($this->channels[$key])) {
            $this->channels[$key] = new Channel(1);
            $this->channels[$key]->push(1);
        }
        $this->appServer->channel->push(1);
        // print_r("Channel {$this->appServer->uniqueId} locked\n");
        $this->channels[$key]->pop();
        try {
            // check the data again
            $data = $this->get($key);
            if ($data !== null) {
                return $data;
            }
            // print_r("Setting up cache item {$this->appServer->uniqueId} $key\n");
            $syncKey = 0;
            $syncRow = $this->appServer->syncTable->get($key);
            if ($syncRow !== false) {
                $syncKey = $syncRow['value'];
            } else {
                $this->appServer->syncTable->set($key, ['value' => $syncKey]);
            }
            // echo '[CacheManager] Set cache item Sync: ' . $syncKey . ' ' . $this->appServer->server->getWorkerId() . ' ' . $this->appServer->uniqueId . ' ' . $key . PHP_EOL;
            // var_dump($this->appServer->syncTable->get($key));
            $data = $action();
            $this->cacheItems[$key] = new CacheItem($syncKey, $data);
            // print_r($this->cacheItems);
            // print_r("Cached {$this->appServer->uniqueId} $syncKey\n");
            return $data;
        } finally {
            // print_r("Channel {$this->appServer->uniqueId} released\n");
            $this->channels[$key]->push(1);
        }
    }
}
