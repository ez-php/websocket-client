<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\WebSocket\Frame;
use EzPhp\WebSocket\HandshakeException;
use EzPhp\WebSocket\Opcode;
use EzPhp\WebSocket\PerMessageDeflate;
use EzPhp\WebSocket\PerMessageDeflateConfig;
use EzPhp\WebsocketClient\Client;
use EzPhp\WebsocketClient\ConnectionException;
use EzPhp\WebsocketClient\Url;

final class WebsocketClientCompressionTest extends TestCase
{
    private function connect(WebsocketClientFakeTransport $transport, ?PerMessageDeflateConfig $config = new PerMessageDeflateConfig()): Client
    {
        return Client::over($transport, Url::parse('ws://localhost/ws'), [], [], 1.0, $config);
    }

    /**
     * The server side of the agreement, to compress what the "server" sends and inflate what the client sent.
     */
    private static function server(): PerMessageDeflate
    {
        $server = PerMessageDeflate::accept('permessage-deflate', new PerMessageDeflateConfig());
        self::assertNotNull($server);

        return $server;
    }

    private static function written(WebsocketClientFakeTransport $transport, int $index): Frame
    {
        $bytes = $transport->written[$index];
        $frame = Frame::parse($bytes);
        self::assertNotNull($frame);

        return $frame;
    }

    public function testOffersTheExtensionAndCompressesWhenAccepted(): void
    {
        $transport = new WebsocketClientFakeTransport(null, "Sec-WebSocket-Extensions: permessage-deflate\r\n");
        $client = $this->connect($transport);

        self::assertStringContainsString("Sec-WebSocket-Extensions: permessage-deflate; client_max_window_bits\r\n", $transport->written[0]);
        self::assertTrue($client->isCompressed());

        $payload = str_repeat('compress me ', 20);
        $client->send($payload);
        $client->ping('p');

        $sent = self::written($transport, 1);
        self::assertTrue($sent->rsv1);
        self::assertTrue($sent->masked);
        self::assertSame($payload, self::server()->decompress($sent->payload, 10_000));
        self::assertFalse(self::written($transport, 2)->rsv1, 'control frames are never compressed');
    }

    public function testDeclinedOfferKeepsMessagesUncompressed(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport);

        self::assertFalse($client->isCompressed());
        $client->sendBinary('raw');
        self::assertSame('raw', self::written($transport, 1)->payload);
        self::assertFalse(self::written($transport, 1)->rsv1);
    }

    public function testCompressedServerMessagesAreInflatedAcrossFragments(): void
    {
        $transport = new WebsocketClientFakeTransport(null, "Sec-WebSocket-Extensions: permessage-deflate; server_no_context_takeover\r\n");
        $client = $this->connect($transport);
        $compressed = self::server()->compress('héllo wörld');

        $transport->queue((new Frame(Opcode::TEXT, substr($compressed, 0, 4), false, false, true))->encode()
            . (new Frame(Opcode::CONTINUATION, substr($compressed, 4)))->encode());

        self::assertSame('héllo wörld', $client->receive(1.0)?->payload);
    }

    public function testRsv1WithoutTheExtensionFailsWith1002(): void
    {
        $transport = new WebsocketClientFakeTransport();
        $client = $this->connect($transport, null);
        $transport->queue((new Frame(Opcode::TEXT, 'x', rsv1: true))->encode());

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('(1002)');

        $client->receive(1.0);
    }

    public function testOversizedInflatedMessageFailsWith1009(): void
    {
        $transport = new WebsocketClientFakeTransport(null, "Sec-WebSocket-Extensions: permessage-deflate\r\n");
        $client = $this->connect($transport);
        $transport->queue((new Frame(Opcode::BINARY, self::server()->compress(str_repeat("\0", 17 * 1024 * 1024)), rsv1: true))->encode());

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('(1009)');

        $client->receive(1.0);
    }

    public function testUndecodableDataFailsWith1007(): void
    {
        $transport = new WebsocketClientFakeTransport(null, "Sec-WebSocket-Extensions: permessage-deflate\r\n");
        $client = $this->connect($transport);
        $transport->queue((new Frame(Opcode::BINARY, "\xff\xff\xff\xff", rsv1: true))->encode());

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('(1007)');

        $client->receive(1.0);
    }

    public function testExtensionsWithoutAnOfferAreStillRejected(): void
    {
        $this->expectException(HandshakeException::class);

        $this->connect(new WebsocketClientFakeTransport(null, "Sec-WebSocket-Extensions: permessage-deflate\r\n"), null);
    }

    public function testInvalidAgreementIsRejectedAndClosesTheTransport(): void
    {
        $transport = new WebsocketClientFakeTransport(null, "Sec-WebSocket-Extensions: permessage-deflate; client_max_window_bits=8\r\n");

        try {
            $this->connect($transport);
            self::fail('accepted an unusable agreement');
        } catch (HandshakeException) {
            self::assertTrue($transport->closed);
        }
    }

    public function testSendingAfterCloseFails(): void
    {
        $transport = new WebsocketClientFakeTransport(null, "Sec-WebSocket-Extensions: permessage-deflate\r\n");
        $client = $this->connect($transport);
        $client->close(timeout: 0.0);

        $this->expectException(ConnectionException::class);
        $client->send('late');
    }
}
