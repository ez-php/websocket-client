<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\WebsocketClient\Client;
use EzPhp\WebsocketClient\ConnectionException;
use RuntimeException;

/**
 * Talks to a real `ez-php/websocket` Server running in a child process over loopback TCP:
 * handshake, text/binary echo, ping/pong, and the closing handshake in both directions.
 *
 * The server runs in a child process, so its lines are invisible to the parent's coverage
 * driver; the in-process tests keep the coverage, this suite guards real-wire behaviour.
 */
final class WebsocketClientEndToEndTest extends TestCase
{
    /** @var resource|null */
    private static mixed $server = null;

    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($probe === false) {
            throw new RuntimeException('Could not reserve a port: ' . $errstr);
        }

        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        self::$port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $server = proc_open(
            [PHP_BINARY, __DIR__ . '/Support/ws-echo-server.php', (string) self::$port],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        if (!is_resource($server)) {
            throw new RuntimeException('Could not start the WebSocket server process.');
        }

        self::$server = $server;
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            // Connection-refused warnings are expected until the server is listening.
            set_error_handler(static fn (): bool => true, E_WARNING);

            try {
                $tcp = stream_socket_client('tcp://127.0.0.1:' . self::$port, $errno, $errstr, 0.2);
            } finally {
                restore_error_handler();
            }

            if ($tcp !== false) {
                fclose($tcp);

                return;
            }

            usleep(50_000);
        }

        throw new RuntimeException('The WebSocket server did not accept connections within 5 seconds.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }

        self::$server = null;

        parent::tearDownAfterClass();
    }

    public function test_text_round_trip(): void
    {
        $client = Client::connect($this->url());

        $client->send('hello');
        $message = $client->receive(2.0);

        self::assertNotNull($message);
        self::assertSame('echo:hello', $message->payload);

        $client->close();
    }

    public function test_binary_and_large_payload_round_trip(): void
    {
        $client = Client::connect($this->url());
        $payload = random_bytes(200_000);

        $client->sendBinary($payload);
        $message = $client->receive(5.0);

        self::assertNotNull($message);
        self::assertTrue($message->isBinary());
        self::assertSame($payload, $message->payload);

        $client->close();
    }

    public function test_ping_is_answered_and_connection_stays_usable(): void
    {
        $client = Client::connect($this->url());

        $client->ping('are-you-there');
        self::assertNull($client->receive(0.2), 'a pong is swallowed, not returned');

        $client->send('still-here');
        $message = $client->receive(2.0);

        self::assertNotNull($message);
        self::assertSame('echo:still-here', $message->payload);

        $client->close();
    }

    public function test_client_initiated_close_completes_the_closing_handshake(): void
    {
        $client = Client::connect($this->url());

        $client->close(1000, 'done', 2.0);

        self::assertFalse($client->isConnected());

        $this->expectException(ConnectionException::class);

        $client->receive(0.0);
    }

    public function test_connecting_to_a_closed_port_throws(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($probe);
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $this->expectException(ConnectionException::class);

        Client::connect("ws://127.0.0.1:{$port}/", [], [], 0.5);
    }

    private function url(): string
    {
        return 'ws://127.0.0.1:' . self::$port . '/';
    }
}
