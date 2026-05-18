<?php

declare(strict_types=1);

namespace OpenTelemetry\DistroTools\Build;

use OpenTelemetry\Distro\AutoloaderForClassesInDirectory;
use OpenTelemetry\Distro\BootstrapStageLogger;
use OpenTelemetry\Distro\Log\LogLevel;
use RuntimeException;

const OTEL_PHP_TOOLS_LOG_LEVEL_ENV_VAR_NAME = 'OTEL_PHP_TOOLS_LOG_LEVEL';

require __DIR__ . DIRECTORY_SEPARATOR . 'BuildToolsAssertTrait.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'BuildToolsLog.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'BuildToolsLoggingClassTrait.php';

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

require $prodPhpDistroPath . DIRECTORY_SEPARATOR . 'Util' . DIRECTORY_SEPARATOR . 'BoolUtil.php';
require $prodPhpDistroPath . '/requireAutoloaderForClassesInDirectory.php';
require $prodPhpDistroPath . '/Util/EnumUtilTrait.php';
require $prodPhpDistroPath . '/Log/LogLevel.php';

$getMaxEnabledLogLevelConfig = function (): ?LogLevel {
    $envVarVal = getenv(OTEL_PHP_TOOLS_LOG_LEVEL_ENV_VAR_NAME);
    if (!is_string($envVarVal)) {
        return null;
    }

    return LogLevel::tryToFindByName(strtolower($envVarVal));
};
$maxEnabledLogLevel = $getMaxEnabledLogLevelConfig() ?? BuildToolsLog::DEFAULT_LEVEL;
BuildToolsLog::configure($maxEnabledLogLevel);

BootstrapStageLogger::configure(
    maxEnabledLevel: $maxEnabledLogLevel->value,
    phpSrcCodeRootDir: $prodPhpDistroPath,
    rootNamespace: __NAMESPACE__,
    formatAndWrite: function (int $level, int $prodLogFeature, string $file, int $line, string $func, string $message): void {
        BuildToolsLog::defaultFormatAndWrite(
            levelString: BootstrapStageLogger::levelIntToString($level),
            featureOrCategoryString: BuildToolsLog::prodLogFeatureIntToString($prodLogFeature),
            file: $file,
            line: $line,
            func: $func,
            messageWithContext: $message
        );
    }
);

AutoloaderForClassesInDirectory::register(dirRootNamespace: 'OpenTelemetry\\Distro', dirFullPath: $prodPhpDistroPath);
AutoloaderForClassesInDirectory::register(dirRootNamespace: __NAMESPACE__, dirFullPath: __DIR__);
