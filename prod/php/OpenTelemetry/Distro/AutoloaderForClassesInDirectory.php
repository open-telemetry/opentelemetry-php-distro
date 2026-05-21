<?php

declare(strict_types=1);

namespace OpenTelemetry\Distro;

use OpenTelemetry\Distro\Log\LoggingClassTrait;
use OpenTelemetry\Distro\Log\LogFeature;
use OpenTelemetry\Distro\Util\DistroRuntimeException;

final class AutoloaderForClassesInDirectory
{
    use LoggingClassTrait;

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
        if (!spl_autoload_register(($autoloader)->autoloadCodeForClass(...))) {
            throw new DistroRuntimeException('spl_autoload_register() returned false', context: compact('dirRootNamespace', 'dirFullPath'));
        }
    }

    private function shouldAutoloadCodeForClass(string $fqClassName): bool
    {
        // does the class use the namespace prefix?
        return strncmp($this->autoloadFqClassNamePrefix, $fqClassName, $this->autoloadFqClassNamePrefixLength) == 0;
    }

    public function autoloadCodeForClass(string $fqClassName): void
    {
        // Example of $fqClassName: OpenTelemetry\Distro\Autoloader

        $logTrace = self::logTrace(__FUNCTION__);
        $logTrace?->with(__LINE__, 'Entered', compact('fqClassName'));

        if (!self::shouldAutoloadCodeForClass($fqClassName)) {
            $logTrace?->with(__LINE__, 'shouldAutoloadCodeForClass returned false', compact('fqClassName'));
            return;
        }

        // get the relative class name
        $relativeClass = substr($fqClassName, $this->autoloadFqClassNamePrefixLength);
        $classSrcFileRelative = ((DIRECTORY_SEPARATOR === '\\')
            ? $relativeClass
            : str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass)) . '.php';
        $classSrcFileAbsolute = $this->srcFilePathPrefix . $classSrcFileRelative;

        if (file_exists($classSrcFileAbsolute)) {
            $logTrace?->with(__LINE__, 'Before require', compact('classSrcFileAbsolute'));
            require $classSrcFileAbsolute;
            $logTrace?->with(__LINE__, 'After require', compact('classSrcFileAbsolute'));
        } else {
            $logTrace?->with(__LINE__, 'File with the code for class does not exist', compact('fqClassName', 'classSrcFileAbsolute'));
        }
    }

    /**
     * Must be defined in class using LoggingClassTrait
     */
    private static function getCurrentSourceCodeFile(): string
    {
        return __FILE__;
    }

    /**
     * Must be defined in class using LoggingClassTrait
     */
    private static function getCurrentOptionalLogProdFeatureIntOrCategoryString(): int
    {
        return LogFeature::BOOTSTRAP;
    }
}
