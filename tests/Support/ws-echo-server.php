<?php

declare(strict_types=1);

/**
 * Child process for WebsocketClientEndToEndTest: a real ez-php/websocket Server
 * that echoes TEXT as "echo:<text>" and BINARY back unchanged.
 *
 * Usage: php ws-echo-server.php <port> [<autoload.php>]
 *
 * The test passes its own Composer autoloader, so parent and child run the same
 * ez-php/websocket code (in the monorepo the module's vendor/ may hold an older release).
 */

use EzPhp\WebSocket\ConnectionInterface;
use EzPhp\WebSocket\Frame;
use EzPhp\WebSocket\HandlerInterface;
use EzPhp\WebSocket\Opcode;
use EzPhp\WebSocket\PerMessageDeflateConfig;
use EzPhp\WebSocket\Server;

$cliArgs = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? array_values($_SERVER['argv']) : [];
$given = is_string($cliArgs[2] ?? null) ? [$cliArgs[2]] : [];

foreach ([...$given, dirname(__DIR__, 2) . '/vendor/autoload.php', dirname(__DIR__, 4) . '/vendor/autoload.php'] as $autoload) {
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

// Compression is only used when a client offers it, so the uncompressed tests are unaffected.
(new Server('127.0.0.1', (int) $port, compression: new PerMessageDeflateConfig()))->run(new WebsocketClientEchoHandler());
