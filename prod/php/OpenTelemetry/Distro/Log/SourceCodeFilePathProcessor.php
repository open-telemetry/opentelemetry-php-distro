<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro\Log;

use OpenTelemetry\Distro\Util\GetContextInterface;

/**
 * @phpstan-import-type Context from GetContextInterface
 *
 * @phpstan-type StringList list<string>
 */
final class SourceCodeFilePathProcessor
{
    /** @var StringList */
    private readonly array $rootDirsPrefixes;

    /**
     * @param StringList $rootDirs
     */
    public function __construct(array $rootDirs)
    {
        $this->rootDirsPrefixes = self::buildSourceCodeRootDirsPrefixes($rootDirs);
    }

    /**
     * @param StringList $prefixCandidates
     */
    public static function findPrefix(string $text, array $prefixCandidates): ?string
    {
        foreach ($prefixCandidates as $prefixCandidate) {
            if (str_starts_with($text, $prefixCandidate)) {
                return $prefixCandidate;
            }
        }

        return null;
    }

    public static function removeDuplicateDirSeparators(string $path, string $dirSeparator): string
    {
        $twoDirSeparators = $dirSeparator . $dirSeparator;
        $result = $path;
        do {
            $result = str_replace($twoDirSeparators, $dirSeparator, $result, /* out */ $timesReplaced);
        } while ($timesReplaced !== 0);
        return $result;
    }

    /**
     * @param StringList $sourceCodeRootDirs
     *
     * @return StringList
     */
    private static function buildSourceCodeRootDirsPrefixes(array $sourceCodeRootDirs): array
    {
        $sourceCodeRootDirsPrefixes = [];
        foreach ($sourceCodeRootDirs as $dirFullPath) {
            $newPrefix = self::removeDuplicateDirSeparators($dirFullPath, DIRECTORY_SEPARATOR);
            if ($newPrefix === '') {
                continue;
            }
            $newPrefix = str_ends_with($newPrefix, DIRECTORY_SEPARATOR) ? $newPrefix : ($newPrefix . DIRECTORY_SEPARATOR);
            // If none of the already added elements is a prefix for the new one
            if (self::findPrefix($newPrefix, $sourceCodeRootDirsPrefixes) === null) {
                // Remove already added elements for which the new one is a prefix
                $sourceCodeRootDirsPrefixes = array_values(array_filter($sourceCodeRootDirsPrefixes, fn($alreadyAddedPrefix) => !str_starts_with($alreadyAddedPrefix, $newPrefix)));
                $sourceCodeRootDirsPrefixes[] = $newPrefix;
            }
        }
        return $sourceCodeRootDirsPrefixes;
    }

    public function processPath(string $filePath): string
    {
        if (self::findPrefix($filePath, $this->rootDirsPrefixes) !== null) {
            return basename($filePath);
        }

        return $filePath;
    }

    /**
     * @return Context
     */
    public function toLog(): array
    {
        return [
            'rootDirsPrefixes' => $this->rootDirsPrefixes,
        ];
    }
}
