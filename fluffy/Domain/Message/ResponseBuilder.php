<?php

namespace Fluffy\Domain\Message;

class ResponseBuilder
{
    public array $headers = [];
    public int $statusCode = 200;
    public string $statusText = '';
    public $body = '';
    public bool $stringify = false;
    public ?string $filePath = null;

    static function json($data)
    {
        $response = new ResponseBuilder();
        $response->body = $data;
        $response->stringify = true;
        $response->headers['Content-type'] = 'application/json; charset=utf-8';
        return $response;
    }

    static function html($data)
    {
        $response = new ResponseBuilder();
        $response->body = $data;
        $response->headers['Content-type'] = 'text/html; charset=utf-8';
        return $response;
    }

    static function xml($data)
    {
        $response = new ResponseBuilder();
        $response->body = $data;
        $response->headers['Content-type'] = 'application/xml';
        return $response;
    }

    static function text($data)
    {
        $response = new ResponseBuilder();
        $response->body = $data;
        $response->headers['Content-type'] = 'text/plain; charset=utf-8';
        return $response;
    }

    static function file($data, $mimeType = null, $contentDisposition = null)
    {
        $response = new ResponseBuilder();
        $response->body = $data;
        if ($mimeType !== null) {
            $response->headers['Content-type'] = $mimeType;
        }
        if($contentDisposition !== null)
        {
            $response->headers['Content-Disposition'] = $contentDisposition;
        }
        return $response;
    }

    /**
     * A file on disk as the response, sent by the server with sendfile(2): the bytes go from the
     * kernel's page cache to the socket 64 KB at a time and never enter PHP, so a 5 GB file costs a
     * worker what a 5 KB one does, and the worker is free before the download ends. Use it for
     * anything large; `file()` holds the whole body in memory, twice.
     *
     * $downloadName makes it an attachment with that name. The file must outlive the transfer, so
     * never delete it in the same request.
     */
    static function sendFile(string $path, string $mimeType, ?string $downloadName = null)
    {
        $response = new ResponseBuilder();
        $response->filePath = $path;
        $response->headers['Content-type'] = $mimeType;
        if ($downloadName !== null) {
            $response->headers['Content-Disposition'] = self::attachment($downloadName);
        }
        // nginx buffers a proxied response, and spools whatever exceeds its buffers to its own
        // temp dir before sending it: the file would be written to disk a second time. This turns
        // that off for this response alone, with no nginx config to ship.
        $response->headers['X-Accel-Buffering'] = 'no';
        return $response;
    }

    /**
     * Content-Disposition for a download (RFC 6266): an ASCII `filename` every client reads, and
     * `filename*` with the real UTF-8 name for the ones that understand it.
     */
    static function attachment(string $name): string
    {
        // One "_" per character, not per byte (/u); a name that is not valid UTF-8 gets a plain one.
        $ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/u', '_', $name) ?? 'download';
        return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }

    function withCode(int $code)
    {
        $this->statusCode = $code;
        return $this;
    }

    function withHeaders(array $headers)
    {
        $this->headers += $headers;
        return $this;
    }

    function withBody($data)
    {
        $this->body = $data;
        return $this;
    }

    function asJson()
    {
        $this->stringify = true;
        $this->headers['Content-type'] = 'application/json; charset=utf-8';
        return $this;
    }

    function asPlainText()
    {
        $this->stringify = false;
        $this->headers['Content-type'] = 'text/plain; charset=utf-8';
        return $this;
    }

    function asHtml()
    {
        $this->stringify = false;
        $this->headers['Content-type'] = 'text/html; charset=utf-8';
        return $this;
    }

    function withBodyType(string $bodyType)
    {
        $this->headers['Content-type'] = $bodyType;
        return $this;
    }
}