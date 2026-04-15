<?php

namespace Fluffy\Domain\Message;

abstract class HttpRequest
{
    public function __construct(
        public string $method,
        public string $uri,
        public array $headers = array(),
        public array $query = array(),
        public array $server = array()
    ) {
    }

    public abstract function getBody();
    public abstract function getCookie(?string $name = null);
    public abstract function getHeader(?string $name = null);
    public function getIp()
    {
        return $this->headers['x-real-ip'] ?? $this->server['remote_addr'] ?? '127.0.0.1';
    }
}
