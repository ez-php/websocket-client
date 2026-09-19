<?php

declare(strict_types=1);

namespace EzPhp\WebsocketClient;

use EzPhp\WebSocket\Opcode;

/**
 * A complete message received from the server: a reassembled TEXT or BINARY
 * message, or the server's CLOSE frame.
 *
 * @package EzPhp\WebsocketClient
 */
final readonly class Message
{
    /**
     * @param Opcode $opcode  TEXT, BINARY or CLOSE
     * @param string $payload Message payload; for CLOSE the raw close body (2-byte code + reason)
     */
    public function __construct(
        public Opcode $opcode,
        public string $payload,
    ) {
    }

    /**
     * Whether this is a TEXT message.
     */
    public function isText(): bool
    {
        return $this->opcode === Opcode::TEXT;
    }

    /**
     * Whether this is a BINARY message.
     */
    public function isBinary(): bool
    {
        return $this->opcode === Opcode::BINARY;
    }

    /**
     * Whether this is the server's CLOSE frame.
     */
    public function isClose(): bool
    {
        return $this->opcode === Opcode::CLOSE;
    }

    /**
     * The status code of a CLOSE message, or null when absent or not a CLOSE.
     */
    public function closeCode(): ?int
    {
        if (!$this->isClose() || strlen($this->payload) < 2) {
            return null;
        }

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('n', $this->payload);

        return $unpacked[1];
    }

    /**
     * The reason text of a CLOSE message ('' when absent or not a CLOSE).
     */
    public function closeReason(): string
    {
        return $this->isClose() ? substr($this->payload, 2) : '';
    }
}
