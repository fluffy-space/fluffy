<?php

namespace Fluffy\Controllers;

use Fluffy\Domain\Message\ResponseBuilder;

class BaseController
{
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
