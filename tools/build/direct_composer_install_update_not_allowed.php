<?php

declare(strict_types=1);

namespace OpenTelemetry\DistroTools\Build;

use OpenTelemetry\Distro\Log\LogBackend;
use OpenTelemetry\Distro\Log\LogLevel;

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap_build_tools.php';

if (ComposerUtil::shouldAllowDirectCommand()) {
    exit();
}

/** @var list<string> $argv */
global $argv;

/**
 * @param array<string, mixed> $context
 */
$logError = function (int $line, string $msg, array $context = []): void {
    /** @var array<string, mixed> $context */
    LogBackend::getSingletonInstance()->write(file: __FILE__, line: $line, func: '', featureOrCategory: null, level: LogLevel::error, message: $msg, context: $context);
};

if (count($argv) !== 2) {
    if (count($argv) < 2) {
        /** @noinspection RequiredAttributes */
        $logError(__LINE__, 'Missing command line argument: <composer command>', compact('argv'));
        exit(BuildToolsUtil::FAILURE_EXIT_CODE);
    }
    $logError(__LINE__, 'Provided more command line arguments than expected', ['expected number of arguments' => 1] + compact('argv'));
    exit(BuildToolsUtil::FAILURE_EXIT_CODE);
}

$usedComposerCommand = $argv[1];
const COMMAND_TO_USE_INSTEAD_OF_COMPOSER_INSTALL = './tools/build/install_PHP_deps_in_dev_env.sh';
$cmdToUseInstead = match ($usedComposerCommand) {
    'install' => COMMAND_TO_USE_INSTEAD_OF_COMPOSER_INSTALL,
    'update' => './tools/build/generate_composer_lock_files.sh && ' . COMMAND_TO_USE_INSTEAD_OF_COMPOSER_INSTALL,
    default => null,
};

if ($cmdToUseInstead === null) {
    $logError(__LINE__, "Unexpected composer command: $usedComposerCommand");
} else {
    BuildToolsLogUtil::writeLine("Direct `composer $usedComposerCommand' is not allowed");
    BuildToolsLogUtil::writeLine('Instead use');
    BuildToolsLogUtil::writeLine("\t" . $cmdToUseInstead);
}

exit(BuildToolsUtil::FAILURE_EXIT_CODE);
