<?php

declare(strict_types=1);

use OpenTelemetry\Distro\ProdPhpDir;

require __DIR__ . '/OpenTelemetry/Distro/ProdPhpDir.php';
ProdPhpDir::$fullPath = __DIR__;

require __DIR__ . '/OpenTelemetry/Distro/Util/HiddenConstructorTrait.php';
require __DIR__ . '/OpenTelemetry/Distro/PhpPartFacade.php';
