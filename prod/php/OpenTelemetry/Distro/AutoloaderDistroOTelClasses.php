<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro;

final class AutoloaderDistroOTelClasses
{
    use BootstrapStageLoggingClassTrait;

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
}
