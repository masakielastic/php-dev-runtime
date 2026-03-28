<?php

declare(strict_types=1);

namespace PhpDevRuntime\Contract;

use PhpDevRuntime\Runtime\RuntimeContext;

interface LifespanInterface
{
    public function startup(RuntimeContext $context): void;

    public function shutdown(RuntimeContext $context): void;
}
