<?php

declare(strict_types=1);

namespace EzPhp\WebsocketClient;

use EzPhp\WebSocket\HandshakeException;
use InvalidArgumentException;

/**
 * The client side of the RFC 6455 §4 opening handshake: builds the HTTP
 * upgrade request and verifies the server's `101 Switching Protocols` reply.
 *
 * Pure functions over strings — no I/O — so they are testable without a socket.
 *
 * @package EzPhp\WebsocketClient
 */
final class Handshake
{
    private const string GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /**
     * Headers the handshake owns; callers may not override them.
     */
    private const array RESERVED_HEADERS = ['host', 'upgrade', 'connection'];

    /**
     * A fresh random `Sec-WebSocket-Key` (16 random bytes, base64).
     */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(16));
    }

    /**
     * The `Sec-WebSocket-Accept` value a server must answer `$key` with.
     */
    public static function acceptFor(string $key): string
    {
        return base64_encode(sha1($key . self::GUID, true));
    }

    /**
     * Build the HTTP upgrade request.
     *
     * @param array<string, string> $headers   Extra request headers (e.g. `Authorization`, `Origin`)
     * @param list<string>          $protocols Requested subprotocols, in preference order
     *
     * @throws InvalidArgumentException on a reserved or malformed header, or a malformed subprotocol
     */
    public static function buildRequest(Url $url, string $key, array $headers = [], array $protocols = []): string
    {
        $lines = [
            "GET {$url->path} HTTP/1.1",
            'Host: ' . $url->hostHeader(),
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Key: {$key}",
            'Sec-WebSocket-Version: 13',
        ];

        if ($protocols !== []) {
            foreach ($protocols as $protocol) {
                if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $protocol) !== 1) {
                    throw new InvalidArgumentException("Invalid subprotocol token: {$protocol}");
                }
            }

            $lines[] = 'Sec-WebSocket-Protocol: ' . implode(', ', $protocols);
        }

        foreach ($headers as $name => $value) {
            $lowered = strtolower($name);

            if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1 || preg_match('/[\r\n\x00]/', $value) === 1) {
                throw new InvalidArgumentException("Invalid header: {$name}");
            }

            if (in_array($lowered, self::RESERVED_HEADERS, true) || str_starts_with($lowered, 'sec-websocket-')) {
                throw new InvalidArgumentException("The {$name} header is managed by the handshake and cannot be set.");
            }

            $lines[] = "{$name}: {$value}";
        }

        return implode("\r\n", $lines) . "\r\n\r\n";
    }

    /**
     * Verify the server's response head (status line + headers, without the trailing blank line).
     *
     * @param list<string> $protocols The subprotocols that were requested
     *
     * @return string|null the subprotocol the server selected, or null when none
     *
     * @throws HandshakeException when the response is not a valid acceptance of `$key`
     */
    public static function validateResponse(string $head, string $key, array $protocols = []): ?string
    {
        $lines = explode("\r\n", $head);
        $status = (string) array_shift($lines);

        if (preg_match('#^HTTP/1\.1 (\d{3})(?: |$)#', $status, $match) !== 1) {
            throw new HandshakeException("Malformed handshake response status line: {$status}");
        }

        if ($match[1] !== '101') {
            throw new HandshakeException("Server refused the WebSocket upgrade with HTTP status {$match[1]}.");
        }

        $headers = [];

        foreach ($lines as $line) {
            $colon = strpos($line, ':');

            if ($colon === false) {
                throw new HandshakeException("Malformed handshake response header: {$line}");
            }

            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
        }

        if (strtolower($headers['upgrade'] ?? '') !== 'websocket') {
            throw new HandshakeException('Handshake response is missing "Upgrade: websocket".');
        }

        $connection = array_map(trim(...), explode(',', strtolower($headers['connection'] ?? '')));

        if (!in_array('upgrade', $connection, true)) {
            throw new HandshakeException('Handshake response is missing "Connection: Upgrade".');
        }

        if (!hash_equals(self::acceptFor($key), $headers['sec-websocket-accept'] ?? '')) {
            throw new HandshakeException('Handshake response has a missing or invalid Sec-WebSocket-Accept.');
        }

        if (isset($headers['sec-websocket-extensions'])) {
            throw new HandshakeException('Server negotiated a WebSocket extension that was never requested.');
        }

        $selected = $headers['sec-websocket-protocol'] ?? null;

        if ($selected !== null && !in_array($selected, $protocols, true)) {
            throw new HandshakeException("Server selected a subprotocol that was not requested: {$selected}");
        }

        return $selected;
    }
}
