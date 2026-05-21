<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Config;

use OpenTelemetry\Distro\Util\ArrayUtil;
use OTelDistroTests\Util\ArrayUtilForTests;
use OTelDistroTests\Util\Log\LoggableTrait;
use OTelDistroTests\Util\TextUtilForTests;
use PHPUnit\Framework\Assert;
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
     * @return string[]
     */
    protected static function snapshotTraitPropNamesNotForOptions(): array
    {
        return ['optNameToParsedValue'];
    }

    /**
     * @return string[]
     */
    protected static function additionalPropNamesNotForOptions(): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public static function propertyNamesForOptions(): array
    {
        /** @var ?array<string> $result */
        static $result = null;
        if ($result === null) {
            $result = array_keys(get_class_vars(get_called_class()));
            $propNamesNotForOptions = array_merge(self::snapshotTraitPropNamesNotForOptions(), self::additionalPropNamesNotForOptions());
            Assert::assertSame(count($propNamesNotForOptions), ArrayUtilForTests::removeAllValues(/* in,out */ $result, $propNamesNotForOptions));
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
}
