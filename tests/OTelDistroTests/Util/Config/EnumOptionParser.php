<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Config;

use BackedEnum;
use OpenTelemetry\Distro\Util\TextUtil;
use OTelDistroTests\Util\ExceptionUtil;
use Override;
use PHPUnit\Framework\Assert;
use UnitEnum;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 *
 * @template T
 *
 * @extends OptionParser<T>
 */
class EnumOptionParser extends OptionParser
{
    /**
     * We are forced to use list-array of pairs instead of regular associative array
     * because in an associative array if the key is numeric string it's automatically converted to int
     * (see https://www.php.net/manual/en/language.types.array.php)
     *
     * @param list<array{string, T}> $nameValuePairs
     */
    public function __construct(
        private readonly string $dbgDesc,
        private readonly array $nameValuePairs,
        private readonly bool $isCaseSensitive,
        private readonly bool $isUnambiguousPrefixAllowed
    ) {
    }

    /**
     * @template TEnum of UnitEnum
     *
     * @param class-string<TEnum> $enumClass
     *
     * @return self<TEnum>
     */
    public static function useEnumCasesNames(string $enumClass, bool $isCaseSensitive, bool $isUnambiguousPrefixAllowed): self
    {
        $nameValuePairs = [];
        foreach ($enumClass::cases() as $enumCase) {
            $nameValuePairs[] = [$enumCase->name, $enumCase];
        }
        return new self($enumClass, $nameValuePairs, $isCaseSensitive, $isUnambiguousPrefixAllowed);
    }

    /**
     * @template TEnum of BackedEnum
     * *
     * @param class-string<TEnum> $enumClass
     *
     * @return self<TEnum>
     */
    public static function useEnumCasesValues(string $enumClass, bool $isCaseSensitive, bool $isUnambiguousPrefixAllowed): self
    {
        /** @var list<array{string, TEnum}> $nameValuePairs */
        $nameValuePairs = [];
        foreach ($enumClass::cases() as $enumCase) {
            Assert::assertIsString($enumCase->value);
            $nameValuePairs[] = [$enumCase->value, $enumCase];
        }
        return new self($enumClass, $nameValuePairs, $isCaseSensitive, $isUnambiguousPrefixAllowed);
    }

    /**
     * @return list<array{string, T}>
     */
    public function nameValuePairs(): array
    {
        return $this->nameValuePairs;
    }

    public function isCaseSensitive(): bool
    {
        return $this->isCaseSensitive;
    }

    public function isUnambiguousPrefixAllowed(): bool
    {
        return $this->isUnambiguousPrefixAllowed;
    }

    /** @inheritDoc */
    #[Override]
    public function parse(string $rawValue): mixed
    {
        /** @var ?array{string, T} $foundPair */
        $foundPair = null;
        foreach ($this->nameValuePairs as $currentPair) {
            if (TextUtil::isPrefixOf($rawValue, $currentPair[0], $this->isCaseSensitive)) {
                if (strlen($currentPair[0]) === strlen($rawValue)) {
                    return $currentPair[1];
                }

                if (!$this->isUnambiguousPrefixAllowed) {
                    continue;
                }

                if ($foundPair != null) {
                    throw new ParseException(ExceptionUtil::buildMessage('Not a valid value - it matches more than one entry as a prefix', compact('this', 'rawValue', 'foundPair', 'currentPair')));
                }
                $foundPair = $currentPair;
            }
        }

        if ($foundPair == null) {
            throw new ParseException('Not a valid ' . $this->dbgDesc . ' value. Raw option value: `' . $rawValue . '\'');
        }

        return $foundPair[1];
    }
}
