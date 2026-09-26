<?php

namespace Fluffy\Controllers;

use Fluffy\Domain\Message\ResponseBuilder;

class BaseController
{
    /**
     * Largest page a list endpoint will serve when the caller does not say otherwise.
     * `size` reaches an action straight off the query string, so without a ceiling `?size=1000000`
     * is a LIMIT 1000000 plus a model per row — one request holding a worker and its memory.
     */
    public const MAX_PAGE_SIZE = 100;

    /** A caller-supplied page size, bounded to [1, $max]. */
    protected static function pageSize(int $size, int $max = self::MAX_PAGE_SIZE): int
    {
        return max(1, min($max, $size));
    }

    /** A caller-supplied page number, bounded to >= 1 (page 0 / negatives skew the OFFSET). */
    protected static function pageNumber(int $page): int
    {
        return max(1, $page);
    }

    public function Unauthorized(?string $message = null)
    {
        return ResponseBuilder::Json([
            "message" => $message ?? "Unauthorized"
        ])->WithCode(401);
    }

    public function Forbidden(?string $message = null)
    {
        return ResponseBuilder::Json([
            "message" => $message ?? "Forbidden"
        ])->WithCode(403);
    }

    public function NotFound()
    {
        return ResponseBuilder::Json([
            "message" => "Not Found"
        ])->WithCode(404);
    }

    public function Conflict()
    {
        return ResponseBuilder::Json([
            "message" => "Not Found"
        ])->WithCode(409);
    }

    public function BadRequest($errors = null)
    {
        return ResponseBuilder::Json([
            "message" => "Bad Request",
            "errors" => $errors
        ])->WithCode(400);
    }

    public function TooManyRequests(?string $message = null, ?array $errors = null)
    {
        return ResponseBuilder::Json([
            "message" => $message ?? "Too Many Requests",
            "errors" => $errors
        ])->WithCode(429);
    }

    public function ServerError($errors = null)
    {
        return ResponseBuilder::Json([
            "message" => "Server Error",
            "errors" => $errors
        ])->WithCode(500);
    }

    public function File($content, $mimeType = null, $contentDisposition = null)
    {
        return ResponseBuilder::File($content, $mimeType, $contentDisposition);
    }

    /** A file on disk, streamed by the server (sendfile); see ResponseBuilder::sendFile. */
    public function SendFile(string $path, string $mimeType, ?string $downloadName = null)
    {
        return ResponseBuilder::sendFile($path, $mimeType, $downloadName);
    }

    public function Xml(string $data)
    {
        return ResponseBuilder::xml($data);
    }

    public function Text(string $data)
    {
        return ResponseBuilder::text($data);
    }

    public function Redirect(string $location, int $code = 302)
    {
        return (new ResponseBuilder())
            ->WithHeaders(['Location' => $location])
            ->WithCode($code);
    }
}
