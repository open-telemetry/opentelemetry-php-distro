<?php

declare(strict_types=1);

use OpenTelemetry\Distro\ProdPhpDir;

require __DIR__ . '/OpenTelemety/Distro/ProdPhpDir.php';
ProdPhpDir::$fullPath = __DIR__;

require __DIR__ . '/OpenTelemety/Distro/Util/HiddenConstructorTrait.php';
require __DIR__ . '/OpenTelemety/Distro/PhpPartFacade.php';
