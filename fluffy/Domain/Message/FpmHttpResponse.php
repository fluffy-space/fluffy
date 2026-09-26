<?php

namespace Fluffy\Domain\Message;

class FpmHttpResponse extends HttpResponse
{

    public function setCookie(string $key, string $value = '', int $expire = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httpOnly = false, string $sameSite = '', string $priority = ''): bool
    {
        return true;
    }

    public function end()
    {
        http_response_code($this->status);
        foreach ($this->headers as $key => $value) {
            header("$key: $value");
        }
        if ($this->filePath !== null) {
            header('Content-Length: ' . filesize($this->filePath));
            readfile($this->filePath);
            return;
        }
        echo $this->body;
    }
}
