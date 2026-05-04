<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use OpenTelemetry\Distro\Util\ArrayUtil;
use OTelDistroTests\ComponentTests\Util\AppCodeContextDataUtil;
use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\AppCodeRequestParams;
use OTelDistroTests\ComponentTests\Util\AppCodeTarget;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\WaitForOTelSignalCounts;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\DataProviderForTestBuilder;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\MixedMap;

/**
 * @group smoke
 * @group does_not_require_external_services
 */
final class UserBootstrapPhpFileTest extends ComponentTestCaseBase
{
    private const USER_BOOTSTRAP_FILE_FULL_PATH = __DIR__ . DIRECTORY_SEPARATOR . 'user_bootstrap.php';

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function dataProviderForTestVariousValues(): iterable
    {
        return self::adaptDataProviderForTestBuilderToSmokeToDescToMixedMap(
            (new DataProviderForTestBuilder())
                ->addKeyedDimensionAllValuesCombinable(
                    OptionForProdName::user_bootstrap_php_file->name,
                    [
                        self::USER_BOOTSTRAP_FILE_FULL_PATH,
                        __DIR__ . DIRECTORY_SEPARATOR . 'user_bootstrap_file_that_does_not_exist.php',
                        null,
                        123, // not a valid value - not a ?string
                        678.9, // not a valid value - not a ?string
                    ]
                )
        );
    }

    public static function appCodeForTestVariousValues(MixedMap $appCodeArgs): void
    {
        self::appCodeSetsHowFinished(
            $appCodeArgs,
            /**
             * @retrun array<string, mixed>
             */
            function (): array {
                return [UserBootstrapPhpFileShared::GLOBALS_KEY => ArrayUtil::getValueIfKeyExistsElse(UserBootstrapPhpFileShared::GLOBALS_KEY, $GLOBALS, null)];
            }
        );
    }

    public function implTestVariousValues(MixedMap $testArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $testCaseHandle = $this->getTestCaseHandle();

        $appCodeHost = $testCaseHandle->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeParams) use ($testArgs): void {
                self::ensureTransactionSpanEnabled($appCodeParams);
                self::copyProdOptionsToAppCodeHostParams($testArgs, $appCodeParams);
            }
        );

        $appCodeArgs = $testArgs->cloneAsArray();
        AppCodeContextDataUtil::createTempFile($testCaseHandle, /* in,out */ $appCodeArgs);

        $appCodeHost->execAppCode(
            AppCodeTarget::asRouted([__CLASS__, 'appCodeForTestVariousValues']),
            function (AppCodeRequestParams $appCodeRequestParams) use ($appCodeArgs): void {
                $appCodeRequestParams->setAppCodeArgs($appCodeArgs);
            }
        );

        $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spans(1)); // exactly 1 span (the root span) is expected
        $dbgCtx->add(compact('agentBackendComms'));

        // Assert

        $appCodeContextData = AppCodeContextDataUtil::readDataAsMixedMapFromTempFile($appCodeArgs);
        $dbgCtx->add(compact('appCodeContextData'));
        self::assertTrue($appCodeContextData->getBool(self::DID_APP_CODE_FINISH_SUCCESSFULLY_KEY));

        $userBootstrapPhpFileOptVal = $testArgs->get(OptionForProdName::user_bootstrap_php_file->name);
        $globalsVal = $appCodeContextData->getNullableString(UserBootstrapPhpFileShared::GLOBALS_KEY);
        self::assertSame($userBootstrapPhpFileOptVal === self::USER_BOOTSTRAP_FILE_FULL_PATH ? UserBootstrapPhpFileShared::GLOBALS_VALUE : null, $globalsVal);
    }

    /**
     * @dataProvider dataProviderForTestVariousValues
     */
    public function testVariousValues(MixedMap $testArgs): void
    {
        self::runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTestWithArgs(__CLASS__, __FUNCTION__, $testArgs),
            function () use ($testArgs): void {
                $this->implTestVariousValues($testArgs);
            }
        );
    }
}
