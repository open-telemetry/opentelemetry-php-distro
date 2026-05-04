<?php

declare(strict_types=1);

use OTelDistroTests\ComponentTests\UserBootstrapPhpFileShared;

require __DIR__ . DIRECTORY_SEPARATOR . 'UserBootstrapPhpFileShared.php';

$GLOBALS[UserBootstrapPhpFileShared::GLOBALS_KEY] = UserBootstrapPhpFileShared::GLOBALS_VALUE;
