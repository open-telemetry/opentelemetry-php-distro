<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OpenTelemetry\Distro\Util\StaticClassTrait;
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

    public const FILE_PATH_KEY = 'app_code_context_data_file_path';

    public static function createTempFile(TestCaseHandle $testCaseHandle): string
    {
        return $testCaseHandle->getResourcesClient()->createTempFile('app_code_context_data');
    }

    /**
     * @param JsonEncodableData $data
     */
    public static function writeDataToFile(null|bool|int|float|string|array $data, string $filePath): void
    {
        FileUtil::putFileContents($filePath, JsonUtil::encode(self::assertJsonEncodableData($data)));
    }

    /**
     * @return JsonEncodableData
     */
    public static function readDataFromFile(string $filePath): null|bool|int|float|string|array
    {
        return self::assertJsonEncodableData(JsonUtil::decode(FileUtil::getFileContents($filePath)));
    }

    public static function readMixedMapFromFile(string $filePath): MixedMap
    {
        return (new MixedMap(MixedMap::assertValidMixedMapArray(AssertEx::isArray(self::readDataFromFile($filePath)))));
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
