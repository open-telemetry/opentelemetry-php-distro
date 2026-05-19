<?php

declare(strict_types=1);

namespace OpenTelemetry\DistroTools\Build;

use OpenTelemetry\Distro\AutoloaderForClassesInDirectory;
use OpenTelemetry\Distro\Log\LogBackend;
use OpenTelemetry\Distro\Log\LogLevel;
use RuntimeException;

const OTEL_PHP_TOOLS_LOG_LEVEL_ENV_VAR_NAME = 'OTEL_PHP_TOOLS_LOG_LEVEL';

require __DIR__ . DIRECTORY_SEPARATOR . 'BuildToolsAssertTrait.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'BuildToolsLogUtil.php';

// __DIR__ is "<repo root>/tools/build"
$repoRootDir = realpath($repoRootDirTempVal = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..');
if ($repoRootDir === false) {
    throw new RuntimeException("realpath returned false for $repoRootDirTempVal");
}

$prodPhpPath = $repoRootDir . DIRECTORY_SEPARATOR . 'prod' . DIRECTORY_SEPARATOR . 'php';
$prodPhpDistroPath = $prodPhpPath . DIRECTORY_SEPARATOR . 'OpenTelemetry' . DIRECTORY_SEPARATOR . 'Distro';
require $prodPhpDistroPath . DIRECTORY_SEPARATOR . 'ProdPhpDir.php';
/** @noinspection PhpFullyQualifiedNameUsageInspection */
\OpenTelemetry\Distro\ProdPhpDir::$fullPath = $prodPhpPath;

require $prodPhpDistroPath . DIRECTORY_SEPARATOR . 'requireAutoloaderForClassesInDirectory.php';

$getMaxEnabledLogLevelConfig = function (): ?LogLevel {
    $envVarVal = getenv(OTEL_PHP_TOOLS_LOG_LEVEL_ENV_VAR_NAME);
    if (!is_string($envVarVal)) {
        return null;
    }
    return LogLevel::tryToFindByName(strtolower($envVarVal));
};
$maxEnabledLogLevel = $getMaxEnabledLogLevelConfig() ?? BuildToolsLogUtil::DEFAULT_LEVEL;

LogBackend::initSingletonInstance(
    new LogBackend(
        maxEnabledLevel: $maxEnabledLogLevel->value,
        sourceCodeRootDirs: [$prodPhpPath, __DIR__],
        formatAndWrite: BuildToolsLogUtil::formatAndWriteForLogBackend(...),
    ),
);

AutoloaderForClassesInDirectory::register(dirRootNamespace: 'OpenTelemetry\\Distro', dirFullPath: $prodPhpDistroPath);
AutoloaderForClassesInDirectory::register(dirRootNamespace: __NAMESPACE__, dirFullPath: __DIR__);
