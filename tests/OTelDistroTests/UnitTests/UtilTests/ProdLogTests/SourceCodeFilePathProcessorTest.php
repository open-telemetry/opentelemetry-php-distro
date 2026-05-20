<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests\ProdLogTests;

use OpenTelemetry\Distro\Log\SourceCodeFilePathProcessor;
use OTelDistroTests\Util\FileUtil;
use OTelDistroTests\Util\RangeUtil;
use OTelDistroTests\Util\TestCaseBase;

/**
 * @phpstan-import-type StringList from SourceCodeFilePathProcessor
 */
class SourceCodeFilePathProcessorTest extends TestCaseBase
{
    private const WINDOWS_DIRECTORY_SEPARATOR = '\\';

    /**
     * @return StringList
     */
    private static function directorySeparatorsToTest(): array
    {
        /** @var ?StringList $result */
        static $result = null;
        if ($result === null) {
            $result = array_values(array_unique([DIRECTORY_SEPARATOR, FileUtil::UNIX_DIRECTORY_SEPARATOR, self::WINDOWS_DIRECTORY_SEPARATOR]));
        }
        return $result;
    }

    public function testFindPrefix(): void
    {
        $invokeAssert = function (string $text, array $prefixCandidates, ?string $expectedResult): void {
            /** @var StringList $prefixCandidates */
            $actualResult = SourceCodeFilePathProcessor::findPrefix($text, $prefixCandidates);
            self::assertSame($expectedResult, $actualResult);
        };

        $invokeAssert('', [], null);
        $invokeAssert('', [''], '');
        $invokeAssert('a', ['a', ''], 'a');
        $invokeAssert('a', ['', 'a'], '');
        $invokeAssert('ab', ['a', ''], 'a');
        $invokeAssert('ab', ['', 'a', 'ab'], '');
        $invokeAssert('ab', ['ab', '', 'a'], 'ab');
        $invokeAssert('ab', ['a', 'ab', ''], 'a');
        $invokeAssert('ab', ['a', '', 'ab'], 'a');
    }

    public function testRemoveDuplicateDirSeparators(): void
    {
        $invokeAssert = function (string $path, string $dirSeparator, string $expectedResult): void {
            $actualResult = SourceCodeFilePathProcessor::removeDuplicateDirSeparators($path, $dirSeparator);
            self::assertSame($expectedResult, $actualResult);
        };

        $invokeAssertNoChange = function (string $path, string $dirSeparator) use ($invokeAssert): void {
            $invokeAssert($path, $dirSeparator, $path);
        };

        /**
         * @param string $text
         *
         * @return StringList
         */
        $genTextPrefixes = function (string $text): iterable {
            foreach (RangeUtil::generateUpTo(strlen($text)) as $len) {
                yield substr($text, 0, $len);
            }
        };

        $invokeAssertNoChangeForAnyPrefix = function (string $path, string $dirSeparator) use ($invokeAssertNoChange, $genTextPrefixes): void {
            foreach ($genTextPrefixes($path) as $pathPrefix) {
                $invokeAssertNoChange($pathPrefix, $dirSeparator);
            }
        };

        /**
         * @param array<string> $pathParts
         */
        $invokeAssertOnPathParts = function (array $pathParts, string $partsSeparatorOnInput, string $partsSeparatorOnExpected) use ($invokeAssert): void {
            /** @var array<string> $pathParts */
            $invokeAssert(implode($partsSeparatorOnInput, $pathParts), $partsSeparatorOnExpected, implode($partsSeparatorOnExpected, $pathParts));
        };

        $invokeAssert('//a', '/', '/a');
        $invokeAssert('a//b', '/', 'a/b');
        $invokeAssert('a/b//', '/', 'a/b/');
        $invokeAssert('//a//b//', '/', '/a/b/');
        $invokeAssert('//a//b//c', '/', '/a/b/c');

        foreach (self::directorySeparatorsToTest() as $dirSeparator) {
            $invokeAssertNoChangeForAnyPrefix('abc', $dirSeparator);
            $invokeAssertNoChangeForAnyPrefix('a/b/c/', $dirSeparator);
            $invokeAssertNoChangeForAnyPrefix('/a/b/c/', $dirSeparator);

            foreach (RangeUtil::generateFromToIncluding(2, 5) as $repeatCount) {
                $dirSeparatorRepeated = str_repeat($dirSeparator, $repeatCount);
                $invokeAssertOnPathParts(['a'], $dirSeparatorRepeated, $dirSeparator);
                $invokeAssertOnPathParts(['', 'a'], $dirSeparatorRepeated, $dirSeparator);
                $invokeAssertOnPathParts(['a', ''], $dirSeparatorRepeated, $dirSeparator);
                $invokeAssertOnPathParts(['a', 'b'], $dirSeparatorRepeated, $dirSeparator);
                $invokeAssertOnPathParts(['', 'a', 'b'], $dirSeparatorRepeated, $dirSeparator);
                $invokeAssertOnPathParts(['a', 'b', ''], $dirSeparatorRepeated, $dirSeparator);
                $invokeAssertOnPathParts(['', 'a', 'b', ''], $dirSeparatorRepeated, $dirSeparator);
            }
        }
    }
}
