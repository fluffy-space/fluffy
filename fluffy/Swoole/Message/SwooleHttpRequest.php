<?php

namespace Fluffy\Swoole\Message;

use Fluffy\Domain\Message\HttpRequest;

class SwooleHttpRequest extends HttpRequest
{
    public function __construct(
        private \Swoole\Http\Request $swooleRequest,
        string $method,
        string $uri,
        array $headers = array(),
        array $query = array(),
        array $server = array(),
    ) {
        // $server was dropped here, leaving HttpRequest::$server empty: getIp() then read no
        // remote_addr, defaulted to 127.0.0.1 and trusted X-Real-IP from any peer. Pass it on.
        parent::__construct($method, $uri, $headers, $query, $server);
    }

    /** The raw query string exactly as sent (no leading '?'); '' when there is none. */
    public function getQueryString(): string
    {
        return (string) ($this->swooleRequest->server['query_string'] ?? '');
    }

    public function getCookie(?string $name = null): mixed
    {
        // var_dump($this->swooleRequest->cookie);
        return $name ? $this->swooleRequest->cookie[$name] ?? null : $this->swooleRequest->cookie;
    }

    public function getBody()
    {
        return $this->swooleRequest->getContent();
    }

    public function getHeader(?string $name = null)
    {
        // print_r($this->swooleRequest->header);
        return $name ? $this->swooleRequest->header[strtolower($name)] ?? null : $this->swooleRequest->header;
    }
}
