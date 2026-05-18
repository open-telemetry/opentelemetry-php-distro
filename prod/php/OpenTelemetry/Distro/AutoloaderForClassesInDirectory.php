<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro;

use OpenTelemetry\Distro\Log\LogFeature;

final class AutoloaderForClassesInDirectory
{
    use BootstrapStageLoggingClassTrait;

    private readonly int $autoloadFqClassNamePrefixLength;

    private function __construct(
        private readonly string $autoloadFqClassNamePrefix,
        private readonly string $srcFilePathPrefix,
    ) {
        $this->autoloadFqClassNamePrefixLength = strlen($this->autoloadFqClassNamePrefix);
    }

    public static function register(string $dirRootNamespace, string $dirFullPath): void
    {
        $autoloader = new self(autoloadFqClassNamePrefix: $dirRootNamespace . '\\', srcFilePathPrefix: $dirFullPath . DIRECTORY_SEPARATOR);
        spl_autoload_register(($autoloader)->autoloadCodeForClass(...));
    }

    private function shouldAutoloadCodeForClass(string $fqClassName): bool
    {
        // does the class use the namespace prefix?
        return strncmp($this->autoloadFqClassNamePrefix, $fqClassName, $this->autoloadFqClassNamePrefixLength) == 0;
    }

    public function autoloadCodeForClass(string $fqClassName): void
    {
        // Example of $fqClassName: OpenTelemetry\Distro\Autoloader

        self::logTrace(__LINE__, __FUNCTION__, 'Entered', compact('fqClassName'));

        if (!self::shouldAutoloadCodeForClass($fqClassName)) {
            self::logTrace(__LINE__, __FUNCTION__, 'shouldAutoloadCodeForClass returned false', compact('fqClassName'));
            return;
        }

        // get the relative class name
        $relativeClass = substr($fqClassName, $this->autoloadFqClassNamePrefixLength);
        $classSrcFileRelative = ((DIRECTORY_SEPARATOR === '\\')
            ? $relativeClass
            : str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass)) . '.php';
        $classSrcFileAbsolute = $this->srcFilePathPrefix . $classSrcFileRelative;

        if (file_exists($classSrcFileAbsolute)) {
            self::logTrace(__LINE__, __FUNCTION__, 'Before require', compact('classSrcFileAbsolute'));
            require $classSrcFileAbsolute;
            self::logTrace(__LINE__, __FUNCTION__, 'After require', compact('classSrcFileAbsolute'));
        } else {
            self::logTrace(__LINE__, __FUNCTION__, 'File with the code for class does not exist', compact('fqClassName', 'classSrcFileAbsolute'));
        }
    }

    /**
     * Must be defined in class using BootstrapStageLoggingClassTrait
     */
    private static function getCurrentSourceCodeFile(): string
    {
        return __FILE__;
    }

    /**
     * Must be defined in class using BootstrapStageLoggingClassTrait
     */
    private static function getCurrentSourceCodeClass(): string
    {
        return __CLASS__;
    }

    /**
     * Must be defined in class using BootstrapStageLoggingClassTrait
     */
    private static function getCurrentLogFeature(): int
    {
        return LogFeature::BOOTSTRAP;
    }
}
