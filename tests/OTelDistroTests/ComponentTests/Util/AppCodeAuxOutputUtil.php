<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OpenTelemetry\Distro\Util\StaticClassTrait;
use OTelDistroTests\Util\ArrayUtilForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\ClassNameUtil;
use OTelDistroTests\Util\FileUtil;
use OTelDistroTests\Util\JsonUtil;
use OTelDistroTests\Util\MixedMap;
use PHPUnit\Framework\Assert;

/**
 * @phpstan-type JsonEncodableData null|bool|int|float|string|list<mixed>|array<string, mixed>
 */
final class AppCodeAuxOutputUtil
{
    use StaticClassTrait;

    private const FILE_PATH_KEY = 'app_code_aux_output_file_path';

    /**
     * @param array<string, mixed> &$appCodeRequestArgs
     */
    public static function createTempFile(string $calledFromClass, TestCaseHandle $testCaseHandle, /* in,out */ array &$appCodeRequestArgs): void
    {
        $tempFilePath = $testCaseHandle->getResourcesCleanerClient()->createTempFile(
            FileUtil::generateTempFileNamePrefix(ClassNameUtil::fqToShortFromRawString($calledFromClass) . '_' . self::FILE_PATH_KEY)
        );
        ArrayUtilForTests::addAssertingKeyNew(self::FILE_PATH_KEY, $tempFilePath, /* in,out */ $appCodeRequestArgs);
    }

    public static function getFilePath(MixedMap $appCodeRequestArgs): string
    {
        return $appCodeRequestArgs->getString(self::FILE_PATH_KEY);
    }

    /**
     * @param JsonEncodableData $data
     */
    public static function writeDataToTempFile(null|bool|int|float|string|array $data, MixedMap $appCodeRequestArgs): void
    {
        $dataToWrite = JsonUtil::encode(self::assertJsonEncodableData($data));
        FileUtil::putFileContents(self::getFilePath($appCodeRequestArgs), $dataToWrite);
    }

    /**
     * @param array<string, mixed> $appCodeRequestArgs
     *
     * @return JsonEncodableData
     */
    public static function readDataFromTempFile(array $appCodeRequestArgs): null|bool|int|float|string|array
    {
        return self::assertJsonEncodableData(JsonUtil::decode(FileUtil::getFileContents(MixedMap::getStringFrom(self::FILE_PATH_KEY, $appCodeRequestArgs))));
    }

    /**
     * @param array<string, mixed> $appCodeRequestArgs
     */
    public static function readDataAsMixedMapFromTempFile(array $appCodeRequestArgs): MixedMap
    {
        return (new MixedMap(MixedMap::assertValidMixedMapArray(AssertEx::isArray(self::readDataFromTempFile($appCodeRequestArgs)))));
    }

    /**
     * @return JsonEncodableData
     */
    public static function assertJsonEncodableData(mixed $data): null|bool|int|float|string|array
    {
        if (
            ($data === null)
            || is_bool($data)
            || is_int($data)
            || is_float($data)
            || is_string($data)
        ) {
            return $data;
        }

        Assert::assertIsArray($data);
        foreach ($data as $value) {
            self::assertJsonEncodableData($value);
        }
        return $data; // @phpstan-ignore return.type
    }
}
