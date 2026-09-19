<?php

declare(strict_types=1);

namespace EzPhp\WebsocketClient;

/**
 * {@see TransportInterface} over a PHP stream resource — plain `tcp://` or TLS `ssl://`.
 *
 * @package EzPhp\WebsocketClient
 */
final class StreamTransport implements TransportInterface
{
    private const int READ_CHUNK = 8192;

    /**
     * @param resource|null $stream A connected, blocking stream
     */
    public function __construct(private mixed $stream)
    {
    }

    /**
     * Open a TCP connection to `$url`, negotiating TLS for `wss://`.
     *
     * TLS peer and host-name verification are on by default; `$sslOptions`
     * is merged over those defaults into the `ssl` stream-context options
     * (e.g. `cafile`, `local_cert`).
     *
     * @param array<string, mixed> $sslOptions
     *
     * @throws ConnectionException when the connection or TLS negotiation fails
     */
    public static function connect(Url $url, float $timeoutSeconds, array $sslOptions = []): self
    {
        $context = stream_context_create($url->secure ? [
            'ssl' => $sslOptions + [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => trim($url->host, '[]'),
                'SNI_enabled' => true,
            ],
        ] : []);

        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $stream = stream_socket_client(
                $url->socketAddress(),
                $errno,
                $errstr,
                $timeoutSeconds,
                STREAM_CLIENT_CONNECT,
                $context,
            );
        } finally {
            restore_error_handler();
        }

        if ($stream === false) {
            $reason = $errstr !== '' ? $errstr : (is_string($warning) ? $warning : 'unknown error');

            throw new ConnectionException("Could not connect to {$url->socketAddress()}: {$reason}");
        }

        stream_set_timeout($stream, max(1, (int) ceil($timeoutSeconds)));

        return new self($stream);
    }

    public function write(string $bytes): void
    {
        if (!is_resource($this->stream)) {
            throw new ConnectionException('Cannot write: the stream is closed.');
        }

        $remaining = $bytes;

        while ($remaining !== '') {
            $warning = null;
            set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
                $warning = $message;

                return true;
            });

            try {
                $written = fwrite($this->stream, $remaining);
            } finally {
                restore_error_handler();
            }

            if ($written === false || $written === 0) {
                throw new ConnectionException('Failed to write to the WebSocket stream' . (is_string($warning) ? ": {$warning}" : '.'));
            }

            $remaining = substr($remaining, $written);
        }
    }

    public function read(float $timeoutSeconds): ?string
    {
        if (!is_resource($this->stream)) {
            return null;
        }

        // A TLS stream may hold decoded bytes that stream_select() cannot see.
        $buffered = stream_get_meta_data($this->stream)['unread_bytes'];

        if ($buffered === 0) {
            $read = [$this->stream];
            $write = null;
            $except = null;
            $seconds = (int) $timeoutSeconds;
            $micro = (int) (($timeoutSeconds - $seconds) * 1_000_000);

            $ready = stream_select($read, $write, $except, $seconds, $micro);

            if ($ready === false) {
                return null;
            }

            if ($ready === 0) {
                return '';
            }
        }

        $data = fread($this->stream, self::READ_CHUNK);

        if ($data === false || ($data === '' && feof($this->stream))) {
            return null;
        }

        return $data;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
    }
}
