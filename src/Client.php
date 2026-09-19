<?php

declare(strict_types=1);

namespace EzPhp\WebsocketClient;

use EzPhp\WebSocket\Frame;
use EzPhp\WebSocket\HandshakeException;
use EzPhp\WebSocket\Opcode;
use EzPhp\WebSocket\WebSocketException;
use InvalidArgumentException;

/**
 * A blocking RFC 6455 WebSocket client.
 *
 * ```php
 * $client = Client::connect('wss://example.com/socket');
 * $client->send('hello');
 * $reply = $client->receive(timeout: 5.0);   // ?Message — null when nothing arrived in time
 * $client->close();
 * ```
 *
 * Pings are answered automatically, pongs are swallowed, fragmented messages
 * are reassembled, and any protocol violation by the server fails the
 * connection with a close frame and a {@see ConnectionException}.
 * Not thread- or fiber-safe: use one instance per connection.
 *
 * @package EzPhp\WebsocketClient
 */
final class Client
{
    /**
     * Largest reassembled message accepted (matches the server module's frame cap).
     */
    private const int MAX_MESSAGE_BYTES = 16 * 1024 * 1024;

    private const int MAX_CONTROL_PAYLOAD = 125;

    private const int MAX_HANDSHAKE_HEAD_BYTES = 16384;

    private string $buffer = '';

    private bool $closed = false;

    private bool $closeSent = false;

    private ?Opcode $fragmentOpcode = null;

    private string $fragmentPayload = '';

    private function __construct(
        private readonly TransportInterface $transport,
        private readonly FrameEncoder $encoder,
        private readonly ?string $subprotocol,
    ) {
    }

    /**
     * Connect to a `ws://` or `wss://` endpoint and complete the opening handshake.
     *
     * @param array<string, string> $headers    Extra request headers (`Authorization`, `Origin`, ...)
     * @param list<string>          $protocols  Requested subprotocols, in preference order
     * @param array<string, mixed>  $sslOptions `ssl` stream-context options for `wss://` (verification is on by default)
     *
     * @throws InvalidArgumentException on a malformed URL, header or subprotocol
     * @throws ConnectionException      when the connection cannot be opened
     * @throws HandshakeException       when the server does not accept the upgrade
     */
    public static function connect(
        string $url,
        array $headers = [],
        array $protocols = [],
        float $timeout = 5.0,
        array $sslOptions = [],
    ): self {
        $parsed = Url::parse($url);

        return self::over(StreamTransport::connect($parsed, $timeout, $sslOptions), $parsed, $headers, $protocols, $timeout);
    }

    /**
     * Perform the opening handshake over an already-connected transport.
     *
     * The transport is closed if the handshake fails.
     *
     * @param array<string, string> $headers
     * @param list<string>          $protocols
     *
     * @throws ConnectionException
     * @throws HandshakeException
     */
    public static function over(
        TransportInterface $transport,
        Url $url,
        array $headers = [],
        array $protocols = [],
        float $timeout = 5.0,
    ): self {
        $key = Handshake::generateKey();

        try {
            $transport->write(Handshake::buildRequest($url, $key, $headers, $protocols));
            [$head, $leftover] = self::readHead($transport, $timeout);
            $subprotocol = Handshake::validateResponse($head, $key, $protocols);
        } catch (WebSocketException | InvalidArgumentException $e) {
            $transport->close();

            throw $e;
        }

        $client = new self($transport, new FrameEncoder(), $subprotocol);
        $client->buffer = $leftover;

        return $client;
    }

    /**
     * The subprotocol the server selected, or null.
     */
    public function subprotocol(): ?string
    {
        return $this->subprotocol;
    }

    /**
     * Whether the connection is still open for sending.
     */
    public function isConnected(): bool
    {
        return !$this->closed && !$this->closeSent;
    }

    /**
     * Send a TEXT message.
     *
     * @throws ConnectionException when the connection is closed or the write fails
     */
    public function send(string $text): void
    {
        $this->sendFrame(Opcode::TEXT, $text);
    }

    /**
     * Send a BINARY message.
     *
     * @throws ConnectionException when the connection is closed or the write fails
     */
    public function sendBinary(string $data): void
    {
        $this->sendFrame(Opcode::BINARY, $data);
    }

    /**
     * Send a PING (payload up to 125 bytes). The pong is consumed silently by `receive()`.
     *
     * @throws ConnectionException when the connection is closed or the write fails
     * @throws InvalidArgumentException when the payload exceeds 125 bytes
     */
    public function ping(string $payload = ''): void
    {
        if (strlen($payload) > self::MAX_CONTROL_PAYLOAD) {
            throw new InvalidArgumentException('A ping payload must not exceed 125 bytes.');
        }

        $this->sendFrame(Opcode::PING, $payload);
    }

    /**
     * Wait for the next complete message.
     *
     * @param float $timeout Seconds to wait; 0 polls once
     *
     * @return Message|null the message, or null when none arrived in time. A CLOSE message ends the connection.
     *
     * @throws ConnectionException when the connection is closed, drops, or the server violates the protocol
     */
    public function receive(float $timeout = 5.0): ?Message
    {
        if ($this->closed) {
            throw new ConnectionException('The connection is closed.');
        }

        $deadline = microtime(true) + $timeout;

        while (true) {
            while (($frame = $this->nextFrame()) !== null) {
                $message = $this->handleFrame($frame);

                if ($message !== null) {
                    return $message;
                }
            }

            $chunk = $this->transport->read(max(0.0, $deadline - microtime(true)));

            if ($chunk === null) {
                $this->shutdown();

                throw new ConnectionException('The connection was closed by the peer without a close frame.');
            }

            if ($chunk === '') {
                if (microtime(true) >= $deadline) {
                    return null;
                }

                continue;
            }

            $this->buffer .= $chunk;
        }
    }

    /**
     * Perform the closing handshake and close the transport. Idempotent.
     *
     * Sends a CLOSE frame, then waits up to `$timeout` seconds for the server's
     * CLOSE; the transport is closed either way.
     *
     * @param int    $code    RFC 6455 §7.4 status code
     * @param string $reason  Reason text (up to 123 bytes)
     * @param float  $timeout Seconds to wait for the server's CLOSE
     *
     * @throws InvalidArgumentException when the reason is too long
     */
    public function close(int $code = 1000, string $reason = '', float $timeout = 1.0): void
    {
        if (strlen($reason) > self::MAX_CONTROL_PAYLOAD - 2) {
            throw new InvalidArgumentException('A close reason must not exceed 123 bytes.');
        }

        if ($this->closed) {
            return;
        }

        try {
            if (!$this->closeSent) {
                $this->sendFrame(Opcode::CLOSE, pack('n', $code) . $reason, true);
            }

            $deadline = microtime(true) + $timeout;

            while (!$this->closed && microtime(true) < $deadline) {
                $this->receive(max(0.0, $deadline - microtime(true)));
            }
        } catch (ConnectionException) {
            // The peer dropped the connection instead of answering; we are closing anyway.
        }

        $this->shutdown();
    }

    /**
     * Read the response head, returning it (without the blank line) plus any bytes received after it.
     *
     * @return array{0: string, 1: string}
     *
     * @throws ConnectionException
     */
    private static function readHead(TransportInterface $transport, float $timeout): array
    {
        $deadline = microtime(true) + $timeout;
        $data = '';

        while (($end = strpos($data, "\r\n\r\n")) === false) {
            if (strlen($data) > self::MAX_HANDSHAKE_HEAD_BYTES) {
                throw new HandshakeException('Handshake response head is too large.');
            }

            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                throw new ConnectionException('Timed out waiting for the handshake response.');
            }

            $chunk = $transport->read($remaining);

            if ($chunk === null) {
                throw new ConnectionException('The server closed the connection during the handshake.');
            }

            $data .= $chunk;
        }

        return [substr($data, 0, $end), substr($data, $end + 4)];
    }

    /**
     * Parse the next complete frame out of the buffer, enforcing client-side protocol rules.
     *
     * @throws ConnectionException after failing the connection on a violation
     */
    private function nextFrame(): ?Frame
    {
        // Server→client frames must not be masked (RFC 6455 §5.1); Frame::parse() would silently unmask.
        if (strlen($this->buffer) >= 2 && (ord($this->buffer[1]) & 0x80) !== 0) {
            $this->fail(1002, 'Received a masked frame from the server.');
        }

        try {
            $frame = Frame::parse($this->buffer);
        } catch (WebSocketException $e) {
            $this->fail(1002, $e->getMessage());
        }

        return $frame;
    }

    /**
     * Apply one frame: answer control frames, reassemble data frames.
     *
     * @return Message|null a completed message, or null when the frame was consumed internally
     *
     * @throws ConnectionException
     */
    private function handleFrame(Frame $frame): ?Message
    {
        $opcode = $frame->opcode;

        if ($opcode === Opcode::PING || $opcode === Opcode::PONG || $opcode === Opcode::CLOSE) {
            if (!$frame->fin || strlen($frame->payload) > self::MAX_CONTROL_PAYLOAD) {
                $this->fail(1002, 'Invalid control frame.');
            }

            if ($opcode === Opcode::PING) {
                $this->sendFrame(Opcode::PONG, $frame->payload, true);
            }

            if ($opcode === Opcode::CLOSE) {
                return $this->handleClose($frame);
            }

            return null;
        }

        if ($opcode === Opcode::CONTINUATION) {
            if ($this->fragmentOpcode === null) {
                $this->fail(1002, 'Unexpected continuation frame.');
            }
        } elseif ($this->fragmentOpcode !== null) {
            $this->fail(1002, 'A new data frame interrupted a fragmented message.');
        } else {
            $this->fragmentOpcode = $opcode;
            $this->fragmentPayload = '';
        }

        $this->fragmentPayload .= $frame->payload;

        if (strlen($this->fragmentPayload) > self::MAX_MESSAGE_BYTES) {
            $this->fail(1009, 'Message too big.');
        }

        if (!$frame->fin) {
            return null;
        }

        $type = $this->fragmentOpcode;
        $payload = $this->fragmentPayload;
        $this->fragmentOpcode = null;
        $this->fragmentPayload = '';

        if ($type === Opcode::TEXT && preg_match('//u', $payload) !== 1) {
            $this->fail(1007, 'Text message is not valid UTF-8.');
        }

        return new Message($type, $payload);
    }

    /**
     * Answer the server's CLOSE (unless we initiated the close) and end the connection.
     */
    private function handleClose(Frame $frame): Message
    {
        if (!$this->closeSent) {
            // Echo the status code back (RFC 6455 §5.5.1); 1005 "no status" must not go on the wire.
            $this->sendFrame(Opcode::CLOSE, strlen($frame->payload) >= 2 ? substr($frame->payload, 0, 2) : '', true);
        }

        $this->shutdown();

        return new Message(Opcode::CLOSE, $frame->payload);
    }

    /**
     * Send a CLOSE frame with `$code`, drop the connection, and throw.
     *
     * @throws ConnectionException always
     */
    private function fail(int $code, string $reason): never
    {
        try {
            if (!$this->closeSent) {
                $this->sendFrame(Opcode::CLOSE, pack('n', $code) . substr($reason, 0, 123), true);
            }
        } catch (ConnectionException) {
            // Already broken; nothing more to tell the peer.
        }

        $this->shutdown();

        throw new ConnectionException("WebSocket protocol error ({$code}): {$reason}");
    }

    /**
     * @param bool $control true for internal control frames that may still go out after our CLOSE
     *
     * @throws ConnectionException
     */
    private function sendFrame(Opcode $opcode, string $payload, bool $control = false): void
    {
        if ($this->closed || (!$control && $this->closeSent)) {
            throw new ConnectionException('The connection is closed.');
        }

        if ($opcode === Opcode::CLOSE) {
            $this->closeSent = true;
        }

        $this->transport->write($this->encoder->encode($opcode, $payload));
    }

    private function shutdown(): void
    {
        $this->closed = true;
        $this->transport->close();
    }
}
