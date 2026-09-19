<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\WebsocketClient\Url;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class WebsocketClientUrlTest extends TestCase
{
    public function test_ws_url_defaults_to_port_80_and_root_path(): void
    {
        $url = Url::parse('ws://example.com');

        self::assertSame('example.com', $url->host);
        self::assertSame(80, $url->port);
        self::assertSame('/', $url->path);
        self::assertFalse($url->secure);
        self::assertSame('example.com', $url->hostHeader());
        self::assertSame('tcp://example.com:80', $url->socketAddress());
    }

    public function test_wss_url_defaults_to_port_443_and_keeps_path_and_query(): void
    {
        $url = Url::parse('WSS://example.com/chat/room?token=abc');

        self::assertSame(443, $url->port);
        self::assertSame('/chat/room?token=abc', $url->path);
        self::assertTrue($url->secure);
        self::assertSame('ssl://example.com:443', $url->socketAddress());
    }

    public function test_non_default_port_appears_in_host_header(): void
    {
        self::assertSame('localhost:9000', Url::parse('ws://localhost:9000/x')->hostHeader());
        self::assertSame('localhost:80', Url::parse('wss://localhost:80/x')->hostHeader());
    }

    public function test_ipv6_literal_keeps_brackets(): void
    {
        $url = Url::parse('ws://[::1]:8080/');

        self::assertSame('[::1]', $url->host);
        self::assertSame('tcp://[::1]:8080', $url->socketAddress());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUrls(): array
    {
        return [
            'http scheme' => ['http://example.com/'],
            'no scheme' => ['example.com/socket'],
            'no host' => ['ws:///path'],
            'fragment' => ['ws://example.com/#frag'],
            'space' => ['ws://example.com/a b'],
            'newline' => ["ws://example.com/a\r\nX: y"],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function test_invalid_urls_are_rejected(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        Url::parse($url);
    }
}
