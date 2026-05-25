<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Config;

use OpenTelemetry\Distro\Util\ArrayUtil;
use OTelDistroTests\Util\ArrayUtilForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Log\LoggableTrait;
use OTelDistroTests\Util\TextUtilForTests;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use ReflectionType;
use UnitEnum;

/**
 * @template TOptionName of UnitEnum
 */
trait SnapshotTrait
{
    use LoggableTrait;

    /** @var ?array<string, mixed> */
    private ?array $optNameToParsedValue = null;

    /**
     * @param array<string, mixed> $optNameToParsedValue
     */
    protected function setPropertiesToValuesFrom(array $optNameToParsedValue): void
    {
        Assert::assertNull($this->optNameToParsedValue);

        $actualClass = get_called_class();
        foreach ($optNameToParsedValue as $optName => $parsedValue) {
            $propertyName = TextUtilForTests::snakeToCamelCase($optName);
            if (!property_exists($actualClass, $propertyName)) {
                throw new ConfigException("Property `$propertyName' doesn't exist in class " . $actualClass);
            }
            $this->$propertyName = $parsedValue;
        }

        $this->optNameToParsedValue = $optNameToParsedValue;
    }

    /**
     * @return list<string>
     */
    protected static function snapshotTraitPropNamesNotForOptions(): array
    {
        return ['optNameToParsedValue'];
    }

    /**
     * @return list<string>
     */
    protected static function additionalPropNamesNotForOptions(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public static function propertyNamesForOptions(): array
    {
        /** @var ?list<string> $result */
        static $result = null;

        if ($result === null) {
            $tempResult = array_keys(get_class_vars(get_called_class()));
            $propNamesNotForOptions = array_merge(self::snapshotTraitPropNamesNotForOptions(), self::additionalPropNamesNotForOptions());
            Assert::assertSame(count($propNamesNotForOptions), ArrayUtilForTests::removeAllValues(/* in,out */ $tempResult, $propNamesNotForOptions));
            $result = array_values($tempResult);
        }
        return $result;
    }

    /**
     * @param TOptionName $optName
     */
    public function getOptionValueByName(UnitEnum $optName): mixed
    {
        Assert::assertNotNull($this->optNameToParsedValue);
        return ArrayUtil::getValueIfKeyExistsElse($optName->name, $this->optNameToParsedValue, null);
    }

    /**
     * @param TOptionName $optName
     */
    public static function getPropertyReflectionType(UnitEnum $optName): ReflectionType
    {
        $propertyName = TextUtilForTests::snakeToCamelCase($optName->name);
        return AssertEx::notNull((new ReflectionClass(static::class))->getProperty($propertyName)->getType());
    }
}
