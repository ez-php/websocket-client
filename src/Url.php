<?php

declare(strict_types=1);

namespace EzPhp\WebsocketClient;

use InvalidArgumentException;

/**
 * A parsed `ws://` or `wss://` endpoint.
 *
 * @package EzPhp\WebsocketClient
 */
final readonly class Url
{
    /**
     * @param string $host   Host name or IP literal (IPv6 literals keep their brackets)
     * @param int    $port   TCP port
     * @param string $path   Request target: path plus optional query, always starting with "/"
     * @param bool   $secure Whether the scheme is `wss` (TLS)
     */
    public function __construct(
        public string $host,
        public int $port,
        public string $path,
        public bool $secure,
    ) {
    }

    /**
     * Parse a `ws://` / `wss://` URL.
     *
     * @throws InvalidArgumentException when the URL is not a valid WebSocket endpoint
     */
    public static function parse(string $url): self
    {
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new InvalidArgumentException('WebSocket URL must not contain whitespace or control characters.');
        }

        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException("Invalid WebSocket URL: {$url}");
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'ws' && $scheme !== 'wss') {
            throw new InvalidArgumentException("WebSocket URL scheme must be ws or wss, got: {$parts['scheme']}");
        }

        if (isset($parts['fragment'])) {
            throw new InvalidArgumentException('WebSocket URL must not contain a fragment.');
        }

        $secure = $scheme === 'wss';
        $path = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];

        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        return new self($parts['host'], $parts['port'] ?? ($secure ? 443 : 80), $path, $secure);
    }

    /**
     * The value of the `Host` request header (port omitted when it is the scheme default).
     */
    public function hostHeader(): string
    {
        $default = $this->secure ? 443 : 80;

        return $this->port === $default ? $this->host : $this->host . ':' . $this->port;
    }

    /**
     * The `tcp://` or `ssl://` address handed to `stream_socket_client()`.
     */
    public function socketAddress(): string
    {
        return ($this->secure ? 'ssl' : 'tcp') . '://' . $this->host . ':' . $this->port;
    }
}
