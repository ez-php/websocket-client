# Coding Guidelines

Applies to the entire ez-php project — framework core, all modules, and the application template.

---

## Environment

- PHP **8.5**, Composer for dependency management
- All project based commands run **inside Docker** — never directly on the host

```
docker compose exec app <command>
```

Container name: `ez-php-app`, service name: `app`.

---

## Quality Suite

Run after every change:

```
docker compose exec app composer full
```

Executes in order:
1. `sync_guidelines.php --check` — fails if any `CLAUDE.md` has drifted from this file
2. `check_test_classes.php` — fails on a duplicate test class name (all packages share the `Tests\` namespace, so a collision is a fatal error in the aggregated run, not a test failure)
3. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
4. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
   *(Note: `@PHP85Migration` does not exist yet in php-cs-fixer; `@PHP83Migration` is the highest available and is used intentionally even though the project targets PHP 8.5)*
5. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse             # PHPStan only
composer cs                  # CS Fixer only
composer test                # PHPUnit only
composer guidelines:check    # CLAUDE.md drift only
composer test-classes:check  # duplicate test class names only
```

**PHPStan:** never suppress with `@phpstan-ignore-line` — always fix the root cause.

---

## Coding Standards

- `declare(strict_types=1)` at the top of every PHP file
- Typed properties, parameters, and return values — avoid `mixed`
- PHPDoc on every class and public method
- One responsibility per class — keep classes small and focused
- Constructor injection — no service locator pattern
- No global state unless intentional and documented
- Concrete classes are `final` — extend behavior through composition, not inheritance. Exception-hierarchy base classes (e.g. `EzPhpException`, `HttpException`, `CacheException`) are one carve-out, since they exist specifically to be extended. A documented template-method-style base class (e.g. `Mailable`, meant to be configured via constructor-time subclassing) is the other — the owning module's `CLAUDE.md` must record it under Design Decisions.

**Naming:**

| Thing | Convention |
|---|---|
| Classes / Interfaces | `PascalCase` |
| Methods / variables | `camelCase` |
| Constants | `UPPER_CASE` |
| Files | Match class name exactly |

**Principles:** SOLID · KISS · DRY · YAGNI

---

## Workflow & Behavior

- Write tests **before or alongside** production code (test-first)
- Read and understand the relevant code before making any changes
- Modify the minimal number of files necessary
- Keep implementations small — if it feels big, it likely belongs in a separate module
- No hidden magic — everything must be explicit and traceable
- No large abstractions without clear necessity
- No heavy dependencies — check if PHP stdlib suffices first
- Respect module boundaries — don't reach across packages
- Keep the framework core small — what belongs in a module stays there
- Document architectural reasoning for non-obvious design decisions
- Do not change public APIs unless necessary
- Prefer composition over inheritance — no premature abstractions

---

## New Modules & CLAUDE.md Files

### 1 — Required files

Every module under `modules/<name>/` must have:

| File | Purpose |
|---|---|
| `composer.json` | package definition, deps, autoload |
| `phpstan.neon` | static analysis config, level 9 |
| `phpunit.xml` | test suite config |
| `.php-cs-fixer.php` | code style config |
| `.gitignore` | ignore `vendor/`, `.env`, cache |
| `.env.example` | environment variable defaults (copy to `.env` on first run) |
| `docker-compose.yml` | Docker Compose service definition (always `container_name: ez-php-<name>-app`) |
| `docker/app/Dockerfile` | module Docker image (`FROM au9500/php:8.5`) |
| `docker/app/container-start.sh` | container entrypoint: `composer install` → `sleep infinity` |
| `docker/app/php.ini` | PHP ini overrides (`memory_limit`, `display_errors`, `xdebug.mode`) |
| `.github/workflows/ci.yml` | standalone CI pipeline |
| `README.md` | public documentation |
| `tests/TestCase.php` | base test case for the module |
| `start.sh` | convenience script: copy `.env`, bring up Docker, wait for services, exec shell |
| `CLAUDE.md` | see section 2 below |

### 2 — CLAUDE.md structure

Every module `CLAUDE.md` must follow this exact structure:

1. **Full content of `CODING_GUIDELINES.md`, verbatim** — copy it as-is, do not summarize or shorten
2. A `---` separator
3. `# Package: ez-php/<name>` (or `# Directory: <name>` for non-package directories)
4. Module-specific section covering:
   - Source structure — file tree with one-line description per file
   - Key classes and their responsibilities
   - Design decisions and constraints
   - Testing approach and infrastructure requirements (MySQL, Redis, etc.)
   - What does **not** belong in this module

**Do not edit part 1 by hand.** It is generated from `CODING_GUIDELINES.md` by
`sync_guidelines.php` at the project root:

```
php sync_guidelines.php            # rewrite every out-of-sync CLAUDE.md
php sync_guidelines.php --check    # report drift, exit 1 if any (CI / pre-commit)
```

Edit `CODING_GUIDELINES.md`, then run the script — it replaces everything before the
`# Package:` / `# Directory:` / `# Project:` heading and preserves the hand-written
section below it byte-for-byte. Editing a single copy only creates drift; before this
script existed, all 40 copies had diverged.

### 3 — Scaffolding a new module

`make_module.php` at the project root writes the required-file set and the monorepo
wiring in one step, wrapping `docker-init` for the Docker subset:

```
composer module:make <name> -- --description="..."
php make_module.php <name> --description="..." --services=mysql,redis
```

`<name>` is the kebab-case package name; the namespace is derived as
`EzPhp\<PascalCase>` (each `-`-separated word upper-cased) unless `--namespace=`
overrides it. Existing exceptions the guess gets wrong: `bignum` → `BigNum`,
`dataloader` → `DataLoader`, `dotenv` → `Env`, `graphql` → `GraphQL`, `oauth` → `OAuth`,
`opcache` → `OPCache`, `swagger-ui` → `SwaggerUI`, `webauthn` → `WebAuthn` and
`websocket` → `WebSocket`; `websocket-client` → `WebsocketClient`, `websocket-tls` → `WebsocketTls`,
`webauthn-metadata` → `WebauthnMetadata` and `metrics-statsd` → `MetricsStatsd` are
intentional lower-case-word namespaces, and `testing-application` shares `EzPhp\Testing\`
with `testing`).

To bring in a module whose code already lives in its own repository instead of
generating a fresh skeleton, pass `--repo=` with a git URL:

```
php make_module.php <name> --repo=<git-url> [--namespace=Foo]
```

This runs `git submodule add <url> modules/<name>` instead of writing package
files, then applies the same monorepo wiring below. It is mutually exclusive
with `--services` and `--description` — a submodule brings its own Docker
scaffold (if any) and its own `composer.json` description. A minimal `CLAUDE.md`
stub is written only if the submodule doesn't already ship one, so
`composer guidelines:sync` has a `# Package:` heading to anchor part 1 against.

It writes `modules/<name>/` and registers the module in the four places the monorepo
needs it — root `composer.json` (`autoload.psr-4` **and** the shared
`autoload-dev` `Tests\` directory list), `phpstan.neon`, `phpunit.xml` (test suite
**and** coverage source), and `packages.sh` (alphabetical position) — in both
generated and `--repo` mode.

Two things stay manual on purpose:

- **`CLAUDE.md` part 1** — only the `# Package:` section is generated. Run
  `composer guidelines:sync` afterwards; baking a guidelines copy into the generator
  would recreate the drift the sync script exists to prevent.
- **The host-port table below** (`--services` only) — claim the "next free" row by
  editing the table in `CODING_GUIDELINES.md` (never in a `CLAUDE.md` copy) and run
  `composer guidelines:sync` in the same change. Editing it drifts every `CLAUDE.md`
  until the sync runs, which is why the generator only reminds you instead of doing
  it. Skipping the edit leaves "next free" stale, so the next module collides.

### 4 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^2.0"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

Pass `--services` to merge MySQL/Redis/Meilisearch service definitions directly into `docker-compose.yml` and uncomment the matching sections in `.env.example`, instead of adapting them by hand afterward:

```
vendor/bin/docker-init --services=mysql
vendor/bin/docker-init --services=redis
vendor/bin/docker-init --services=meilisearch
vendor/bin/docker-init --services=mysql,redis
```

Pass `--extensions` to merge PHP extension install blocks (apt packages plus `docker-php-ext-install`/`pecl` lines) directly into `docker/app/Dockerfile`, instead of hand-editing it afterward — supported extensions: `bcmath`, `gmp`, `gd`, `imagick`:

```
vendor/bin/docker-init --extensions=gmp,bcmath
vendor/bin/docker-init --extensions=gd,imagick
```

When run from a module directory inside this monorepo, any requested extension not already present is also merged into the shared root `docker/app/Dockerfile` — the container `composer full` at the root actually runs against, distinct from the module's own standalone image.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis, Meilisearch) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | Redis host port | `MEILISEARCH_PORT` |
|---|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 (`REDIS_PORT`) | 7700 |
| `ez-php/framework` | 3307 | — | — |
| `ez-php/` (application template) | 3308 | 6383 (`REDIS_PORT`) | — |
| `ez-php/orm` | 3309 | — | — |
| `ez-php/cache` | — | 6380 (`REDIS_HOST_PORT`) | — |
| `ez-php/queue` | 3310 | 6381 (`REDIS_HOST_PORT`) | — |
| `ez-php/rate-limiter` | — | 6382 (`REDIS_HOST_PORT`) | — |
| `ez-php/search` | — | — | 7701 |
| `ez-php/event-store` | 3311 | — | — |
| **next free** | **3312** | **6384** | **7702** |

Only set a port for services the module actually uses. Modules without external services need no port config.

> The `MEILISEARCH_PORT` column is the **host** port. Inside a Compose network the service is always reachable at `http://meilisearch:7700` regardless of the host mapping — only publish-side ports need to be unique.

> The "Redis host port" column is likewise the **host**-published port. `ez-php/cache`, `ez-php/queue`, and `ez-php/rate-limiter` map it through a separate `REDIS_HOST_PORT` env var in `docker-compose.yml`, keeping `REDIS_PORT` fixed at `6379` for in-container connections (the app container always reaches Redis at `redis:6379` over the Compose network, regardless of the host mapping) — the root project and the `ez-php/` application template are the two exceptions, since both have no host/container split and use `REDIS_PORT` for both (the template's other in-container Redis settings — `CACHE_REDIS_PORT`, `QUEUE_REDIS_PORT`, `RATE_LIMITER_REDIS_PORT`, `HEALTH_REDIS_PORT` — stay fixed at `6379` regardless, same as every other module).

> This table tracks only MySQL, Redis, and Meilisearch ports — the three services shared across multiple modules where a collision is otherwise easy to introduce. Mailpit is the one other service with published host ports: SMTP `1025` and web UI `8025`. `ez-php/mail` maps them through `MAILPIT_SMTP_HOST_PORT`/`MAILPIT_API_HOST_PORT` in `modules/mail/docker-compose.yml` (mirroring the `*_HOST_PORT` pattern above, documented in `modules/mail/.env.example`); the root project and the `ez-php/` template each run their own Mailpit on the same defaults (`MAIL_PORT`/`MAIL_WEB_PORT`), so **these three stacks cannot run at the same time** without overriding those variables. It isn't a table column because no module beyond those three runs Mailpit — but a new module adding its own single-use service's ports should likewise parameterize them and document the defaults in its own `.env.example` rather than adding a column here.

### 5 — Monorepo scripts

`packages.sh` at the project root is the **central package registry**. Both `push_all.sh` and `update_all.sh` source it — the package list lives in exactly one place.

When adding a new module, add `"$ROOT/modules/<name>"` to the `PACKAGES` array in `packages.sh` in **alphabetical order** among the other `modules/*` entries (before `framework`, `ez-php`, and the root entry at the end).

---

# Package: ez-php/websocket-client

RFC 6455 WebSocket client — HTTP upgrade handshake, masked frame encoder, plain-TCP (`ws://`) and TLS (`wss://`) transports. Client side only; reuses `Frame`/`Opcode` from `ez-php/websocket`.

---

## Source Structure

```
src/
├── Client.php               — blocking client: connect/over(), send/sendBinary/ping, receive(), close(); auto-pong, fragment reassembly, protocol-error failing
├── Handshake.php            — pure functions: Sec-WebSocket-Key/Accept, upgrade request builder, 101 response validator
├── FrameEncoder.php         — masked client→server frame encoder (Frame::encode() is server-side/unmasked)
├── Message.php              — value object for a complete TEXT/BINARY/CLOSE message
├── Url.php                  — ws:// / wss:// parser (host, port, request target, Host header, socket address)
├── TransportInterface.php   — write / read(timeout) / close byte transport contract
├── StreamTransport.php      — TransportInterface over stream_socket_client(); tcp:// or ssl:// with peer verification on
└── ConnectionException.php  — connection/protocol failures (extends EzPhp\WebSocket\WebSocketException)

tests/
├── TestCase.php                          — Base PHPUnit test case
├── WebsocketClientFakeTransport.php      — in-memory transport: auto-answers the handshake, scripted inbound bytes, records writes
├── WebsocketClientUrlTest.php            — URL parsing and rejection cases
├── WebsocketClientFrameEncoderTest.php   — RFC 6455 §5.7 masked example, length encodings, round trip through Frame::parse()
├── WebsocketClientHandshakeTest.php      — RFC 6455 Accept vector, request headers/injection guards, response validation
├── WebsocketClientClientTest.php         — Client behaviour over the fake transport: control frames, fragmentation, close handshake, protocol failures
├── WebsocketClientStreamTransportTest.php — StreamTransport over socket pairs and loopback TCP
├── WebsocketClientEndToEndTest.php       — real ez-php/websocket Server in a child process: echo, binary, ping, close
└── Support/ws-echo-server.php            — the child process: Server + echo handler
```

---

## Key Classes and Responsibilities

### Client (`src/Client.php`)

Constructed only through `Client::connect($url, headers, protocols, timeout, sslOptions)` or
`Client::over($transport, $url, ...)` (handshake over an existing transport — the seam tests use).
`receive($timeout)` returns the next complete `Message`, or `null` on timeout. Pings are answered
automatically, pongs swallowed, fragments reassembled (16 MiB cap), TEXT validated as UTF-8.
Any server protocol violation (masked frame, bad opcode, orphan continuation, oversized/fragmented
control frame, bad UTF-8, oversized message) sends a close frame with the matching status code
(1002/1007/1009), closes the transport and throws `ConnectionException`. `close()` performs the
closing handshake (sends CLOSE, waits for the peer's CLOSE up to a timeout, then closes) and is idempotent.

### Handshake (`src/Handshake.php`)

Stateless and I/O-free. `buildRequest()` refuses reserved headers (`Host`, `Upgrade`, `Connection`,
`Sec-WebSocket-*`) and CR/LF in names/values. `validateResponse()` requires status 101, `Upgrade`,
`Connection: Upgrade`, and a matching `Sec-WebSocket-Accept` (compared with `hash_equals`); rejects
extensions (none are offered) and unrequested subprotocols; returns the selected subprotocol.
Failures throw `EzPhp\WebSocket\HandshakeException` from `ez-php/websocket` (reused, not duplicated).

### FrameEncoder (`src/FrameEncoder.php`)

Masks every outgoing frame with a fresh `random_bytes(4)` key, per RFC 6455 §5.3.

### StreamTransport (`src/StreamTransport.php`)

Blocking stream; reads are gated by `stream_select()` (and skipped when TLS already buffered bytes).
`wss://` verifies peer and host name by default; `$sslOptions` is merged over those defaults.
PHP warnings from connect/write are captured into `ConnectionException` messages rather than leaking.

---

## Design Decisions and Constraints

- **Partial reuse of `ez-php/websocket`'s `Frame`.** `Frame::parse()` and `Opcode` are reused for
  the receive path (server frames are unmasked, which `parse()` handles). `Frame::encode()` is
  unmasked and server-only, so the send path uses this module's own `FrameEncoder`. `Frame` is
  deliberately not modified — that is another package's public API.
- **`Frame::parse()` silently unmasks**, so `Client::nextFrame()` peeks at the mask bit first and
  fails the connection with 1002 (RFC 6455 §5.1: servers must not mask).
- **Blocking, single-connection API.** No Fibers or event loop: `receive()` blocks up to its
  timeout. Applications multiplexing many sockets should use `ez-php/websocket`'s server-side loop
  or run one client per process/fiber. `Client` is not safe to share between fibers mid-call.
- **No extensions, no permessage-deflate.** The handshake never offers any, and rejects a server
  that answers with one.
- **Oversized *single* frames** are rejected by `Frame::parse()` (16 MiB) and surface as 1002;
  oversized *reassembled* messages use 1009.
- **TLS verification is on by default** and only relaxed by explicit `$sslOptions`.
- **`Client` is `final` with a private constructor**; the `TransportInterface` seam replaces
  subclassing/mocking. `ConnectionException` is non-final per the exception-hierarchy carve-out.
- **Depends on `ez-php/websocket`** (`^2.0`, as `ez-php/websocket-tls` does) for `Frame`, `Opcode`,
  `HandshakeException`, `WebSocketException`; requires `ext-openssl`. No framework dependency.

---

## Testing Approach

No external infrastructure (no MySQL/Redis). Most tests run in-process against
`WebsocketClientFakeTransport` or `stream_socket_pair()`. `WebsocketClientEndToEndTest` spawns a
real `ez-php/websocket` `Server` on a free loopback port in a child process (invisible to the
coverage driver, so the in-process suites carry coverage). `wss://` against a live TLS server is
not exercised here (that needs certificates; `ez-php/websocket-tls` owns the TLS server side) —
only the TLS option handling and failure path are covered.

Test class names are prefixed `WebsocketClient` because all packages share the `Tests\` namespace.

---

## What Does Not Belong Here

- Server-side logic (listeners, channels, handlers) — `ez-php/websocket` / `ez-php/websocket-tls`
- Modifications to `Frame`/`Opcode` — those live in `ez-php/websocket`
- Auto-reconnect, backoff, or heartbeat scheduling — application-level policy
- A framework service provider / static façade — this is a standalone client library
- WebSocket extensions (permessage-deflate) and fragmented *sending*
- Async/evented multiplexing of many connections
