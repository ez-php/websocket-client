<?php

declare(strict_types=1);

namespace EzPhp\WebsocketClient;

/**
 * Byte transport underneath a {@see Client}: a connected TCP or TLS stream.
 *
 * @package EzPhp\WebsocketClient
 */
interface TransportInterface
{
    /**
     * Write all of `$bytes`.
     *
     * @throws ConnectionException when the write fails
     */
    public function write(string $bytes): void;

    /**
     * Wait up to `$timeoutSeconds` for data.
     *
     * @return string|null bytes read, '' when the timeout elapsed with no data, null once the peer closed the stream
     */
    public function read(float $timeoutSeconds): ?string;

    /**
     * Close the underlying stream. Safe to call more than once.
     */
    public function close(): void;
}
