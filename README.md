# ez-php/websocket-client

RFC 6455 WebSocket client for PHP 8.5 — HTTP upgrade handshake, masked frames, plain TCP (`ws://`) and TLS (`wss://`). Reuses the frame parser and opcodes of [`ez-php/websocket`](https://github.com/ez-php/websocket); no framework dependency.

---

## Installation

```bash
composer require ez-php/websocket-client
```

Requires PHP 8.5 and `ext-openssl`.

---

## Usage

```php
use EzPhp\WebsocketClient\Client;

$client = Client::connect('wss://example.com/socket', headers: ['Authorization' => 'Bearer …']);

$client->send('hello');                    // TEXT
$client->sendBinary($bytes);               // BINARY

$message = $client->receive(timeout: 5.0); // ?Message — null when nothing arrived in time
if ($message?->isText()) {
    echo $message->payload;
}

$client->ping();                           // pongs are consumed by receive()
$client->close(1000, 'bye');               // closing handshake, idempotent
```

- `receive()` answers server pings, reassembles fragmented messages, and returns the server's
  `CLOSE` as a `Message` (`isClose()`, `closeCode()`, `closeReason()`).
- Protocol violations by the server close the connection with the proper status code and throw
  `ConnectionException`. A refused upgrade throws `EzPhp\WebSocket\HandshakeException`.
- `wss://` verifies the certificate and host name by default. Override via
  `Client::connect(..., sslOptions: ['cafile' => '/path/ca.pem'])`.
- Subprotocols: `Client::connect($url, protocols: ['chat'])`, then `$client->subprotocol()`.
- The API is blocking and one instance serves one connection.

---

## License

MIT
