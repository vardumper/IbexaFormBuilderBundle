<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

final class IbexaFormBuilderBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
