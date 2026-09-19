<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\WebSocket\HandshakeException;
use EzPhp\WebsocketClient\Handshake;
use EzPhp\WebsocketClient\Url;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class WebsocketClientHandshakeTest extends TestCase
{
    private const string KEY = 'dGhlIHNhbXBsZSBub25jZQ==';

    public function test_accept_matches_the_rfc_6455_example(): void
    {
        self::assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', Handshake::acceptFor(self::KEY));
    }

    public function test_generated_keys_are_16_random_bytes_base64(): void
    {
        $key = Handshake::generateKey();

        self::assertSame(16, strlen((string) base64_decode($key, true)));
        self::assertNotSame($key, Handshake::generateKey());
    }

    public function test_request_contains_the_required_headers(): void
    {
        $request = Handshake::buildRequest(
            Url::parse('ws://example.com:9000/chat?x=1'),
            self::KEY,
            ['Origin' => 'https://example.com'],
            ['chat', 'superchat'],
        );

        self::assertStringStartsWith("GET /chat?x=1 HTTP/1.1\r\n", $request);
        self::assertStringContainsString("\r\nHost: example.com:9000\r\n", $request);
        self::assertStringContainsString("\r\nUpgrade: websocket\r\n", $request);
        self::assertStringContainsString("\r\nConnection: Upgrade\r\n", $request);
        self::assertStringContainsString("\r\nSec-WebSocket-Key: " . self::KEY . "\r\n", $request);
        self::assertStringContainsString("\r\nSec-WebSocket-Version: 13\r\n", $request);
        self::assertStringContainsString("\r\nSec-WebSocket-Protocol: chat, superchat\r\n", $request);
        self::assertStringContainsString("\r\nOrigin: https://example.com\r\n", $request);
        self::assertStringEndsWith("\r\n\r\n", $request);
    }

    /**
     * @return array<string, array{array<string, string>, list<string>}>
     */
    public static function invalidRequestInputs(): array
    {
        return [
            'reserved Host' => [['Host' => 'evil'], []],
            'reserved Upgrade' => [['upgrade' => 'x'], []],
            'reserved Sec-WebSocket-Key' => [['Sec-WebSocket-Key' => 'x'], []],
            'CRLF in value' => [['X-A' => "b\r\nX-Injected: 1"], []],
            'bad header name' => [['Bad Name' => 'v'], []],
            'bad subprotocol' => [[], ['chat, other']],
        ];
    }

    /**
     * @param array<string, string> $headers
     * @param list<string>          $protocols
     */
    #[DataProvider('invalidRequestInputs')]
    public function test_invalid_request_inputs_are_rejected(array $headers, array $protocols): void
    {
        $this->expectException(InvalidArgumentException::class);

        Handshake::buildRequest(Url::parse('ws://example.com/'), self::KEY, $headers, $protocols);
    }

    public function test_valid_response_is_accepted_and_has_no_subprotocol(): void
    {
        self::assertNull(Handshake::validateResponse($this->head(), self::KEY));
    }

    public function test_header_names_and_connection_tokens_are_case_insensitive(): void
    {
        $head = "HTTP/1.1 101 Switching Protocols\r\nUPGRADE: WebSocket\r\nconnection: keep-alive, Upgrade\r\n"
            . 'sec-websocket-accept: ' . Handshake::acceptFor(self::KEY);

        self::assertNull(Handshake::validateResponse($head, self::KEY));
    }

    public function test_selected_subprotocol_is_returned(): void
    {
        $head = $this->head("Sec-WebSocket-Protocol: chat\r\n");

        self::assertSame('chat', Handshake::validateResponse($head, self::KEY, ['chat', 'other']));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function badResponses(): array
    {
        $accept = 's3pPLMBiTxaQ9kYGzzhZRbK+xOo=';
        $ok = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}";

        return [
            'not http' => ['garbage', 'Malformed handshake response status line'],
            'status 200' => ["HTTP/1.1 200 OK\r\nContent-Length: 0", 'HTTP status 200'],
            'status 403' => ["HTTP/1.1 403 Forbidden\r\n", 'HTTP status 403'],
            'no upgrade header' => ["HTTP/1.1 101 x\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}", 'Upgrade: websocket'],
            'no connection header' => ["HTTP/1.1 101 x\r\nUpgrade: websocket\r\nSec-WebSocket-Accept: {$accept}", 'Connection: Upgrade'],
            'wrong accept' => [str_replace($accept, 'AAAAAAAAAAAAAAAAAAAAAAAAAAA=', $ok), 'Sec-WebSocket-Accept'],
            'missing accept' => ["HTTP/1.1 101 x\r\nUpgrade: websocket\r\nConnection: Upgrade", 'Sec-WebSocket-Accept'],
            'unrequested extension' => [$ok . "\r\nSec-WebSocket-Extensions: permessage-deflate", 'extension'],
            'unrequested subprotocol' => [$ok . "\r\nSec-WebSocket-Protocol: chat", 'subprotocol'],
            'malformed header' => [$ok . "\r\nno-colon-here", 'Malformed handshake response header'],
        ];
    }

    #[DataProvider('badResponses')]
    public function test_invalid_responses_are_rejected(string $head, string $messagePart): void
    {
        $this->expectException(HandshakeException::class);
        $this->expectExceptionMessage($messagePart);

        Handshake::validateResponse($head, self::KEY);
    }

    private function head(string $extra = ''): string
    {
        return "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . $extra . 'Sec-WebSocket-Accept: ' . Handshake::acceptFor(self::KEY);
    }
}
