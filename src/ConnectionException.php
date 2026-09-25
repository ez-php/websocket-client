<?php

declare(strict_types=1);

namespace EzPhp\WebsocketClient;

use EzPhp\WebSocket\WebSocketException;

/**
 * Thrown when the connection cannot be opened, breaks, or is failed because
 * the peer violated the WebSocket protocol.
 *
 * @package EzPhp\WebsocketClient
 */
final class ConnectionException extends WebSocketException
{
}
