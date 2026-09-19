<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\WebsocketClient\ConnectionException;
use EzPhp\WebsocketClient\Handshake;
use EzPhp\WebsocketClient\TransportInterface;

/**
 * In-memory transport: answers the handshake request with a valid 101 (unless told otherwise),
 * then serves scripted inbound chunks and records everything the client wrote.
 */
final class WebsocketClientFakeTransport implements TransportInterface
{
    /** @var list<string> */
    public array $written = [];

    public bool $closed = false;

    public bool $failWrites = false;

    /** Bytes glued onto the end of the valid 101 response (frames sent in the same segment). */
    public string $handshakeTrailer = '';

    /** @var list<string|null> queued read results; null simulates the peer closing the stream */
    private array $inbound = [];

    /**
     * @param string|null $handshakeResponse full response override; null = a valid 101 for the sent key
     * @param string      $extraResponseHeaders extra header lines (each ending in \r\n) appended to the valid 101
     */
    public function __construct(
        private readonly ?string $handshakeResponse = null,
        private readonly string $extraResponseHeaders = '',
    ) {
    }

    public function queue(string|null $bytes): void
    {
        $this->inbound[] = $bytes;
    }

    public function write(string $bytes): void
    {
        if ($this->failWrites) {
            throw new ConnectionException('write failed');
        }

        $this->written[] = $bytes;

        if (str_starts_with($bytes, 'GET ')) {
            array_unshift($this->inbound, $this->handshakeResponse ?? $this->validResponse($bytes));
        }
    }

    public function read(float $timeoutSeconds): ?string
    {
        if ($this->inbound === []) {
            return '';
        }

        return array_shift($this->inbound);
    }

    public function close(): void
    {
        $this->closed = true;
    }

    private function validResponse(string $request): string
    {
        preg_match('/Sec-WebSocket-Key: (\S+)/', $request, $match);

        return "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . 'Sec-WebSocket-Accept: ' . Handshake::acceptFor($match[1] ?? '') . "\r\n"
            . $this->extraResponseHeaders . "\r\n" . $this->handshakeTrailer;
    }
}
