<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests;

use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\Config\OptionForTestsName;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\EnvVarUtil;
use OTelDistroTests\Util\MixedMap;
use OTelDistroTests\Util\TestCaseBase;

/**
 * @phpstan-import-type EnvVars from EnvVarUtil
 */
final class ComponentTestsEnvVarsForAppCodeTest extends TestCaseBase
{
    private const ENV_VARS_IN_PHPUNIT_CONTEXT_KEY = 'env_var_names_in_phpunit_context';
    private const EXPECTED_ENV_VARS_IN_APP_CODE_CONTEXT_KEY = 'expected_env_var_names_in_app_context';

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function dataProviderForTestFilterEnvVarsFromPhpUnitToAppCodeContext(): iterable
    {
        $envVarsThatShouldBePassed = [];
        foreach (['HOME', 'SHELL', 'XDG_SESSION_TYPE'] as $envVarName) {
            $envVarsThatShouldBePassed[$envVarName] = $envVarName . ' should be passed to app code context because it is not related to OTel Distro';
        }
        foreach (OptionForTestsName::cases() as $optName) {
            $envVarName = $optName->toEnvVarName();
            $envVarsThatShouldBePassed[$envVarName] = $envVarName . ' should be passed to app code context because it is a config option for OTel Distro tests infrastructure';
        }

        $envVarsThatShouldNotBePassed = [];
        foreach (OptionForProdName::cases() as $optName) {
            $envVarName = $optName->toEnvVarName();
            $envVarsThatShouldNotBePassed[$envVarName] = $envVarName . ' should NOT be passed to app code context because it is a config option for OTel Distro';
        }
        /**
         * @link https://getcomposer.org/doc/03-cli.md#composer
         */
        foreach (['COMPOSER', 'COMPOSER_DEV_MODE'] as $envVarName) {
            $envVarsThatShouldNotBePassed[$envVarName] = $envVarName . ' should NOT be passed to app code context because it MIGHT be a config option for Composer Dependency Manager for PHP';
        }

        /**
         * @phpstan-param EnvVars $envVarsInPHPUnitContext
         * @phpstan-param EnvVars $expectedEnvVarsInAppCodeContext
         *
         * @return array<string, mixed>
         */
        $generateDataSet = function (array $envVarsInPHPUnitContext, array $expectedEnvVarsInAppCodeContext): array {
            return [
                self::ENV_VARS_IN_PHPUNIT_CONTEXT_KEY => $envVarsInPHPUnitContext,
                self::EXPECTED_ENV_VARS_IN_APP_CODE_CONTEXT_KEY => $expectedEnvVarsInAppCodeContext,
            ];
        };

        /**
         * @return iterable<array<string, mixed>>
         */
        $generateDataSets = function () use ($generateDataSet, $envVarsThatShouldBePassed, $envVarsThatShouldNotBePassed): iterable {
            foreach ($envVarsThatShouldBePassed as $envVarName => $envVarValue) {
                $envVarThatShouldBePassed = [$envVarName => $envVarValue];
                yield $generateDataSet($envVarThatShouldBePassed, $envVarThatShouldBePassed);
            }

            foreach ($envVarsThatShouldNotBePassed as $envVarName => $envVarValue) {
                $envVarThatShouldNotBePassed = [$envVarName => $envVarValue];
                yield $generateDataSet($envVarThatShouldNotBePassed, []);
            }

            $allEnvVars = $envVarsThatShouldBePassed + $envVarsThatShouldNotBePassed;
            self::assertCount(count($envVarsThatShouldBePassed) + count($envVarsThatShouldNotBePassed), $allEnvVars);
            yield $generateDataSet($allEnvVars, $envVarsThatShouldBePassed);
        };

        return self::adaptDataSetsGeneratorToSmokeToDescToMixedMap($generateDataSets);
    }

    /**
     * @dataProvider dataProviderForTestFilterEnvVarsFromPhpUnitToAppCodeContext
     */
    public static function testFilterEnvVarsFromPhpUnitToAppCodeContext(MixedMap $testArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $envVarsInPHPUnitContext = AssertEx::isArray($testArgs->get(self::ENV_VARS_IN_PHPUNIT_CONTEXT_KEY));
        /** @var EnvVars $envVarsInPHPUnitContext */
        $expectedEnvVarsInAppCodeContext = AssertEx::isArray($testArgs->get(self::EXPECTED_ENV_VARS_IN_APP_CODE_CONTEXT_KEY));
        /** @var EnvVars $expectedEnvVarsInAppCodeContext */

        $actualEnvVarsInAppCodeContext = AppCodeHostParams::filterEnvVarsFromPhpUnitToAppCodeContext($envVarsInPHPUnitContext);

        AssertEx::equalMaps($expectedEnvVarsInAppCodeContext, $actualEnvVarsInAppCodeContext);
    }
}
