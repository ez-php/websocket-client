<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\WebSocket\Frame;
use EzPhp\WebSocket\Opcode;
use EzPhp\WebsocketClient\FrameEncoder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class WebsocketClientFrameEncoderTest extends TestCase
{
    public function test_rfc_6455_masked_hello_example(): void
    {
        // RFC 6455 §5.7: a single-frame masked text message "Hello".
        $encoded = (new FrameEncoder())->encode(Opcode::TEXT, 'Hello', true, "\x37\xfa\x21\x3d");

        self::assertSame('818537fa213d7f9f4d5158', bin2hex($encoded));
    }

    public function test_mask_bit_is_always_set_and_key_is_random_by_default(): void
    {
        $encoder = new FrameEncoder();
        $a = $encoder->encode(Opcode::TEXT, 'same');
        $b = $encoder->encode(Opcode::TEXT, 'same');

        self::assertSame(0x80, ord($a[1]) & 0x80);
        self::assertNotSame($a, $b);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function payloadSizes(): array
    {
        return ['empty' => [0], 'short' => [125], '16-bit' => [126], '16-bit max' => [65535], '64-bit' => [65536], 'unaligned' => [70001]];
    }

    #[DataProvider('payloadSizes')]
    public function test_frames_round_trip_through_the_server_parser(int $size): void
    {
        $payload = substr(random_bytes(max(1, $size)), 0, $size);
        $buffer = (new FrameEncoder())->encode(Opcode::BINARY, $payload);

        $frame = Frame::parse($buffer);

        self::assertNotNull($frame);
        self::assertSame(Opcode::BINARY, $frame->opcode);
        self::assertSame($payload, $frame->payload);
        self::assertTrue($frame->fin);
        self::assertSame('', $buffer);
    }

    public function test_fin_flag_can_be_cleared(): void
    {
        $buffer = (new FrameEncoder())->encode(Opcode::TEXT, 'part', false);
        $frame = Frame::parse($buffer);

        self::assertNotNull($frame);
        self::assertFalse($frame->fin);
    }

    public function test_mask_key_must_be_four_bytes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FrameEncoder())->encode(Opcode::TEXT, 'x', true, 'abc');
    }
}
