<?php

namespace Fluffy\Swoole\Message;

use Fluffy\Domain\Message\HttpResponse;

class SwooleHttpResponse extends HttpResponse
{
    public function __construct(private \Swoole\Http\Response $swooleResponse)
    {
    }

    public function setCookie(string $key, string $value = '', int $expire = 0, string $path = '/', string $domain  = '', bool $secure = false, bool $httpOnly = false, string $sameSite = '', string $priority = ''): bool
    {
        return $this->swooleResponse->cookie($key, $value, $expire, $path, $domain, $secure, $httpOnly, $sameSite, $priority);
    }

    public function end()
    {
        $this->swooleResponse->status($this->status);
        foreach ($this->headers as $key => $value) {
            $this->swooleResponse->header($key, $value);
        }
        if ($this->filePath !== null) {
            // Only the path crosses to the reactor thread, which sendfile(2)s it as the socket
            // drains. The router has already checked the file exists and the request is HTTP/1.1.
            $this->swooleResponse->sendfile($this->filePath);
            return;
        }
        $this->swooleResponse->end($this->body);
    }
}
