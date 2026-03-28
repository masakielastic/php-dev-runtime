<?php

declare(strict_types=1);

namespace PhpDevRuntime\Console;

use PhpDevRuntime\Http\RuntimeServer;
use PhpDevRuntime\Runtime\AppFactory;
use PhpDevRuntime\Runtime\RuntimeContext;
use Throwable;

final class ServeCommand
{
    public function __construct(
        private AppFactory $appFactory = new AppFactory(),
        private ServeOptionsParser $optionsParser = new ServeOptionsParser(),
        private ReloadSupervisor $reloadSupervisor = new ReloadSupervisor(),
    )
    {
    }

    public function run(array $argv): int
    {
        $args = $argv;
        array_shift($args);
        $command = array_shift($args);

        if ($command !== 'serve') {
            $this->printUsage();

            return $command === null ? 0 : 1;
        }

        try {
            $options = $this->optionsParser->parse($args);

            if ($this->reloadSupervisor->shouldSupervise($options)) {
                return $this->reloadSupervisor->run($argv, $options->reload);
            }

            $application = $this->appFactory->createFromFile($options->appFile);
            $context = new RuntimeContext(
                $options->environment,
                dirname(realpath($options->appFile) ?: $options->appFile),
                $options->publicPath,
                $options->host,
                $options->port,
                $options->debug,
                $options->tls,
                $options->protocol,
                $options->reload,
            );

            (new RuntimeServer($application, $context))->run();

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, sprintf("Failed to start server: %s\n", $exception->getMessage()));

            return 1;
        }
    }

    private function printUsage(): void
    {
        $usage = <<<TXT
Usage:
  bin/dev-server serve [OPTION]... <APP> <PORT> [<PRIVATE_KEY> <CERT>]

Example:
  bin/dev-server serve --no-tls -a 127.0.0.1 examples/hello-app/app.php 8080
  bin/dev-server serve -a 127.0.0.1 -d examples/hello-app/public examples/hello-app/app.php 8443 localhost-key.pem localhost.pem

Options:
  -a, --address=<ADDR>       Bind to the given address. Default: 127.0.0.1
  -d, --htdocs=<PATH>        Serve static files from the given directory
      --http2                Serve HTTP/2 instead of HTTP/1.1
      --reload               Restart the server process when files change
      --reload-interval=<MS> Polling interval for reload detection. Default: 500
      --env=<ENV>            Runtime environment name. Default: dev
      --no-debug             Disable detailed development error pages
      --no-tls               Disable TLS and serve plain HTTP
      --tls-passphrase=<PW>  Passphrase for the encrypted private key
TXT;

        fwrite(STDERR, $usage . "\n");
    }
}
