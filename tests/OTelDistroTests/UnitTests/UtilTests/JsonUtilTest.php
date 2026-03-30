<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests;

use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\JsonUtil;
use OTelDistroTests\Util\TestCaseBase;
use JsonException;

final class JsonUtilTest extends TestCaseBase
{
    private static function decode(string $encodedData): mixed
    {
        $decodedData = json_decode($encodedData, /* associative: */ true);
        if ($decodedData === null && ($encodedData !== 'null')) {
            throw new JsonException(
                'json_decode() failed.'
                . ' json_last_error_msg(): ' . json_last_error_msg() . '.'
                . ' encodedData: `' . $encodedData . '\''
            );
        }
        return $decodedData;
    }

    public function testMapWithNumericKeys(): void
    {
        $original = ['0' => 0];
        $serialized = JsonUtil::encode((object)$original);
        self::assertSame(1, preg_match('/^\s*{\s*"0"\s*:\s*0\s*}\s*$/', $serialized));
        $decodedJson = self::decode($serialized);
        self::assertIsArray($decodedJson);
        AssertEx::equalMaps($original, $decodedJson);
    }
}
