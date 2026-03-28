# php-dev-runtime

`php-dev-runtime` is a development-focused HTTP runtime that uses ReactPHP internally while keeping its public API centered on a conventional request/response model.

- HTTP/1.1 only
- Intended for development and project-local use
- The application boundary is fixed to `ApplicationInterface`
- ReactPHP loop/socket/stream objects are not exposed through the public API
- Promises are allowed, but the primary path is a synchronous `ResponseInterface` return value
- The `serve` command follows an `nghttpd`-style positional argument layout

## Installation

```bash
composer install
```

## Usage

```bash
bin/dev-server serve --no-tls -a 127.0.0.1 examples/hello-app/app.php 8080
```

Options:

- `-a, --address=127.0.0.1`
- `-d, --htdocs=path`
- `--env=dev`
- `--no-debug`
- `--no-tls`
- `--tls-passphrase=secret`

Command layout:

```bash
bin/dev-server serve [OPTION]... <APP> <PORT> [<PRIVATE_KEY> <CERT>]
```

Examples:

```bash
# Plain HTTP
bin/dev-server serve --no-tls examples/hello-app/app.php 8080

# HTTPS with an explicit static directory
bin/dev-server serve \
  -a 127.0.0.1 \
  -d examples/hello-app/public \
  examples/hello-app/app.php \
  8443 \
  localhost-key.pem \
  localhost.pem
```

If `--htdocs` is omitted, the runtime uses the `public/` directory next to the application file.

TLS is enabled by default. Unless `--no-tls` is set, `<PRIVATE_KEY>` and `<CERT>` are required.

## TLS for Development

The runtime only supports HTTPS for local development. It does not attempt to manage certificates for you.

With `mkcert`:

```bash
mkcert localhost 127.0.0.1 ::1
bin/dev-server serve examples/hello-app/app.php 8443 localhost+2-key.pem localhost+2.pem
```

With `openssl`:

```bash
openssl req -x509 -newkey rsa:2048 -nodes \
  -keyout localhost-key.pem \
  -out localhost.pem \
  -days 7 \
  -subj "/CN=localhost"

bin/dev-server serve examples/hello-app/app.php 8443 localhost-key.pem localhost.pem
```

## Application API

```php
<?php

use PhpDevRuntime\Contract\ApplicationInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Promise\PromiseInterface;

interface ApplicationInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface|PromiseInterface;
}
```

In the normal case, return `ResponseInterface` directly. You may return a Promise where needed, but the runtime does not expose the event loop or sockets to application code.

You can also implement lifecycle hooks if needed.

```php
<?php

use PhpDevRuntime\Contract\LifespanInterface;
use PhpDevRuntime\Runtime\RuntimeContext;

interface LifespanInterface
{
    public function startup(RuntimeContext $context): void;
    public function shutdown(RuntimeContext $context): void;
}
```

`RuntimeContext` only carries configuration-like values such as `environment`, `appRoot`, `publicPath`, `host`, `port`, `debug`, and TLS state. It does not include the ReactPHP loop.

## Directory Layout

```text
.
├── bin/
│   └── dev-server
├── examples/
│   └── hello-app/
│       ├── app.php
│       └── public/
│           └── index.html
├── src/
│   ├── Console/
│   │   └── ServeCommand.php
│   ├── Contract/
│   │   ├── ApplicationInterface.php
│   │   └── LifespanInterface.php
│   ├── Http/
│   │   ├── ErrorHandler.php
│   │   ├── RequestHandlerAdapter.php
│   │   ├── RuntimeServer.php
│   │   └── StaticFileMiddleware.php
│   └── Runtime/
│       ├── AppFactory.php
│       ├── RuntimeContext.php
│       └── TlsConfiguration.php
├── composer.json
└── README.md
```

## Implementation Notes

- ReactPHP is contained inside `RuntimeServer`, so the application layer only needs to know about `handle()`.
- `RequestHandlerAdapter` centralizes static file serving, application dispatch, Promise normalization, and exception-to-response conversion.
- TLS termination is handled inside `RuntimeServer`, so application code still sees a normal request/response boundary.
- `StaticFileMiddleware` only serves files from `public/` and does not pass those requests through application code.
- Error pages return minimal development-oriented details. `--no-debug` suppresses those details.
- The CLI only supports `serve`; there is no heavier command framework in this minimal setup.
- The CLI shape intentionally follows `nghttpd`: positional `<PORT>` and TLS key/certificate arguments, with `--no-tls` for plaintext mode.

## Example

`examples/hello-app/app.php` demonstrates:

- A normal synchronous request/response flow
- `startup()` / `shutdown()` lifecycle hooks
- A development error page via `/boom`
- Static file delivery from `public/index.html`

## What Is Intentionally Out of Scope

- HTTP/2
- WebSocket
- SSE
- Hot reload
- Production-oriented tuning
- Advanced middleware pipelines
- DI container integration
- Public exposure of ReactPHP low-level APIs
