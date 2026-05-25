<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use Ds\Map;
use OpenTelemetry\Distro\Log\LogLevel;
use OpenTelemetry\Distro\Util\TextUtil;
use OTelDistroTests\UnitTests\Util\MockConfigRawSnapshotSource;
use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Config\CompositeRawSnapshotSource;
use OTelDistroTests\Util\Config\ConfigSnapshotForProd;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\Config\OptionForTestsName;
use OTelDistroTests\Util\Config\OptionsForProdMetadata;
use OTelDistroTests\Util\Config\Parser as ConfigParser;
use OTelDistroTests\Util\EnvVarUtil;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\Log\LogCategoryForTests;
use OTelDistroTests\Util\Log\LoggableInterface;
use OTelDistroTests\Util\Log\LoggableToString;
use OTelDistroTests\Util\Log\LoggableTrait;
use OTelDistroTests\Util\Log\LoggerFactory;
use PHPUnit\Framework\Assert;

/**
 * @phpstan-import-type EnvVars from EnvVarUtil
 *
 * @phpstan-type OptionForProdValue string|int|float|bool
 * @phpstan-type OptionsForProdMap Map<OptionForProdName, OptionForProdValue>
 */
class AppCodeHostParams implements LoggableInterface
{
    use LoggableTrait;

    /** @var OptionsForProdMap */
    private Map $prodOptions;

    /** @var array<string, string> */
    private array $additionalEnvVars = [];

    public string $spawnedProcessInternalId;

    public function __construct(
        public readonly string $dbgProcessNamePrefix
    ) {
        $this->prodOptions = AmbientContextForTests::testConfig()->matrixRow()->optionalPart?->prodOptions->copy() ?? new Map();
    }

    /**
     * @return OptionForProdValue
     */
    public static function assertValidProdOptionValueType(mixed $optVal, string $optName): mixed
    {
        if (is_string($optVal) || is_int($optVal) || is_float($optVal) || is_bool($optVal)) {
            return $optVal;
        }
        Assert::fail('Not valid option value type; ' . LoggableToString::convert(compact('optName', 'optVal') + ['$optVal type' => get_debug_type($optVal)]));
    }

    /**
     * @param OptionForProdName  $optName
     * @param OptionForProdValue $optVal
     */
    public function setProdOption(OptionForProdName $optName, string|int|float|bool $optVal): void
    {
        $this->prodOptions[$optName] = $optVal;
    }

    /**
     * @param OptionForProdName   $optName
     * @param ?OptionForProdValue $optVal
     */
    public function setProdOptionIfNotNull(OptionForProdName $optName, null|string|int|float|bool $optVal): void
    {
        if ($optVal !== null) {
            $this->setProdOption($optName, $optVal);
        }
    }

    /**
     * @param OptionForProdName   $optName
     * @param ?OptionForProdValue $optVal
     */
    public function setProdOptionIfNotDefault(OptionForProdName $optName, null|string|int|float|bool $optVal): void
    {
        if ($optVal !== OptionsForProdMetadata::get()[$optName->name]->defaultValue()) {
            $this->setProdOption($optName, AssertEx::notNull($optVal));
        }
    }

    public function setAdditionalEnvVar(string $envVarName, string $envVarValue): void
    {
        $this->additionalEnvVars[$envVarName] = $envVarValue;
    }

    /**
     * @phpstan-param EnvVars $envVarsPHPUnitContext
     *
     * @return EnvVars
     */
    public static function filterEnvVarsFromPhpUnitToAppCodeContext(array $envVarsPHPUnitContext): array
    {
        $logDebug = AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__)->logDebug(__FUNCTION__);
        if ($logDebug !== null) {
            ksort(/* ref */ $envVarsPHPUnitContext);
            $logDebug->with(__LINE__, 'Entered', compact('envVarsPHPUnitContext'));
        }

        $result = array_filter(
            $envVarsPHPUnitContext,
            function (string $envVarName): bool {
                // Return false for entries to be removed

                // Keep environment variables related to testing infrastructure
                if (TextUtil::isPrefixOfIgnoreCase(OptionForTestsName::ENV_VAR_NAME_PREFIX, $envVarName)) {
                    return true;
                }

                // Drop any other environment variables related to either OTel SDK, Distro or contrib
                foreach (OptionForProdName::getEnvVarNamePrefixes() as $envVarPrefix) {
                    if (TextUtil::isPrefixOfIgnoreCase($envVarPrefix, $envVarName)) {
                        return false;
                    }
                }

                // Drop Composer Dependency Manager for PHP environment variables that interfere with OTel SDK initialization
                // COMPOSER_DEV_MODE causes ComposerHandler::isRunning() to return true,
                // which prevents SdkAutoloader::autoload() from being called
                if (TextUtil::isPrefixOfIgnoreCase('COMPOSER', $envVarName)) {
                    return false;
                }

                // Keep the rest
                return true;
            },
            ARRAY_FILTER_USE_KEY
        );

        if ($logDebug !== null) {
            ksort(/* ref */ $result);
            $logDebug->with(__LINE__, 'Exiting', compact('result'));
        }
        return $result;
    }

    /**
     * @return EnvVars
     */
    public function buildEnvVarsForAppCodeProcess(): array
    {
        $result = self::filterEnvVarsFromPhpUnitToAppCodeContext(EnvVarUtilForTests::getAll());

        foreach ($this->prodOptions as $optName => $optVal) {
            $result[$optName->toEnvVarName()] = ConfigUtilForTests::optionValueToString($optVal);
        }

        foreach ($this->additionalEnvVars as $envVarName => $envVarValue) {
            $result[$envVarName] = $envVarValue;
        }

        AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__)
            ->logDebug(__FUNCTION__)?->with(__LINE__, 'Exiting', compact('result'));
        return $result;
    }

    public function buildProdConfig(): ConfigSnapshotForProd
    {
        $envVarsToInheritSource = new MockConfigRawSnapshotSource();
        $envVars = $this->buildEnvVarsForAppCodeProcess();
        $allOptsMeta = OptionsForProdMetadata::get();
        foreach (IterableUtil::keys($allOptsMeta) as $optName) {
            $envVarName = OptionForProdName::findByName($optName)->toEnvVarName();
            if (array_key_exists($envVarName, $envVars)) {
                $envVarsToInheritSource->set($optName, $envVars[$envVarName]);
            }
        }

        $explicitlySetOptionsSource = new MockConfigRawSnapshotSource();
        foreach ($this->prodOptions as $optName => $optVal) {
            $explicitlySetOptionsSource->set($optName->name, ConfigUtilForTests::optionValueToString($optVal));
        }
        $rawSnapshotSource = new CompositeRawSnapshotSource([$explicitlySetOptionsSource, $envVarsToInheritSource]);
        $rawSnapshot = $rawSnapshotSource->currentSnapshot($allOptsMeta);

        // Set log level above ERROR to hide potential errors when parsing the provided test configuration snapshot
        $logBackend = AmbientContextForTests::loggerFactory()->getBackend()->clone();
        $logBackend->setMaxEnabledLevel(LogLevel::critical);
        $loggerFactory = new LoggerFactory($logBackend);
        $parser = new ConfigParser($loggerFactory);
        return new ConfigSnapshotForProd($parser->parse($allOptsMeta, $rawSnapshot));
    }
}
