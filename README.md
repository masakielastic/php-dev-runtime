# php-dev-runtime

`php-dev-runtime` is a development-focused HTTP runtime that uses ReactPHP internally while keeping its public API centered on a conventional request/response model.

- HTTP/1.1 only
- Intended for development and project-local use
- The application boundary is fixed to `ApplicationInterface`
- ReactPHP loop/socket/stream objects are not exposed through the public API
- Promises are allowed, but the primary path is a synchronous `ResponseInterface` return value

## Installation

```bash
composer install
```

## Usage

```bash
bin/dev-server serve examples/hello-app/app.php --host=127.0.0.1 --port=8080
```

Options:

- `--host=127.0.0.1`
- `--port=8080`
- `--public=path`
- `--env=dev`
- `--no-debug`

If `--public` is omitted, the runtime uses the `public/` directory next to the application file.

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

`RuntimeContext` only carries configuration-like values such as `environment`, `appRoot`, `publicPath`, `host`, `port`, and `debug`. It does not include the ReactPHP loop.

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
│       └── RuntimeContext.php
├── composer.json
└── README.md
```

## Implementation Notes

- ReactPHP is contained inside `RuntimeServer`, so the application layer only needs to know about `handle()`.
- `RequestHandlerAdapter` centralizes static file serving, application dispatch, Promise normalization, and exception-to-response conversion.
- `StaticFileMiddleware` only serves files from `public/` and does not pass those requests through application code.
- Error pages return minimal development-oriented details. `--no-debug` suppresses those details.
- The CLI only supports `serve`; there is no heavier command framework in this minimal setup.

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
