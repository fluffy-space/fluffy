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
    /** Peers whose X-Real-IP is believed: our own reverse proxy, which runs on the same host. */
    private const TRUSTED_PROXIES = ['127.0.0.1', '::1', '::ffff:127.0.0.1'];

    /**
     * The client's address, for rate limiting, abuse attribution and geo lookups.
     *
     * X-Real-IP is an ordinary request header — anyone can send one — so it is only believed when
     * the CONNECTION came from the local reverse proxy, which overwrites it with $remote_addr.
     * From any other peer the socket address is the only thing that cannot be forged, and a
     * spoofable client address means every IP-keyed limit (login attempts, abuse reports,
     * anonymous link creation) is bypassed by a header.
     */
    public function getIp()
    {
        $peer = $this->server['remote_addr'] ?? '127.0.0.1';
        if (in_array($peer, self::TRUSTED_PROXIES, true)) {
            return $this->headers['x-real-ip'] ?? $peer;
        }
        return $peer;
    }
}
