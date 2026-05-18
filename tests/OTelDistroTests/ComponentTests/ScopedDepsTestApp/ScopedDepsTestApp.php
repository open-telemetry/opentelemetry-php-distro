<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\ScopedDepsTestApp;

use Closure;
use OpenTelemetry\Distro\BootstrapStageLogger;
use OpenTelemetry\Distro\Log\SplAutoloadFunctionsLogUtil;
use ReflectionClass;
use ReflectionFunction;
use RuntimeException;
use Throwable;

final class ScopedDepsTestApp
{
    private static ?int $maxEnabledLogLevel = null;

    private static function writeLineToStdErr(string $text): void
    {
        /** @var ?bool $isStdErrDefined */
        static $isStdErrDefined = null;
        if ($isStdErrDefined === null) {
            if (defined('STDERR')) {
                $isStdErrDefined = true;
            } else {
                $openedFileResource = fopen('php://stderr', 'w');
                $isStdErrDefined = is_resource($openedFileResource);
                if ($isStdErrDefined) {
                    define('STDERR', $openedFileResource);
                }
            }
        }

        if ($isStdErrDefined) {
            fwrite(STDERR, $text . PHP_EOL);
        }
    }

    private static function requirePhpFileOutsideApp(string $pathFromRepoRoot): void
    {
        /** @var ?string $testsRepoRootDirPath */
        static $testsRepoRootDirPath = null;
        if ($testsRepoRootDirPath === null) {
            $testsRepoRootDirPath = self::getEnvVar(ScopedDepsTestShared::buildEnvVarName(ScopedDepsTestShared::TESTS_REPO_ROOT_DIR_PATH_ENV_VAR_NAME_SUFFIX));
        }

        require $testsRepoRootDirPath . DIRECTORY_SEPARATOR . $pathFromRepoRoot;
    }

    private static function parseLogLevelConfig(): void
    {
        if (!class_exists(BootstrapStageLogger::class)) {
            self::requirePhpFileOutsideApp(pathFromRepoRoot: 'prod/php/OpenTelemetry/Distro/requireBootstrapStageLogger.php');
        }

        self::$maxEnabledLogLevel = BootstrapStageLogger::levelStringToInt(
            self::getEnvVar(ScopedDepsTestShared::buildEnvVarName(ScopedDepsTestShared::LOG_LEVEL_ENV_VAR_NAME_SUFFIX))
        );

        self::logDebug(__LINE__, __FUNCTION__, 'maxEnabledLogLevel: ' . (self::$maxEnabledLogLevel === null ? 'null' : BootstrapStageLogger::levelIntToString(self::$maxEnabledLogLevel)));
    }

    private static function logIfClassesExist(): void
    {
        foreach (ScopedDepsTestShared::ALL_CLASS_NAMES as $fqClassName) {
            $msgSuffix = class_exists($fqClassName, autoload: false)
                ? 'exists (source code file: ' . (new ReflectionClass($fqClassName))->getFileName() . ')'
                : 'does NOT exist';
            self::logDebug(__LINE__, __FUNCTION__, 'class ' . $fqClassName . ' ' . $msgSuffix);
        }
    }

    private static function logAutoloadFunctions(string $message): void
    {
        if (!class_exists(SplAutoloadFunctionsLogUtil::class)) {
            self::requirePhpFileOutsideApp(pathFromRepoRoot: 'prod/php/OpenTelemetry/Distro/SplAutoloadFunctionsLogUtil.php');
        }

        /**
         * @var int $logLevel
         *
         * @noinspection PhpRedundantVariableDocTypeInspection
         */
        static $logLevel = BootstrapStageLogger::LEVEL_DEBUG;
        if (self::isLogEnabledForLevel($logLevel)) {
            self::logWithLevel($logLevel, __LINE__, __FUNCTION__, $message, ['spl_autoload_functions()' => SplAutoloadFunctionsLogUtil::callbacksToLoggable(spl_autoload_functions())]);
        }
    }

    private static function logInitialState(): void
    {
        self::logAutoloadFunctions('Initial state');
        self::logIfClassesExist();
    }

    /**
     * @param array<string, mixed>  $context
     */
    private static function concatMessageAndContext(string $message, array $context = []): string
    {
        return $message . (count($context) === 0 ? '' : (' | ' . json_encode($context)));
    }

    private static function isLogEnabledForLevel(int $level): bool
    {
        return $level <= self::$maxEnabledLogLevel;
    }

    /**
     * @param array<string, mixed>  $context
     */
    private static function logWithLevel(int $level, int $srcCodeLine, string $srcCodeFunc, string $message, array $context = []): void
    {
        if (!self::isLogEnabledForLevel($level)) {
            return;
        }

        $formattedStatement = ScopedDepsTestShared::APP_LOG_LINE_PREFIX;
        $formattedStatement .=  ' ' . '[' . BootstrapStageLogger::levelIntToString($level) . ']';
        $formattedStatement .=  ' ' . '[' . basename(__FILE__) . ':' . $srcCodeLine . ']';
        $formattedStatement .=  ' ' . '[' . $srcCodeFunc . ']';
        $formattedStatement .=  ' ' . self::concatMessageAndContext($message, $context);
        self::writeLineToStdErr($formattedStatement);
    }

    /**
     * @param array<string, mixed>  $context
     *
     * @noinspection PhpSameParameterValueInspection
     */
    private static function logTrace(int $srcCodeLine, string $srcCodeFunc, string $message, array $context = []): void
    {
        self::logWithLevel(BootstrapStageLogger::LEVEL_TRACE, $srcCodeLine, $srcCodeFunc, $message, $context);
    }

    /**
     * @param array<string, mixed>  $context
     *
     * @noinspection PhpSameParameterValueInspection
     */
    private static function logDebug(int $srcCodeLine, string $srcCodeFunc, string $message, array $context = []): void
    {
        self::logWithLevel(BootstrapStageLogger::LEVEL_DEBUG, $srcCodeLine, $srcCodeFunc, $message, $context);
    }

    /**
     * @param array<string, mixed>  $context
     *
     * @noinspection PhpSameParameterValueInspection
     */
    private static function logWarning(int $srcCodeLine, string $srcCodeFunc, string $message, array $context = []): void
    {
        self::logWithLevel(BootstrapStageLogger::LEVEL_WARNING, $srcCodeLine, $srcCodeFunc, $message, $context);
    }

    /**
     * @param array<string, mixed>  $context
     *
     * @phpstan-assert true $cond
     * @phpstan-return ($cond is true ? void : never)
     */
    public static function assert(bool $cond, string $failedMsg, array $context = [])
    {
        if (!$cond) {
            throw new RuntimeException(self::concatMessageAndContext($failedMsg, $context));
        }
    }

    /**
     * @param mixed $actualVal
     *
     * @return array<array-key, mixed>
     */
    public static function assertIsArray(mixed $actualVal): array
    {
        self::assert(is_array($actualVal), "value is not an array", ["value type" => get_debug_type($actualVal), 'value' => $actualVal]);
        /** @var array<array-key, mixed> $actualVal */
        return $actualVal;
    }

    public static function assertIsString(mixed $actualVal): string
    {
        self::assert(is_string($actualVal), "value is not an string", ["value type" => get_debug_type($actualVal), 'value' => $actualVal]);
        /** @var string $actualVal */
        return $actualVal;
    }

    /**
     * @template T of numeric|string|object|resource
     *
     * @param T|false $actualVal
     *
     * @return T
     *
     * @phpstan-assert !false $actualVal
     */
    public static function assertNotFalse(mixed $actualVal): mixed
    {
        self::assert($actualVal !== false, "value is false");
        /** @var T $actualVal */
        return $actualVal;
    }

    /**
     * @template T of numeric|string|object|resource
     *
     * @param ?T $actualVal
     *
     * @return T
     *
     * @phpstan-assert !null $actualVal
     */
    public static function assertIsNotNull(mixed $actualVal): mixed
    {
        self::assert($actualVal !== null, "value is null");
        /** @var T $actualVal */
        return $actualVal;
    }

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, TValue> $array
     *
     * @return TValue
     */
    public static function assertArrayHasKey(array $array, string $key): mixed
    {
        self::assert(array_key_exists($key, $array), "array does not have key $key", compact('array', 'key'));
        return $array[$key];
    }

    private static function getEnvVar(string $envVarName): string
    {
        $envVarVal = getenv($envVarName);
        self::assert(is_string($envVarVal), 'getenv() return value is not a string', compact('envVarName', 'envVarVal') + ['envVarVal type' => get_debug_type($envVarVal)]);
        /**  */
        return $envVarVal;
    }

    private static function stringToBool(string $boolAsString): bool
    {
        /** @var list<string> $trueValues */
        static $trueValues = ['true', 'yes', 'on', '1'];
        if (in_array($boolAsString, $trueValues, strict: true)) {
            return true;
        }
        return false;
    }

    private static function isDistroEnabled(): bool
    {
        /** @noinspection PhpFullyQualifiedNameUsageInspection */
        return (bool)\OpenTelemetry\Distro\get_config_option_by_name(ScopedDepsTestShared::DISTRO_ENABLED_CFG_OPT_NAME);
    }

    private static function isScopingEnabled(): bool
    {
        /** @noinspection PhpFullyQualifiedNameUsageInspection */
        return (bool)\OpenTelemetry\Distro\get_config_option_by_name(ScopedDepsTestShared::DEBUG_SCOPER_ENABLED_CFG_OPT_NAME);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $unscopedClassName
     *
     * @return class-string<T>
     */
    private static function adaptClassNameScoping(string $unscopedClassName, bool $isScoped): string
    {
        return ($isScoped ? (ScopedDepsTestShared::SCOPING_PREFIX . '\\') : '') . $unscopedClassName; // @phpstan-ignore return.type
    }

    private static function putFileContents(string $filePath, string $contents): void
    {
        $filePutContentsRetVal = file_put_contents($filePath, $contents);
        self::assert(is_int($filePutContentsRetVal), 'file_put_contents return value is not int', compact('filePutContentsRetVal'));
        $numberOfBytesWritten = intval($filePutContentsRetVal);
        $numberOfBytesInContents = strlen($contents);
        self::assert($numberOfBytesInContents === $numberOfBytesWritten, '', compact('numberOfBytesInContents', 'numberOfBytesWritten', 'contents'));
    }

    private static function normalizePath(string $path): string
    {
        return self::assertIsString(realpath($path));
    }

    private static function getDistroVendorDir(): string
    {
        /** @noinspection PhpFullyQualifiedNameUsageInspection */
        return self::normalizePath(self::adaptClassNameScoping(\OpenTelemetry\Distro\VendorDir::class, isScoped: self::isScopingEnabled())::$fullPath);
    }

    private static function getAppVendorDir(): string
    {
        return self::normalizePath(__DIR__ . DIRECTORY_SEPARATOR . 'vendor');
    }

    private static function getInstalledVersion(string $packageName, string $vendorDir): string
    {
        $installedPhpFilePath = $vendorDir . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.php';
        self::assert(file_exists($installedPhpFilePath), "$installedPhpFilePath file does not exist", compact('installedPhpFilePath'));
        $installedMap = require($installedPhpFilePath);
        $versionsSection = self::assertArrayHasKey(self::assertIsArray($installedMap), 'versions');
        $packageSection = self::assertArrayHasKey(self::assertIsArray($versionsSection), $packageName);
        return self::assertIsString(self::assertArrayHasKey(self::assertIsArray($packageSection), 'pretty_version'));
    }

    /**
     * @return array<string, array<'Distro'|'App', string>>
     */
    private static function generatePackagesVersions(): array
    {
        $isWithDistroVariants = [false];
        if (self::isDistroEnabled()) {
            $isWithDistroVariants[] = true;
        }
        $result = [];
        foreach (ScopedDepsTestShared::ALL_PACKAGE_NAMES as $packageName) {
            $result[$packageName] = [];
            foreach ($isWithDistroVariants as $isWithDistro) {
                $result[$packageName][ScopedDepsTestShared::buildDistroOrAppKey($isWithDistro)]
                    = self::getInstalledVersion($packageName, $isWithDistro ? self::getDistroVendorDir() : self::getAppVendorDir());
            }
        }
        return $result;
    }

    /**
     * @return array<string, array<'scoped'|'not scoped', string>>
     */
    private static function generateClassesSourceCodeFilesPaths(): array
    {
        $isScopedVariants = [false];
        if (self::isDistroEnabled() && self::isScopingEnabled()) {
            $isScopedVariants[] = true;
        }
        $result = [];
        foreach (ScopedDepsTestShared::ALL_CLASS_NAMES as $fqClassName) {
            $result[$fqClassName] = [];
            foreach ($isScopedVariants as $isScoped) {
                $reflClass = new ReflectionClass(self::adaptClassNameScoping($fqClassName, $isScoped));
                $result[$fqClassName][ScopedDepsTestShared::buildScopedKey($isScoped)] = self::assertIsString($reflClass->getFileName());
            }
        }
        return $result;
    }

    /**
     * @return array<'scoped'|'not scoped', bool>
     */
    private static function generatePsrLogHasReturnType(): array
    {
        $isScopedVariants = [false];
        if (self::isDistroEnabled() && self::isScopingEnabled()) {
            $isScopedVariants[] = true;
        }

        $result = [];
        foreach ($isScopedVariants as $isScoped) {
            $reflClass = new ReflectionClass(self::adaptClassNameScoping(ScopedDepsTestShared::PSR_LOG_ABSTRACTLOGGER_CLASS_NAME, $isScoped));
            /**
             * @see https://github.com/php-fig/log/blob/2.0.0/src/LoggerTrait.php#L23
             * @see https://github.com/php-fig/log/blob/3.0.0/src/LoggerTrait.php#L23
             */
            $result[ScopedDepsTestShared::buildScopedKey($isScoped)] = ($reflClass->getMethod(ScopedDepsTestShared::PSR_LOG_ABSTRACT_LOGGER_METHOD_NAME)->getReturnType() !== null);
        }
        return $result;
    }

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @phpstan-param TKey                 $key
     * @phpstan-param TValue               $value
     * @phpstan-param array<TKey, TValue> &$array
     */
    public static function addAssertingKeyNew(string|int $key, mixed $value, /* in,out */ array &$array): void
    {
        self::assert(!array_key_exists($key, $array), 'array already has the key', compact('key', 'value', 'array'));
        $array[$key] = $value;
    }

    private static function toJsonEncodable(mixed $val): mixed
    {
        if (is_scalar($val) || ($val === null)) {
            return $val;
        }

        if ($val instanceof Closure) {
            return ['class' => get_class($val), 'source code file' => (new ReflectionFunction($val))->getFileName()];
        }

        if (is_object($val)) {
            return ['class' => get_class($val), 'source code file' => (new ReflectionClass($val))->getFileName()];
        }

        if (is_array($val)) {
            return array_map(fn($arrVal) => self::toJsonEncodable($arrVal), $val);
        }

        return ['type' => get_debug_type($val)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function generateAuxOutput(): array
    {
        $appCodeAuxOutput = [
            'maxEnabledLogLevel' => BootstrapStageLogger::levelIntToString(self::assertIsNotNull(self::$maxEnabledLogLevel)),
            ScopedDepsTestShared::DISTRO_ENABLED_CFG_OPT_NAME => self::isDistroEnabled(),
            ScopedDepsTestShared::DEBUG_SCOPER_ENABLED_CFG_OPT_NAME => self::isScopingEnabled(),
            ScopedDepsTestShared::APP_VENDOR_DIR_PATH_KEY => self::getAppVendorDir(),
            'spl_autoload_functions()' => self::toJsonEncodable(spl_autoload_functions()),
            'spl_autoload_extensions()' => spl_autoload_extensions(),
        ];

        if (self::isDistroEnabled()) {
            $appCodeAuxOutput[ScopedDepsTestShared::DISTRO_VENDOR_DIR_PATH_KEY] = self::getDistroVendorDir();
        }

        self::addAssertingKeyNew(ScopedDepsTestShared::PACKAGES_VERSIONS_KEY, self::generatePackagesVersions(), /* ref */ $appCodeAuxOutput);
        self::addAssertingKeyNew(ScopedDepsTestShared::CLASSES_SOURCE_CODE_FILES_PATHS_KEY, self::generateClassesSourceCodeFilesPaths(), /* ref */ $appCodeAuxOutput);
        self::addAssertingKeyNew(ScopedDepsTestShared::PSR_LOG_HAS_RETURN_TYPE_KEY, self::generatePsrLogHasReturnType(), /* ref */ $appCodeAuxOutput);

        return $appCodeAuxOutput;
    }

    private static function logClassAutoload(string $fqClassName): void
    {
        self::logTrace(__LINE__, __FUNCTION__, 'Trying to autoload class ' . $fqClassName);
    }

    /**
     * @throws Throwable
     */
    private static function usePsrLoggerImpl(bool $isCompatibleWithNewPsrLog): void
    {
        if ($isCompatibleWithNewPsrLog) {
            require __DIR__ . DIRECTORY_SEPARATOR . 'CompatibleWithPsrLogReturnType.php';
            $logger = new CompatibleWithPsrLogReturnType();
        } else {
            require __DIR__ . DIRECTORY_SEPARATOR . 'IncompatibleWithPsrLogReturnType.php';
            $logger = new IncompatibleWithPsrLogReturnType();
        }
        $logger->debug('Dummy message');
    }

    public static function run(): void
    {
        require __DIR__ . DIRECTORY_SEPARATOR . 'ScopedDepsTestShared.php';

        self::parseLogLevelConfig();

        self::logInitialState();

        if (self::isLogEnabledForLevel(BootstrapStageLogger::LEVEL_TRACE)) {
            spl_autoload_register(self::logClassAutoload(...), prepend: true);
            self::logAutoloadFunctions('After registering self::logClassAutoload()');
        }

        $appAutoloadPhp = __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        require $appAutoloadPhp;

        self::logAutoloadFunctions('After requiring ' . $appAutoloadPhp);

        $appCodeAuxOutput = self::generateAuxOutput();

        $appCodeAuxOutputFilePath = self::getEnvVar(ScopedDepsTestShared::buildEnvVarName(ScopedDepsTestShared::APP_CODE_AUX_OUTPUT_FILE_PATH_ENV_VAR_NAME_SUFFIX));
        self::putFileContents($appCodeAuxOutputFilePath, self::assertIsString(json_encode($appCodeAuxOutput)));
        self::logDebug(__LINE__, __FUNCTION__, 'Written app code aux output', compact('appCodeAuxOutput', 'appCodeAuxOutputFilePath'));

        $isCompatibleWithNewPsrLog =
            self::stringToBool(self::getEnvVar(ScopedDepsTestShared::buildEnvVarName(ScopedDepsTestShared::IS_APP_COMPATIBLE_WITH_PSR_LOG_RETURN_TYPE_ENV_VAR_NAME_SUFFIX)));
        if (!$isCompatibleWithNewPsrLog) {
            self::logWarning(__LINE__, __FUNCTION__, 'About to use psr/log in a way that is expected to fail...');
        }
        self::usePsrLoggerImpl($isCompatibleWithNewPsrLog);
    }
}
