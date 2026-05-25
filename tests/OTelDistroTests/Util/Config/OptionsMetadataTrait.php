<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Config;

use OpenTelemetry\Distro\Util\SingletonInstanceTrait;
use PHPUnit\Framework\Assert;
use UnitEnum;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
trait OptionsMetadataTrait
{
    use SingletonInstanceTrait;

    /** @var array<string, OptionMetadata<mixed>> */
    private array $optionsNameValueMap;

    /**
     * @template TOptionNameEnum of UnitEnum

     * @param TOptionNameEnum[] $cases
     * @param array<array{TOptionNameEnum, OptionMetadata<mixed>}> $pairs
     *
     * @return array<string, OptionMetadata<mixed>>
     */
    private static function convertPairsToMap(array $pairs, array $cases): array
    {
        /** @var array<string, OptionMetadata<mixed>> $result */
        $result = [];
        foreach ($pairs as $pair) {
            Assert::assertArrayNotHasKey($pair[0]->name, $result);
            $result[$pair[0]->name] = $pair[1];
        }

        foreach ($cases as $case) {
            Assert::assertArrayHasKey($case->name, $result);
        }
        Assert::assertCount(count($cases), $result);

        return $result;
    }

    /**
     * @return array<string, OptionMetadata<mixed>>
     */
    public static function get(): array
    {
        return self::singletonInstance()->optionsNameValueMap;
    }
}
