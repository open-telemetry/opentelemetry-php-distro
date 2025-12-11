<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro;

final class AutoloaderDistroOTelClasses
{
    private readonly string $autoloadFqClassNamePrefix;
    private readonly int $autoloadFqClassNamePrefixLength;
    private readonly string $srcFilePathPrefix;

    private function __construct(string $rootNamespace, string $rootNamespaceDir)
    {
        $this->autoloadFqClassNamePrefix = $rootNamespace . '\\';
        $this->autoloadFqClassNamePrefixLength = strlen($this->autoloadFqClassNamePrefix);
        $this->srcFilePathPrefix = $rootNamespaceDir . DIRECTORY_SEPARATOR;
    }

    public static function register(string $rootNamespace, string $rootNamespaceDir): void
    {
        spl_autoload_register((new self($rootNamespace, $rootNamespaceDir))->autoloadCodeForClass(...));
    }

    private function shouldAutoloadCodeForClass(string $fqClassName): bool
    {
        // does the class use the namespace prefix?
        return strncmp($this->autoloadFqClassNamePrefix, $fqClassName, $this->autoloadFqClassNamePrefixLength) == 0;
    }

    public function autoloadCodeForClass(string $fqClassName): void
    {
        // Example of $fqClassName: OpenTelemetry\Distro\Autoloader

        BootstrapStageLogger::logTrace("Entered with fqClassName: `$fqClassName'", __FILE__, __LINE__, __CLASS__, __FUNCTION__);

        if (!self::shouldAutoloadCodeForClass($fqClassName)) {
            BootstrapStageLogger::logTrace(
                "shouldAutoloadCodeForClass returned false. fqClassName: $fqClassName",
                __FILE__,
                __LINE__,
                __CLASS__,
                __FUNCTION__
            );
            return;
        }

        // get the relative class name
        $relativeClass = substr($fqClassName, $this->autoloadFqClassNamePrefixLength);
        $classSrcFileRelative = ((DIRECTORY_SEPARATOR === '\\')
            ? $relativeClass
            : str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass)) . '.php';
        $classSrcFileAbsolute = $this->srcFilePathPrefix . $classSrcFileRelative;

        if (file_exists($classSrcFileAbsolute)) {
            BootstrapStageLogger::logTrace(
                "Before require `$classSrcFileAbsolute' ...",
                __FILE__,
                __LINE__,
                __CLASS__,
                __FUNCTION__
            );

            require $classSrcFileAbsolute;

            BootstrapStageLogger::logTrace(
                "After require `$classSrcFileAbsolute' ...",
                __FILE__,
                __LINE__,
                __CLASS__,
                __FUNCTION__
            );
        } else {
            BootstrapStageLogger::logTrace(
                "File with the code for class doesn't exist."
                    . " classSrcFile: `$classSrcFileAbsolute'. fqClassName: `$fqClassName'",
                __FILE__,
                __LINE__,
                __CLASS__,
                __FUNCTION__
            );
        }
    }
}
