<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\WebSocket\Frame;
use EzPhp\WebSocket\HandshakeException;
use EzPhp\WebSocket\Opcode;
use EzPhp\WebsocketClient\Client;
use EzPhp\WebsocketClient\ConnectionException;
use EzPhp\WebsocketClient\Message;
use EzPhp\WebsocketClient\Url;
use InvalidArgumentException;

final class WebsocketClientClientTest extends TestCase
{
    public function test_handshake_request_is_written_and_client_is_connected(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);

        self::assertStringStartsWith('GET /ws HTTP/1.1', $transport->written[0]);
        self::assertTrue($client->isConnected());
        self::assertNull($client->subprotocol());
    }

    public function test_failed_handshake_closes_the_transport_and_throws(): void
    {
        $transport = new WebsocketClientFakeTransport("HTTP/1.1 403 Forbidden\r\n\r\n");

        try {
            $this->connect($transport);
            self::fail('Expected a HandshakeException.');
        } catch (HandshakeException) {
            self::assertTrue($transport->closed);
        }
    }

    public function test_handshake_times_out_without_a_response(): void
    {
        // A response that never completes its head.
        $transport = new WebsocketClientFakeTransport("HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket");

        $this->expectException(ConnectionException::class);

        Client::over($transport, Url::parse('ws://localhost/ws'), [], [], 0.05);
    }

    public function test_peer_closing_during_handshake_throws(): void
    {
        $transport = new WebsocketClientFakeTransport('');
        $transport->queue(null);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('during the handshake');

        Client::over($transport, Url::parse('ws://localhost/ws'), [], [], 1.0);
    }

    public function test_bytes_after_the_handshake_head_are_kept_as_frame_data(): void
    {
        // A server that sends its first frame in the same segment as the 101.
        $transport = new WebsocketClientFakeTransport();
        $transport->handshakeTrailer = $this->serverFrame(Opcode::TEXT, 'early');
        $client = $this->connect($transport);

        $message = $client->receive(0.0);

        self::assertNotNull($message);
        self::assertSame('early', $message->payload);
    }

    public function test_selected_subprotocol_is_exposed(): void
    {
        $transport = new WebsocketClientFakeTransport(null, "Sec-WebSocket-Protocol: chat\r\n");
        $client = Client::over($transport, Url::parse('ws://localhost/ws'), [], ['chat']);

        self::assertSame('chat', $client->subprotocol());
    }

    public function test_send_writes_masked_text_and_binary_frames(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);

        $client->send('hello');
        $client->sendBinary("\x00\x01");

        $text = $this->parseWritten($transport->written[1]);
        $binary = $this->parseWritten($transport->written[2]);

        self::assertSame(Opcode::TEXT, $text->opcode);
        self::assertSame('hello', $text->payload);
        self::assertSame(Opcode::BINARY, $binary->opcode);
        self::assertSame("\x00\x01", $binary->payload);
        self::assertSame(0x80, ord($transport->written[1][1]) & 0x80);
    }

    public function test_receive_returns_a_text_message(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);
        $transport->queue($this->serverFrame(Opcode::TEXT, 'héllo'));

        $message = $client->receive(1.0);

        self::assertInstanceOf(Message::class, $message);
        self::assertTrue($message->isText());
        self::assertFalse($message->isBinary());
        self::assertSame('héllo', $message->payload);
    }

    public function test_receive_returns_a_binary_message(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);
        $transport->queue($this->serverFrame(Opcode::BINARY, "\xff\xfe"));

        $message = $client->receive(1.0);

        self::assertNotNull($message);
        self::assertTrue($message->isBinary());
        self::assertSame("\xff\xfe", $message->payload);
    }

    public function test_receive_reassembles_frames_split_across_reads(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);
        $frame = $this->serverFrame(Opcode::TEXT, 'split-me');
        $transport->queue(substr($frame, 0, 3));
        $transport->queue(substr($frame, 3));

        $message = $client->receive(1.0);

        self::assertNotNull($message);
        self::assertSame('split-me', $message->payload);
    }

    public function test_receive_reassembles_fragmented_messages(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);
        $transport->queue(
            $this->serverFrame(Opcode::TEXT, 'one ', false)
            . $this->serverFrame(Opcode::CONTINUATION, 'two ', false)
            . $this->serverFrame(Opcode::CONTINUATION, 'three'),
        );

        $message = $client->receive(1.0);

        self::assertNotNull($message);
        self::assertSame('one two three', $message->payload);
    }

    public function test_control_frame_may_interleave_with_fragments(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);
        $transport->queue(
            $this->serverFrame(Opcode::TEXT, 'a', false)
            . $this->serverFrame(Opcode::PONG, '')
            . $this->serverFrame(Opcode::CONTINUATION, 'b'),
        );

        $message = $client->receive(1.0);

        self::assertNotNull($message);
        self::assertSame('ab', $message->payload);
    }

    public function test_receive_returns_null_on_timeout(): void
    {
        $client = $this->connect(new WebsocketClientFakeTransport());

        self::assertNull($client->receive(0.0));
        self::assertNull($client->receive(0.02));
        self::assertTrue($client->isConnected());
    }

    public function test_ping_from_server_is_answered_with_a_pong(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);
        $transport->queue($this->serverFrame(Opcode::PING, 'beat'));

        self::assertNull($client->receive(0.0));

        $pong = $this->parseWritten($transport->written[1]);
        self::assertSame(Opcode::PONG, $pong->opcode);
        self::assertSame('beat', $pong->payload);
    }

    public function test_client_ping_writes_a_ping_frame(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);

        $client->ping('x');

        self::assertSame(Opcode::PING, $this->parseWritten($transport->written[1])->opcode);
    }

    public function test_oversized_ping_payload_is_rejected(): void
    {
        $client = $this->connect(new WebsocketClientFakeTransport());

        $this->expectException(InvalidArgumentException::class);

        $client->ping(str_repeat('x', 126));
    }

    public function test_server_close_is_echoed_and_returned(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);
        $transport->queue($this->serverFrame(Opcode::CLOSE, pack('n', 1001) . 'going away'));

        $message = $client->receive(1.0);

        self::assertNotNull($message);
        self::assertTrue($message->isClose());
        self::assertSame(1001, $message->closeCode());
        self::assertSame('going away', $message->closeReason());
        self::assertFalse($client->isConnected());
        self::assertTrue($transport->closed);

        $reply = $this->parseWritten($transport->written[1]);
        self::assertSame(Opcode::CLOSE, $reply->opcode);
        self::assertSame(pack('n', 1001), $reply->payload);
    }

    public function test_close_message_without_payload_has_no_code(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);
        $transport->queue($this->serverFrame(Opcode::CLOSE, ''));

        $message = $client->receive(1.0);

        self::assertNotNull($message);
        self::assertNull($message->closeCode());
        self::assertSame('', $message->closeReason());
        self::assertSame('', $this->parseWritten($transport->written[1])->payload);
        self::assertNull((new Message(Opcode::TEXT, 'x'))->closeCode());
    }

    public function test_receive_after_close_throws(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);
        $transport->queue($this->serverFrame(Opcode::CLOSE, ''));
        $client->receive(1.0);

        $this->expectException(ConnectionException::class);

        $client->receive(0.0);
    }

    public function test_send_after_close_throws(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);
        $client->close(1000, '', 0.0);

        $this->expectException(ConnectionException::class);

        $client->send('late');
    }

    public function test_close_sends_close_frame_waits_for_reply_and_is_idempotent(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);
        $transport->queue($this->serverFrame(Opcode::CLOSE, pack('n', 1000)));

        $client->close(1000, 'bye');
        $client->close();

        $sent = $this->parseWritten($transport->written[1]);
        self::assertSame(Opcode::CLOSE, $sent->opcode);
        self::assertSame(pack('n', 1000) . 'bye', $sent->payload);
        self::assertCount(2, $transport->written, 'the peer CLOSE must not be answered a second time');
        self::assertTrue($transport->closed);
        self::assertFalse($client->isConnected());
    }

    public function test_close_survives_a_peer_that_just_hangs_up(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);
        $transport->queue(null);

        $client->close();

        self::assertTrue($transport->closed);
    }

    public function test_close_gives_up_when_the_peer_stays_silent(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);

        $client->close(1000, '', 0.02);

        self::assertTrue($transport->closed);
    }

    public function test_close_rejects_an_overlong_reason(): void
    {
        $client = $this->connect(new WebsocketClientFakeTransport());

        $this->expectException(InvalidArgumentException::class);

        $client->close(1000, str_repeat('x', 124));
    }

    public function test_peer_hanging_up_without_close_frame_throws(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);
        $transport->queue(null);

        try {
            $client->receive(1.0);
            self::fail('Expected a ConnectionException.');
        } catch (ConnectionException) {
            self::assertTrue($transport->closed);
        }
    }

    public function test_masked_server_frame_fails_the_connection_with_1002(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);
        $transport->queue((new \EzPhp\WebsocketClient\FrameEncoder())->encode(Opcode::TEXT, 'x'));

        $this->assertFailsWith($client, $transport, 1002);
    }

    public function test_unknown_opcode_fails_the_connection(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);
        $transport->queue("\x83\x00");

        $this->assertFailsWith($client, $transport, 1002);
    }

    public function test_unexpected_continuation_fails_the_connection(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);
        $transport->queue($this->serverFrame(Opcode::CONTINUATION, 'x'));

        $this->assertFailsWith($client, $transport, 1002);
    }

    public function test_new_data_frame_inside_a_fragmented_message_fails(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);
        $transport->queue($this->serverFrame(Opcode::TEXT, 'a', false) . $this->serverFrame(Opcode::TEXT, 'b'));

        $this->assertFailsWith($client, $transport, 1002);
    }

    public function test_fragmented_control_frame_fails(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);
        $transport->queue($this->serverFrame(Opcode::PING, '', false));

        $this->assertFailsWith($client, $transport, 1002);
    }

    public function test_oversized_control_frame_fails(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);
        $transport->queue($this->serverFrame(Opcode::PING, str_repeat('x', 126)));

        $this->assertFailsWith($client, $transport, 1002);
    }

    public function test_invalid_utf8_text_fails_with_1007(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);
        $transport->queue($this->serverFrame(Opcode::TEXT, "\xff\xfe"));

        $this->assertFailsWith($client, $transport, 1007);
    }

    public function test_reassembled_message_over_the_cap_fails_with_1009(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);
        $chunk = str_repeat('a', 9 * 1024 * 1024);
        $transport->queue($this->serverFrame(Opcode::TEXT, $chunk, false));
        $transport->queue($this->serverFrame(Opcode::CONTINUATION, $chunk));

        $this->assertFailsWith($client, $transport, 1009);
    }

    public function test_write_failure_surfaces_as_connection_exception(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);
        $transport->failWrites = true;

        $this->expectException(ConnectionException::class);

        $client->send('x');
    }

    private function connect(WebsocketClientFakeTransport $transport): Client
    {
        return Client::over($transport, Url::parse('ws://localhost/ws'));
    }

    private function serverFrame(Opcode $opcode, string $payload, bool $fin = true): string
    {
        return (new Frame($opcode, $payload, $fin))->encode();
    }

    private function parseWritten(string $bytes): Frame
    {
        $frame = Frame::parse($bytes);
        self::assertNotNull($frame);

        return $frame;
    }

    private function assertFailsWith(Client $client, WebsocketClientFakeTransport $transport, int $code): void
    {
        try {
            $client->receive(1.0);
            self::fail('Expected a ConnectionException.');
        } catch (ConnectionException $e) {
            self::assertStringContainsString("({$code})", $e->getMessage());
        }

        $close = $this->parseWritten($transport->written[1]);
        self::assertSame(Opcode::CLOSE, $close->opcode);
        self::assertSame(pack('n', $code), substr($close->payload, 0, 2));
        self::assertTrue($transport->closed);
        self::assertFalse($client->isConnected());
    }
}
