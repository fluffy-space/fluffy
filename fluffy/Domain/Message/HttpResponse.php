<?php

namespace Fluffy\Domain\Message;

abstract class HttpResponse
{
    public int $status = 200;
    public string $body = '';
    public array $headers = [];
    public $rawData = null;
    /**
     * A file on disk to send as the body instead of $body (ResponseBuilder::sendFile). The server
     * streams it, so its bytes never enter PHP. The transfer outlives the request: never delete
     * the file in the same request.
     */
    public ?string $filePath = null;
    /**
     * output the response headers/content, etc
     * @return mixed 
     */
    public abstract function end();
    public abstract function setCookie(string $key, string $value = '', int $expire = 0, string $path = '/', string $domain  = '', bool $secure = false, bool $httpOnly = false, string $sameSite = '', string $priority = ''): bool;
    
    public function getRawResponseData()
    {
        return $this->rawData ?? $this->body;
    }
}