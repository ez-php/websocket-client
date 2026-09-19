<?php

declare(strict_types=1);

/**
 * Child process for WebsocketClientEndToEndTest: a real ez-php/websocket Server
 * that echoes TEXT as "echo:<text>" and BINARY back unchanged.
 *
 * Usage: php ws-echo-server.php <port>
 */

use EzPhp\WebSocket\ConnectionInterface;
use EzPhp\WebSocket\Frame;
use EzPhp\WebSocket\HandlerInterface;
use EzPhp\WebSocket\Opcode;
use EzPhp\WebSocket\Server;

foreach ([dirname(__DIR__, 2) . '/vendor/autoload.php', dirname(__DIR__, 4) . '/vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

$args = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? array_values($_SERVER['argv']) : [];
$port = $args[1] ?? null;

if (!is_string($port)) {
    fwrite(STDERR, "usage: ws-echo-server.php <port>\n");
    exit(2);
}

final class WebsocketClientEchoHandler implements HandlerInterface
{
    public function onOpen(ConnectionInterface $conn): void
    {
    }

    public function onMessage(ConnectionInterface $conn, Frame $frame): void
    {
        if ($frame->opcode === Opcode::BINARY) {
            $conn->sendBinary($frame->payload);

            return;
        }

        $conn->send('echo:' . $frame->payload);
    }

    public function onClose(ConnectionInterface $conn): void
    {
    }

    public function onError(ConnectionInterface $conn, \Throwable $e): void
    {
    }
}

(new Server('127.0.0.1', (int) $port))->run(new WebsocketClientEchoHandler());
