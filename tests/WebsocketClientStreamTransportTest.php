<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\WebsocketClient\ConnectionException;
use EzPhp\WebsocketClient\StreamTransport;
use EzPhp\WebsocketClient\Url;

final class WebsocketClientStreamTransportTest extends TestCase
{
    public function test_write_and_read_over_a_socket_pair(): void
    {
        [$a, $b] = $this->pair();
        $transport = new StreamTransport($a);

        $transport->write('ping');
        self::assertSame('ping', fread($b, 16));

        fwrite($b, 'pong');
        self::assertSame('pong', $transport->read(1.0));

        $transport->close();
    }

    public function test_read_returns_empty_string_on_timeout(): void
    {
        [$a, $b] = $this->pair();
        $transport = new StreamTransport($a);

        self::assertSame('', $transport->read(0.01));

        $transport->close();
        fclose($b);
    }

    public function test_read_returns_null_when_the_peer_closes(): void
    {
        [$a, $b] = $this->pair();
        $transport = new StreamTransport($a);
        fclose($b);

        self::assertNull($transport->read(1.0));

        $transport->close();
    }

    public function test_closed_transport_reads_null_and_refuses_writes(): void
    {
        [$a, $b] = $this->pair();
        $transport = new StreamTransport($a);
        $transport->close();
        $transport->close();
        fclose($b);

        self::assertNull($transport->read(0.0));

        $this->expectException(ConnectionException::class);

        $transport->write('x');
    }

    public function test_write_to_a_closed_peer_fails(): void
    {
        [$a, $b] = $this->pair();
        $transport = new StreamTransport($a);
        fclose($b);

        $this->expectException(ConnectionException::class);

        // The first write after the peer hung up may be buffered; a second one must fail.
        for ($i = 0; $i < 50; $i++) {
            $transport->write(str_repeat('x', 65536));
        }
    }

    public function test_connect_to_a_refused_port_throws(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($probe);
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Could not connect');

        StreamTransport::connect(new Url('127.0.0.1', $port, '/', false), 1.0);
    }

    public function test_connect_opens_a_working_tcp_stream(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $name = (string) stream_socket_get_name($server, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $transport = StreamTransport::connect(new Url('127.0.0.1', $port, '/', false), 1.0);
        $accepted = stream_socket_accept($server, 1.0);
        self::assertIsResource($accepted);

        $transport->write('hi');
        self::assertSame('hi', fread($accepted, 8));

        $transport->close();
        fclose($accepted);
        fclose($server);
    }

    public function test_tls_connect_to_a_plain_tcp_listener_fails(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $name = (string) stream_socket_get_name($server, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($server);

        $this->expectException(ConnectionException::class);

        StreamTransport::connect(new Url('127.0.0.1', $port, '/', true), 1.0, ['cafile' => '/nonexistent']);
    }

    /**
     * @return array{resource, resource}
     */
    private function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        return [$pair[0], $pair[1]];
    }
}
