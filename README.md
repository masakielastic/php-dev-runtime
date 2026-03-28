# php-dev-runtime

`php-dev-runtime` は、ReactPHP を内部実装に使いながら、公開 API は通常の request/response に寄せた開発用 HTTP ランタイムです。

- 対象は HTTP/1.1 のみ
- 用途は開発用・プロジェクト限定
- アプリ境界は `ApplicationInterface` に固定
- ReactPHP の loop/socket/stream は公開 API に出さない
- Promise は許容するが、通常利用では同期的な `ResponseInterface` 返却を主経路にする

## インストール

```bash
composer install
```

## 使い方

```bash
bin/dev-server serve examples/hello-app/app.php --host=127.0.0.1 --port=8080
```

オプション:

- `--host=127.0.0.1`
- `--port=8080`
- `--public=path`
- `--env=dev`
- `--no-debug`

`--public` を省略した場合は、アプリファイルと同じディレクトリ配下の `public/` を利用します。

## アプリケーション API

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

通常は `ResponseInterface` をそのまま返してください。必要な箇所だけ Promise を返せますが、ランタイム外へ event loop や socket は露出しません。

任意でライフサイクルも実装できます。

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

`RuntimeContext` は `environment`、`appRoot`、`publicPath`、`host`、`port`、`debug` のような設定値だけを持ち、ReactPHP の loop は含みません。

## ディレクトリ構成

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

## 実装方針

- ReactPHP は `RuntimeServer` に閉じ込め、アプリ層は `handle()` だけを知ればよい構成にしています。
- `RequestHandlerAdapter` が static file 配信、アプリ呼び出し、Promise の吸収、例外時のエラーレスポンス生成をまとめて担当します。
- `StaticFileMiddleware` は `public/` 配下だけを配信し、アプリケーションコードを経由させません。
- エラーページは開発向けに最小限の詳細を返します。`--no-debug` では詳細を抑制します。
- CLI は `serve` のみで、複雑なコマンドフレームワークは入れていません。

## サンプル

`examples/hello-app/app.php` は以下を示します。

- 通常の同期的 request/response
- `startup()` / `shutdown()` の利用
- `/boom` での開発向けエラーページ
- `public/index.html` の静的配信

## 今回 intentionally 含めていないもの

- HTTP/2
- WebSocket
- SSE
- ホットリロード
- 本番向けチューニング
- 高度なミドルウェアパイプライン
- DI コンテナ統合
- ReactPHP の低レベル API の公開
