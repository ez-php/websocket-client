<?php

declare(strict_types=1);

namespace EzPhp\WebsocketClient;

use EzPhp\WebSocket\Opcode;
use InvalidArgumentException;

/**
 * Encodes client→server frames.
 *
 * RFC 6455 §5.3 requires every client frame to be masked, which
 * `EzPhp\WebSocket\Frame::encode()` (server→client, unmasked) does not do —
 * hence this encoder. Received frames are parsed with `Frame::parse()`.
 *
 * @package EzPhp\WebsocketClient
 */
final class FrameEncoder
{
    /**
     * Encode one masked frame.
     *
     * @param Opcode      $opcode  Frame type
     * @param string      $payload Unmasked payload bytes
     * @param bool        $fin     FIN bit
     * @param string|null $maskKey Exactly 4 bytes; random when null (only tests should pass one)
     *
     * @throws InvalidArgumentException when `$maskKey` is not 4 bytes long
     */
    public function encode(Opcode $opcode, string $payload, bool $fin = true, ?string $maskKey = null): string
    {
        $maskKey ??= random_bytes(4);

        if (strlen($maskKey) !== 4) {
            throw new InvalidArgumentException('The masking key must be exactly 4 bytes.');
        }

        $length = strlen($payload);
        $header = chr(($fin ? 0x80 : 0x00) | $opcode->value);

        if ($length <= 125) {
            $header .= chr(0x80 | $length);
        } elseif ($length <= 65535) {
            $header .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $header .= chr(0x80 | 127) . pack('J', $length);
        }

        // PHP truncates a string XOR to the shorter operand, so a key repeated
        // at least ceil($length / 4) times masks the whole payload.
        $masked = $payload ^ str_repeat($maskKey, intdiv($length, 4) + 1);

        return $header . $maskKey . $masked;
    }
}
