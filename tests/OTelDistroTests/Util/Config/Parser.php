<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Config;

use OTelDistroTests\Util\Log\LogCategoryForTests;
use OTelDistroTests\Util\Log\Logger;
use OTelDistroTests\Util\Log\LoggerFactory;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class Parser
{
    private readonly Logger $logger;

    /**
     * Parser constructor.
     *
     * @param LoggerFactory $loggerFactory
     */
    public function __construct(LoggerFactory $loggerFactory)
    {
        $this->logger = $loggerFactory->loggerForClass(LogCategoryForTests::CONFIG, __NAMESPACE__, __CLASS__, __FILE__);
    }

    /**
     * @template T
     *
     * @param string          $rawValue
     * @param OptionParser<T> $optionParser
     *
     * @return T
     */
    public static function parseOptionRawValue(string $rawValue, OptionParser $optionParser): mixed
    {
        return $optionParser->parse(trim($rawValue));
    }

    /**
     * @param array<string, OptionMetadata<mixed>> $optNameToMeta
     * @param RawSnapshotInterface                 $rawSnapshot
     *
     * @return array<string, mixed> Option name to parsed value
     */
    public function parse(array $optNameToMeta, RawSnapshotInterface $rawSnapshot): array
    {
        $optNameToParsedValue = [];
        foreach ($optNameToMeta as $optName => $optMeta) {
            $rawValue = $rawSnapshot->valueFor($optName);
            if ($rawValue === null) {
                $parsedValue = $optMeta->defaultValue();

                $this->logger->logDebug(__FUNCTION__)?->with(
                    __LINE__,
                    "Input raw config snapshot doesn't have a value for the option - using default value",
                    ['Option name' => $optName, 'Option default value' => $optMeta->defaultValue()]
                );
            } else {
                try {
                    $parsedValue = self::parseOptionRawValue($rawValue, $optMeta->parser());

                    $this->logger->logDebug(__FUNCTION__)?->with(
                        __LINE__,
                        'Input raw config snapshot has a value - using parsed value',
                        ['Option name' => $optName, 'Raw value' => $rawValue, 'Parsed value' => $parsedValue]
                    );
                } catch (ParseException $ex) {
                    $parsedValue = $optMeta->defaultValue();

                    $this->logger->logError(__FUNCTION__)?->with(
                        __LINE__,
                        "Input raw config snapshot has a value but it's invalid - using default value",
                        [
                            'Option name'          => $optName,
                            'Option default value' => $optMeta->defaultValue(),
                            'Exception'            => $ex,
                        ]
                    );
                }
            }
            $optNameToParsedValue[$optName] = $parsedValue;
        }

        return $optNameToParsedValue;
    }
}
