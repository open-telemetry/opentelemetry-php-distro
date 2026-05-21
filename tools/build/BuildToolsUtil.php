<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\DistroTools\Build;

use DirectoryIterator;
use JsonException;
use OpenTelemetry\Distro\Log\LogBackend;
use OpenTelemetry\Distro\Log\LoggingClassTrait;
use OpenTelemetry\Distro\Log\LogLevel;
use OpenTelemetry\Distro\Util\BoolUtil;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type EnvVars array<string, string>
 *
 * @phpstan-type UnsignedByte int<0, 255>
 */
final class BuildToolsUtil
{
    use BuildToolsAssertTrait;
    use LoggingClassTrait;

    public const DEFAULT_CONFIG_ENV_VAR_NAME_PREFIX = 'OTEL_PHP_TOOLS_';

    private const KEEP_TEMP_FILES_ENV_VAR_NAME_SUFFIX = 'KEEP_TEMP_FILES';

    public const FAILURE_EXIT_CODE = 1;

    private static string $configEnvVarPrefix = self::DEFAULT_CONFIG_ENV_VAR_NAME_PREFIX;

    public static function setConfigEnvVarPrefix(string $envVarNameSuffix): void
    {
        self::$configEnvVarPrefix = $envVarNameSuffix;
    }

    private static function buildConfigEnvVar(string $envVarNameSuffix): string
    {
        return self::$configEnvVarPrefix . $envVarNameSuffix;
    }

    /**
     * @param callable(): void $code
     */
    public static function runCmdLineImpl(string $calledFromFqMethod, callable $code): void
    {
        $logDebug = self::logDebug(__FUNCTION__);
        $logDebug?->with(__LINE__, 'Running code for command line: ' . self::shortenFqMethod($calledFromFqMethod), ['log level' => LogBackend::getSingletonInstance()->maxEnabledLevel->name]);

        $exitCode = 0;

        try {
            $code();
        } catch (Throwable $throwable) {
            $exitCode = self::FAILURE_EXIT_CODE;
            self::logCritical(__FUNCTION__)?->withThrowable(__LINE__, 'Thrown by code for command line: ' . self::shortenFqMethod($calledFromFqMethod), $throwable);
        }

        $logDebug?->with(__LINE__, 'Finished running code for command line: ' . self::shortenFqMethod($calledFromFqMethod), compact('exitCode'));
        exit($exitCode);
    }

    /**
     * @template TCodeRetVal of mixed
     *
     * @param callable(): TCodeRetVal $code
     * @param callable(): void $cleanUp
     *
     * @return TCodeRetVal
     *
     * @noinspection PhpDocMissingThrowsInspection
     */
    public static function runCodeAndCleanUp(callable $code, callable $cleanUp): mixed
    {
        $implFinishedSuccessfully = false;
        try {
            $retVal = $code();
            $implFinishedSuccessfully = true;
            return $retVal;
        } finally {
            try {
                $cleanUp();
            } catch (Throwable $throwableFromCleanUp) {
                if ($implFinishedSuccessfully) {
                    throw $throwableFromCleanUp;
                } else {
                    $logLevel = LogLevel::warning;
                    self::logWithLevel(__FUNCTION__, $logLevel)?->with(__LINE__, 'Failed to clean up');
                    self::logWithLevel(__FUNCTION__, $logLevel)?->withThrowable(__LINE__, 'Thrown by cleanup code', $throwableFromCleanUp);
                }
            }
        }
    }

    public static function isCurrentOsWindows(): bool
    {
        /** @var ?bool $cachedResult */
        $cachedResult = null;

        if ($cachedResult === null) {
            $cachedResult = (strnatcasecmp(PHP_OS_FAMILY, 'Windows') === 0);
        }
        /** @var bool $cachedResult */

        return $cachedResult;
    }

    public static function execShellCommand(string $shellCmd): void
    {
        self::logDebug(__FUNCTION__)?->with(__LINE__, "Executing shell command: $shellCmd");
        $retVal = system($shellCmd . ' 1>&2', /* out */ $exitCode);
        self::assertNotFalse($retVal, compact('retVal'));
        self::assert($exitCode === 0, '$exitCode === 0' . ' ; shellCmd: ' . $shellCmd . ' ; exitCode: ' . $exitCode . ' ; retVal: ' . $retVal);
    }

    public static function surroundInSingleQuotes(string $string): string
    {
        return '\'' . $string . '\'';
    }

    /**
     * @param list<string> $parts
     *
     * @return list<string>
     */
    public static function surroundEachInSingleQuotes(array $parts): array
    {
        return array_map(self::surroundInSingleQuotes(...), $parts);
    }

    /**
     * @param array<string> $parts
     */
    public static function buildShellCommand(array $parts): string
    {
        $cmd = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $cmd .= ($cmd === '' ? '' : ' ') . $part;
        }

        return $cmd;
    }

    /**
     * @template TCodeRetVal
     *
     * @param callable(string $tempDir): TCodeRetVal $code
     *
     * @phpstan-return TCodeRetVal
     *
     * @noinspection PhpDocMissingThrowsInspection
     */
    public static function runCodeOnUniqueNameTempDir(string $tempDirNamePrefix, callable $code): mixed
    {
        $tempDir = self::createTempDirectoryGenerateUniqueName($tempDirNamePrefix);
        return self::runCodeAndCleanUp(
            function () use ($tempDir, $code): mixed {
                return $code($tempDir);
            },
            cleanUp: fn() => self::deleteTempDirectory($tempDir)
        );
    }

    public static function changeCurrentDirectoryRunCodeAndRestore(string $newCurrentDir, callable $code): void
    {
        $originalCurrentDir = self::getCurrentDirectory();
        self::changeCurrentDirectory($newCurrentDir);
        self::runCodeAndCleanUp($code, cleanUp: fn() => self::changeCurrentDirectory($originalCurrentDir));
    }

    public static function encodeJson(mixed $data, bool $prettyPrint = false): string
    {
        $options = JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES;
        $options |= $prettyPrint ? JSON_PRETTY_PRINT : 0;
        $encodedData = json_encode($data, $options);
        if ($encodedData === false) {
            throw new JsonException(
                'json_encode() failed'
                . '. json_last_error_msg(): ' . json_last_error_msg()
                . '. data type: ' . get_debug_type($data)
            );
        }
        return $encodedData;
    }

    public static function decodeJson(string $encodedData): mixed
    {
        $decodedData = json_decode($encodedData, /* assoc: */ true);
        if ($decodedData === null && ($encodedData !== 'null')) {
            throw new JsonException(
                'json_decode() failed.'
                . ' json_last_error_msg(): ' . json_last_error_msg() . '.'
                . ' encodedData: `' . $encodedData . '\''
            );
        }
        return $decodedData;
    }

    /**
     * @return iterable<int>
     */
    private static function generateRange(int $begin, int $end, int $step = 1): iterable
    {
        for ($i = $begin; $i < $end; $i += $step) {
            yield $i;
        }
    }

    /**
     * @param int $count
     *
     * @return iterable<int>
     */
    private static function generateRangeUpTo(int $count): iterable
    {
        return self::generateRange(0, $count);
    }

    /**
     * @return iterable<UnsignedByte>
     */
    public static function iterateOverChars(string $input): iterable
    {
        foreach (self::generateRangeUpTo(strlen($input)) as $i) {
            yield ord($input[$i]);
        }
    }

    /**
     * @param class-string $fqClassName
     */
    private static function splitFqClassName(string $fqClassName, /* out */ string &$namespace, /* out */ string &$shortName): void
    {
        // Check if $fqClassName begin with a back slash(es)
        $firstBackSlashPos = strpos($fqClassName, '\\');
        if ($firstBackSlashPos === false) {
            $namespace = '';
            $shortName = $fqClassName;
            return;
        }
        $firstCanonPos = $firstBackSlashPos === 0 ? 1 : 0;

        $lastBackSlashPos = strrpos($fqClassName, '\\', $firstCanonPos);
        if ($lastBackSlashPos === false) {
            $namespace = '';
            $shortName = substr($fqClassName, $firstCanonPos);
            return;
        }

        $namespace = substr($fqClassName, $firstCanonPos, $lastBackSlashPos - $firstCanonPos);
        $shortName = substr($fqClassName, $lastBackSlashPos + 1);
    }

    /**
     * @param class-string<mixed> $fqClassName
     */
    public static function fqClassNameToShort(string $fqClassName): string
    {
        $namespace = '';
        $shortName = '';
        self::splitFqClassName($fqClassName, /* ref */ $namespace, /* ref */ $shortName);
        return $shortName;
    }

    public static function realPath(string $path): string
    {
        $retVal = realpath($path);
        self::assertNotFalse($retVal, compact('retVal', 'path'));
        return $retVal;
    }

    public static function copyFile(string $fromFilePath, string $toFilePath, bool $allowOverwrite = false): void
    {
        self::logDebug(__FUNCTION__)?->with(__LINE__, "Copying file $fromFilePath to $toFilePath");
        $allowOverwriteOpt = ($allowOverwrite ? (self::isCurrentOsWindows() ? '/y' : '-f') : '');
        self::execShellCommand(
            self::isCurrentOsWindows()
                ? "copy $allowOverwriteOpt \"$fromFilePath\" \"$toFilePath\""
                : "cp $allowOverwriteOpt \"$fromFilePath\" \"$toFilePath\""
        );
    }

    public static function getFileContents(string $filePath): string
    {
        $result = file_get_contents($filePath);
        if (!is_string($result)) {
            throw new RuntimeException("Failed to get file contents; file path: `$filePath'");
        }
        return $result;
    }

    public static function putFileContents(string $filePath, string $contents): void
    {
        $contentsLen = strlen($contents);
        $numberOfBytesWritten = file_put_contents($filePath, $contents);
        if (!is_int($numberOfBytesWritten)) {
            throw new RuntimeException("Failed to put file contents; file path: `$filePath'; contents length: " . $contentsLen);
        }
        if ($numberOfBytesWritten !== $contentsLen) {
            throw new RuntimeException(
                "Number of bytes that were written does not match contents length; file path: `$filePath'; contents length: $contentsLen; number of bytes that were written: $numberOfBytesWritten",
            );
        }

        $fileSize = self::getFileSize($filePath);
        if ($fileSize !== $contentsLen) {
            throw new RuntimeException(
                "File size does not match contents length; file path: `$filePath'; contents length: $contentsLen; file size: $fileSize",
            );
        }
    }

    public static function getFileSize(string $filePath): int
    {
        $result = filesize($filePath);
        if (!is_int($result)) {
            throw new RuntimeException("Failed to get file size; file path: `$filePath'");
        }
        return $result;
    }

    public static function createTempDirectoryGenerateUniqueName(string $dirNamePrefix): string
    {
        $fullPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $dirNamePrefix . uniqid();
        self::createTempDirectory($fullPath);
        return $fullPath;
    }

    public static function createTempDirectory(string $newDirFullPath): void
    {
        self::logDebug(__FUNCTION__)?->with(__LINE__, "Creating temporary directory $newDirFullPath");
        if (is_dir($newDirFullPath)) {
            self::logDebug(__FUNCTION__)?->with(__LINE__, "Directory $newDirFullPath already exists");
            return;
        }
        self::assertNotFalse(mkdir($newDirFullPath, recursive: true), compact('newDirFullPath'));
    }

    public static function createDirectory(string $newDirFullPath): void
    {
        self::logDebug(__FUNCTION__)?->with(__LINE__, "Creating directory $newDirFullPath");
        self::assertNotFalse(mkdir($newDirFullPath, recursive: true), compact('newDirFullPath'));
    }

    public static function copyDirectoryContents(string $fromDirPath, string $toDirPath): void
    {
        self::logDebug(__FUNCTION__)?->with(__LINE__, "Copying directory contents from $fromDirPath to $toDirPath");
        self::execShellCommand(
            self::isCurrentOsWindows()
                ? "xcopy /y /s /e \"$fromDirPath\\*\" \"$toDirPath\\\""
                : "cp -r \"$fromDirPath/\"* \"$toDirPath/\"",
        );
    }

    /** @noinspection PhpUnused */
    public static function deleteFile(string $filePath): void
    {
        self::logDebug(__FUNCTION__)?->with(__LINE__, "Deleting file $filePath");
        $retVal = unlink($filePath);
        self::assert($retVal, '$retVal' . ' ; $retVal: ' . json_encode($retVal) . ' ; filePath: ' . $filePath);
    }

    public static function getBoolEnvVar(string $envVarName): ?bool
    {
        $envVarVal = getenv($envVarName);
        if (is_string($envVarVal) && (($parsedVal = BoolUtil::parse($envVarVal)) !== null)) {
            return $parsedVal;
        }
        return null;
    }

    /**
     * @param-out string $reason
     *
     * @phpstan-assert string $reason
     */
    private static function shouldKeepTemporaryFiles(/* out */ ?string &$reason): bool
    {
        /** @var ?bool $cachedRetVal */
        static $cachedRetVal = null;
        /** @var ?string $cachedReason */
        static $cachedReason = null;

        if ($cachedRetVal === null) {
            $envVarName = self::buildConfigEnvVar(self::KEEP_TEMP_FILES_ENV_VAR_NAME_SUFFIX);
            $envVarValue = self::getBoolEnvVar($envVarName);
            if ($envVarValue === null) {
                $cachedRetVal = false;
                $cachedReason = $envVarName . ' is not set';
            } else {
                $cachedRetVal = $envVarValue;
                $cachedReason = $envVarName . ' is set to ' . BoolUtil::toString($cachedRetVal);
            }
        }
        /** @var bool $cachedRetVal */
        /** @var string $cachedReason */

        $reason = $cachedReason;
        return $cachedRetVal;
    }

    public static function deleteDirectory(string $dirPath): void
    {
        self::logDebug(__FUNCTION__)?->with(__LINE__, "Deleting directory $dirPath");
        self::execShellCommand(
            self::isCurrentOsWindows()
                ? "DEL /F /Q /S \"$dirPath\" && RD /S /Q \"$dirPath\""
                : "rm -rf \"$dirPath\""
        );
    }

    public static function deleteTempDirectory(string $dirPath): void
    {
        if (self::shouldKeepTemporaryFiles(/* out */ $reason)) {
            self::logDebug(__FUNCTION__)?->with(__LINE__, "Keeping temporary directory $dirPath because $reason");
        } else {
            self::logDebug(__FUNCTION__)?->with(__LINE__, "Deleting temporary directory $dirPath because $reason");
            self::deleteDirectory($dirPath);
        }
    }

    public static function deleteTempFile(string $filePath): void
    {
        if (self::shouldKeepTemporaryFiles(/* out */ $reason)) {
            self::logDebug(__FUNCTION__)?->with(__LINE__, "Keeping temporary file $filePath because $reason");
        } else {
            self::logDebug(__FUNCTION__)?->with(__LINE__, "Deleting temporary file $filePath because $reason");
            self::deleteFile($filePath);
        }
    }

    public static function getCurrentDirectory(): string
    {
        $currentDir = getcwd();
        self::assertNotFalse($currentDir, compact('currentDir'));
        return self::realPath($currentDir);
    }

    public static function changeCurrentDirectory(string $newCurrentDir): void
    {
        $chdirRetVal = chdir($newCurrentDir);
        self::assertNotFalse($chdirRetVal, compact('chdirRetVal'));
    }

    public static function listDirectoryContents(string $dirPath, int $recursiveDepth = 0, LogLevel $logLevel = BuildToolsLogUtil::DEFAULT_LEVEL): void
    {
        if (!self::isLogEnabledForLevel($logLevel)) {
            return;
        }

        self::logWithLevel(__FUNCTION__, $logLevel)?->with(__LINE__, "Contents  of directory $dirPath:");
        self::execShellCommand(
            self::isCurrentOsWindows()
                ? "dir \"$dirPath\""
                : "ls -al \"$dirPath\""
        );

        if ($recursiveDepth === 0) {
            return;
        }

        foreach (new DirectoryIterator($dirPath) as $fileInfo) {
            if ($fileInfo->getFilename() === '.' || $fileInfo->getFilename() === '..') {
                continue;
            }

            self::listDirectoryContents($fileInfo->getRealPath(), $recursiveDepth - 1);
        }
    }

    public static function listFileContents(string $filePath, LogLevel $logLevel = BuildToolsLogUtil::DEFAULT_LEVEL): void
    {
        if (!self::isLogEnabledForLevel($logLevel)) {
            return;
        }

        self::logWithLevel(__FUNCTION__, $logLevel)?->with(__LINE__, "Contents of file $filePath:");
        self::execShellCommand(
            self::isCurrentOsWindows()
                ? "type \"$filePath\""
                : "cat \"$filePath\""
        );
    }

    public static function partsToPath(string ...$parts): string
    {
        $result = '';
        foreach ($parts as $part) {
            if ($result !== '' && $part !== '') {
                $result .= DIRECTORY_SEPARATOR;
            }
            $result .= $part;
        }
        return $result;
    }

    public static function adaptUnixDirectorySeparators(string $path): string
    {
        /** @phpstan-var string $unixDirectorySeparator */
        static $unixDirectorySeparator = '/';

        if (DIRECTORY_SEPARATOR === $unixDirectorySeparator) {
            return $path;
        }

        static $unixDirectorySeparatorAsInt = null;
        if ($unixDirectorySeparatorAsInt === null) {
            $unixDirectorySeparatorAsInt = ord($unixDirectorySeparator);
        }

        $result = '';
        foreach (self::iterateOverChars($path) as $pathCharAsInt) {
            $result .= $pathCharAsInt === $unixDirectorySeparatorAsInt ? DIRECTORY_SEPARATOR : chr($pathCharAsInt);
        }
        return $result;
    }

    private static function shortenFqMethod(string $fqMethod): string
    {
        return str_starts_with($fqMethod, __NAMESPACE__) ? substr($fqMethod, strlen(__NAMESPACE__) + 1) : $fqMethod;
    }

    /**
     * @return list<string>
     *
     * @noinspection PhpUnused
     */
    public static function getCommandLineArgs(): array
    {
        /** @var list<string> $argv */
        global $argv;
        return count($argv) > 1 ? array_slice($argv, 1) : [];
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
    private static function getCurrentOptionalLogProdFeatureIntOrCategoryString(): null|string // @phpstan-ignore return.unusedType
    {
        return null;
    }
}
