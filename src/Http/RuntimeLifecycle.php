<?php

declare(strict_types=1);

namespace PhpDevRuntime\Http;

use PhpDevRuntime\Contract\ApplicationInterface;
use PhpDevRuntime\Contract\LifespanInterface;
use PhpDevRuntime\Runtime\RuntimeContext;
use Throwable;

final class RuntimeLifecycle
{
    public function __construct(
        private ApplicationInterface $application,
        private RuntimeContext $context,
    ) {
    }

    public function startup(): void
    {
        if (!$this->application instanceof LifespanInterface) {
            return;
        }

        $this->application->startup($this->context);
    }

    public function shutdown(): void
    {
        if (!$this->application instanceof LifespanInterface) {
            return;
        }

        try {
            $this->application->shutdown($this->context);
        } catch (Throwable $exception) {
            fwrite(STDERR, sprintf("Shutdown error: %s\n", $exception->getMessage()));
        }
    }
}
