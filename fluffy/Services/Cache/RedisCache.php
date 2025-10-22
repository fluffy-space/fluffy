<?php

namespace Fluffy\Services\Cache;

use Fluffy\Data\Connector\RedisConnector;

class RedisCache
{
    public function __construct(private RedisConnector $connector) {}

    public function get(string $key): mixed
    {
        /** it's StdClass!!
         * @var RedisCacheItem|null
         */
        $cacheItem = $this->getRaw($key);
        if ($cacheItem) {
            return $cacheItem->data;
        }
        return null;
    }

    /**
     * Returns StdClass!!
     * @param string $key 
     * @return RedisCacheItem|null 
     */
    public function getRaw(string $key): mixed
    {
        $redisKey = "CH:$key";
        $jsonOrNull = $this->connector->get()->get($redisKey);
        if ($jsonOrNull) {
            /**
             * @var RedisCacheItem|null
             */
            $cacheItem = json_decode($jsonOrNull, false);
            return $cacheItem;
        }
        return null;
    }

    public function set(string $key, $data): bool
    {
        $redisKey = "CH:$key";
        $cacheItem = new RedisCacheItem($data);
        return $this->connector->get()->set($redisKey, json_encode($cacheItem));
    }
}
