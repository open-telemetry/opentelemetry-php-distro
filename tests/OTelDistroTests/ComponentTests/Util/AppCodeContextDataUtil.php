<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OpenTelemetry\Distro\Util\StaticClassTrait;
use OTelDistroTests\Util\ArrayUtilForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\FileUtil;
use OTelDistroTests\Util\JsonUtil;
use OTelDistroTests\Util\MixedMap;
use PHPUnit\Framework\Assert;

/**
 * @phpstan-type JsonEncodableData null|bool|int|float|string|list<mixed>|array<string, mixed>
 */
final class AppCodeContextDataUtil
{
    use StaticClassTrait;

    private const FILE_PATH_KEY = 'app_code_context_data_file_path';

    /**
     * @param array<string, mixed> &$appCodeArgs
     */
    public static function createTempFile(TestCaseHandle $testCaseHandle, /* in,out */ array &$appCodeArgs): void
    {
        $tempFilePath = $testCaseHandle->getResourcesClient()->createTempFile('app_code_context_data');
        ArrayUtilForTests::addAssertingKeyNew(self::FILE_PATH_KEY, $tempFilePath, /* in,out */ $appCodeArgs);
    }

    /**
     * @param JsonEncodableData $data
     */
    public static function writeDataToTempFile(null|bool|int|float|string|array $data, MixedMap $appCodeArgs): void
    {
        FileUtil::putFileContents($appCodeArgs->getString(self::FILE_PATH_KEY), JsonUtil::encode(self::assertJsonEncodableData($data)));
    }

    /**
     * @param array<string, mixed> $appCodeArgs
     *
     * @return JsonEncodableData
     */
    public static function readDataFromTempFile(array $appCodeArgs): null|bool|int|float|string|array
    {
        return self::assertJsonEncodableData(JsonUtil::decode(FileUtil::getFileContents(MixedMap::getStringFrom(self::FILE_PATH_KEY, $appCodeArgs))));
    }

    /**
     * @param array<string, mixed> $appCodeArgs
     */
    public static function readDataAsMixedMapFromTempFile(array $appCodeArgs): MixedMap
    {
        return (new MixedMap(MixedMap::assertValidMixedMapArray(AssertEx::isArray(self::readDataFromTempFile($appCodeArgs)))));
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
