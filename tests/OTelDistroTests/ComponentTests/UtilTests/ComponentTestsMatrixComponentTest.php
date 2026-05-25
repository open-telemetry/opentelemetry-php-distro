<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\UtilTests;

use OpenTelemetry\Distro\Log\LogLevel;
use OTelDistroTests\ComponentTests\Util\AgentBackendComms;
use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\EnvVarUtilForTests;
use OTelDistroTests\ComponentTests\Util\TestMatrixRow;
use OTelDistroTests\ComponentTests\Util\TestMatrixRowOptionalPart;
use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\Config\OptionForTestsName;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\DebugContextScopeRef;
use OTelDistroTests\Util\MixedMap;
use PHPUnit\Framework\Assert;

/**
 * @group does_not_require_external_services
 *
 * @phpstan-import-type OptionsForProdMap from AppCodeHostParams
 */
final class ComponentTestsMatrixComponentTest extends ComponentTestCaseBase
{
    private const ACTUAL_ENV_VARS_APP_CODE_CONTEXT_KEY = 'actual_env_vars_app_code_context';

    private const ROW_OPTIONAL_PART_TO_SET_KEY = 'row_optional_part_to_set';

    /**
     * @param array<string, string> $rowOptionalPartToSet
     */
    private static function appendMatrixRowOptionalPartToTheCurrentRow(array $rowOptionalPartToSet): string
    {
        $matrixRowOptionalPartSuffix = '';
        foreach ($rowOptionalPartToSet as $optName => $val) {
            /** @phpstan-var string $optName */
            /** @phpstan-var string $val */
            $envVarName = OptionForProdName::findByName($optName)->toEnvVarName();
            $matrixRowOptionalPartSuffix .= TestMatrixRow::ROW_ELEMENTS_SEPARATOR . $envVarName . TestMatrixRowOptionalPart::KEY_VALUE_SEPARATOR . $val;
        }
        return AmbientContextForTests::testConfig()->matrixRow()->mandatoryPartRaw() . $matrixRowOptionalPartSuffix;
    }

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function dataProviderForTestRowOptionalPart(): iterable
    {
        /**
         * @param array<string, string> $rowOptionalPartToSet
         *
         * @return array<string, mixed>
         */
        $generateDataSet = function (array $rowOptionalPartToSet): array {
            return [
                self::ROW_OPTIONAL_PART_TO_SET_KEY => $rowOptionalPartToSet,
            ];
        };

        /**
         * @return iterable<array<string, mixed>>
         */
        $generateDataSets = function () use ($generateDataSet): iterable {
            // Generate the use case of not setting matrix row optional part by the component tests
            // but only using the one passed via the matrix row
            yield $generateDataSet([]);

            // If the matrix row does have the optional part - do not generate cases to set the optional part
            if (AmbientContextForTests::testConfig()->matrixRow()->optionalPart !== null) {
                return;
            }

            yield $generateDataSet([OptionForProdName::log_level_stderr->name => LogLevel::debug->name]);
            yield $generateDataSet([OptionForProdName::log_level_syslog->name => LogLevel::trace->name]);
            yield $generateDataSet([OptionForProdName::log_level_stderr->name => LogLevel::warning->name, OptionForProdName::log_level_syslog->name => LogLevel::debug->name]);
        };

        return self::adaptDataSetsGeneratorToSmokeToDescToMixedMap($generateDataSets);
    }

    /**
     * @return array<string, mixed>
     */
    public static function appCodeForTestRowOptionalPartThatAlreadySetFromOutside(): array
    {
        return [
            self::ACTUAL_ENV_VARS_APP_CODE_CONTEXT_KEY => EnvVarUtilForTests::getAll(),
        ];
    }

    /**
     * @dataProvider dataProviderForTestRowOptionalPart
     */
    public function testRowOptionalPart(MixedMap $testArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        /** @var array<string, string> $rowOptionalPartToSet */
        $rowOptionalPartToSet = AssertEx::isArray($testArgs->get(self::ROW_OPTIONAL_PART_TO_SET_KEY));
        $matrixRowToSet = $rowOptionalPartToSet === [] ? null : self::appendMatrixRowOptionalPartToTheCurrentRow($rowOptionalPartToSet);

        // If the matrix row does have the optional part -
        // cases to set a different matrix row should NOT be generated
        if (AmbientContextForTests::testConfig()->matrixRow()->optionalPart !== null) {
            AssertEx::isNull($matrixRowToSet);
        }
        if ($matrixRowToSet !== null) {
            $matrixRowEnvVarValToRestore = EnvVarUtilForTests::get(OptionForTestsName::matrix_row->toEnvVarName());
            EnvVarUtilForTests::set(OptionForTestsName::matrix_row->toEnvVarName(), $matrixRowToSet);

            AmbientContextForTests::reconfigure();

            /** @var OptionsForProdMap $ambientContextForTestsRowOptionalPartProdOptions */
            $ambientContextForTestsRowOptionalPartProdOptions = AssertEx::notNull(AmbientContextForTests::testConfig()->matrixRow()->optionalPart?->prodOptions);
            $dbgCtx->add(compact('ambientContextForTestsRowOptionalPartProdOptions'));
            Assert::assertSame(count($rowOptionalPartToSet), $ambientContextForTestsRowOptionalPartProdOptions->count());
            foreach ($ambientContextForTestsRowOptionalPartProdOptions as $optName => $optVal) {
                $dbgCtx->resetTopSubScope(compact('optName', 'optVal'));
                /** @var OptionForProdName $optName */
                self::assertSame($rowOptionalPartToSet[AssertEx::isInstanceOf(OptionForProdName::class, $optName)->name], $optVal);
            }
        }

        $this->implTestForAppCodeSetsHowFinished(
            testArgs: new MixedMap(),
            subAppCode: [__CLASS__, 'appCodeForTestRowOptionalPartThatAlreadySetFromOutside'],
            additionalAssertCode: function (DebugContextScopeRef $dbgCtx, AgentBackendComms $agentBackendComms, MixedMap $appCodeAuxOutput): void {
                $actualEnvVarsInAppContext = AssertEx::isArray($appCodeAuxOutput->get(self::ACTUAL_ENV_VARS_APP_CODE_CONTEXT_KEY));
                $dbgCtx->pushSubScope();
                $prodOptions = AmbientContextForTests::testConfig()->matrixRow()->optionalPart->prodOptions ?? [];
                foreach ($prodOptions as $optName => $expectedOptRawValue) {
                    $dbgCtx->resetTopSubScope(compact('optName', 'expectedOptRawValue'));
                    self::assertSame($expectedOptRawValue, $actualEnvVarsInAppContext[$optName->toEnvVarName()]);
                }
                $dbgCtx->popSubScope();
            }
        );

        if ($matrixRowToSet !== null) {
            EnvVarUtilForTests::setOrUnset(OptionForTestsName::matrix_row->toEnvVarName(), $matrixRowEnvVarValToRestore);
            AmbientContextForTests::reconfigure();
        }
    }
}
