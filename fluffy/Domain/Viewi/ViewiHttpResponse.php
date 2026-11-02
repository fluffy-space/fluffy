<?php

namespace Fluffy\Domain\Viewi;

use Fluffy\Domain\Message\HttpResponse;

class ViewiHttpResponse extends HttpResponse
{

    public function end()
    {
    }

    public function canSetCookies(): bool
    {
        return false;
    }

    public function setCookie(string $key, string $value = '', int $expire = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httpOnly = false, string $sameSite = '', string $priority = ''): bool
    {
        return true;
    }
}
