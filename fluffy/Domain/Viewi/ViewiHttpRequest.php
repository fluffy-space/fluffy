<?php

namespace Fluffy\Domain\Viewi;

use Fluffy\Domain\Message\HttpRequest;

class ViewiHttpRequest extends HttpRequest
{
    public ?array $cookie = [];
    public ?array $header = [];
    /**
     * The payload of a request a COMPONENT issued during SSR, already serialised (JSON).
     *
     * This used to be hardcoded empty, so a `$http->post(...)` in a component's `mounted()` reached
     * the controller with no body at all: RoutingMiddleware had nothing to map, and a handler
     * typed `f(string $id, SomeModel $model)` was called with null — a TypeError, a 500, and a page
     * that only broke when opened directly (during a client-side navigation the browser does the
     * request itself, body included). ViewiFluffyBridge fills this in.
     */
    public string $body = '';

    public function getBody()
    {
        return $this->body;
    }

    public function getCookie(?string $name = null)
    {
        return $name ? $this->cookie[$name] ?? null : $this->cookie;
    }

    public function getHeader(?string $name = null)
    {
        return $name ? $this->header[strtolower($name)] ?? null : $this->header;
    }
}
