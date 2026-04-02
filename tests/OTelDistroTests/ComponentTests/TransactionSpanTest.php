<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use OTelDistroTests\ComponentTests\Util\AppCodeContextDataUtil;
use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\AppCodeRequestParams;
use OTelDistroTests\ComponentTests\Util\AppCodeTarget;
use OTelDistroTests\ComponentTests\Util\AttributesExpectations;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\HttpAppCodeHostHandle;
use OTelDistroTests\ComponentTests\Util\HttpAppCodeRequestParams;
use OTelDistroTests\ComponentTests\Util\OtlpData\Span;
use OTelDistroTests\ComponentTests\Util\OtlpData\SpanKind;
use OTelDistroTests\ComponentTests\Util\SpanExpectationsBuilder;
use OTelDistroTests\ComponentTests\Util\UrlUtil;
use OTelDistroTests\ComponentTests\Util\WaitForOTelSignalCounts;
use OTelDistroTests\Util\ArrayUtilForTests;
use OTelDistroTests\Util\BoolUtilForTests;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\Config\OptionsForProdDefaultValues;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\MixedMap;
use OpenTelemetry\SemConv\TraceAttributes;

/**
 * @group smoke
 * @group does_not_require_external_services
 */
final class TransactionSpanTest extends ComponentTestCaseBase
{
    public static function isTransactionSpanEnabled(?bool $transactionSpanEnabled, ?bool $transactionSpanEnabledCli): bool
    {
        return self::isMainAppCodeHostHttp()
            ? ($transactionSpanEnabled ?? OptionsForProdDefaultValues::TRANSACTION_SPAN_ENABLED)
            : ($transactionSpanEnabledCli ?? OptionsForProdDefaultValues::TRANSACTION_SPAN_ENABLED_CLI);
    }

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function dataProviderForTestFeatureWithVariousEnabledConfigCombos(): iterable
    {
        /**
         * @return iterable<array<string, mixed>>
         */
        $generateDataSets = function (): iterable {
            foreach (BoolUtilForTests::ALL_NULLABLE_VALUES as $transactionSpanEnabled) {
                foreach (BoolUtilForTests::ALL_NULLABLE_VALUES as $transactionSpanEnabledCli) {
                    $shouldAppCodeCreateDummySpanValues = self::isTransactionSpanEnabled($transactionSpanEnabled, $transactionSpanEnabledCli) ? BoolUtilForTests::ALL_VALUES : [true];
                    foreach ($shouldAppCodeCreateDummySpanValues as $shouldAppCodeCreateDummySpan) {
                        yield [
                            OptionForProdName::transaction_span_enabled->name     => $transactionSpanEnabled,
                            OptionForProdName::transaction_span_enabled_cli->name => $transactionSpanEnabledCli,
                            self::SHOULD_APP_CODE_CREATE_DUMMY_SPAN_KEY           => $shouldAppCodeCreateDummySpan,
                        ];
                    }
                }
            }
        };

        return self::adaptDataSetsGeneratorToSmokeToDescToMixedMap($generateDataSets);
    }

    public static function appCodeForTestFeatureWithVariousEnabledConfigCombos(MixedMap $appCodeArgs): void
    {
        self::appCodeSetsHowFinished(
            $appCodeArgs,
            /**
             * @retrun array<string, mixed>
             */
            function () use ($appCodeArgs): array {
                self::appCodeCreatesDummySpan($appCodeArgs);
                return [];
            }
        );
    }

    public function implTestFeatureWithVariousEnabledConfigCombos(MixedMap $testArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $testCaseHandle = $this->getTestCaseHandle();
        $transactionSpanEnabled = $testArgs->getNullableBool(OptionForProdName::transaction_span_enabled->name);
        $transactionSpanEnabledCli = $testArgs->getNullableBool(OptionForProdName::transaction_span_enabled_cli->name);
        $isTransactionSpanEnabled = self::isTransactionSpanEnabled($transactionSpanEnabled, $transactionSpanEnabledCli);
        $shouldAppCodeCreateDummySpan = $testArgs->getBool(self::SHOULD_APP_CODE_CREATE_DUMMY_SPAN_KEY);

        $appCodeHost = $testCaseHandle->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeParams) use ($transactionSpanEnabled, $transactionSpanEnabledCli): void {
                $appCodeParams->setProdOptionIfNotNull(OptionForProdName::transaction_span_enabled, $transactionSpanEnabled);
                $appCodeParams->setProdOptionIfNotNull(OptionForProdName::transaction_span_enabled_cli, $transactionSpanEnabledCli);
            }
        );

        $appCodeArgs = $testArgs->cloneAsArray();
        AppCodeContextDataUtil::createTempFile($testCaseHandle, /* in,out */ $appCodeArgs);

        $appCodeHost->execAppCode(
            AppCodeTarget::asRouted([__CLASS__, 'appCodeForTestFeatureWithVariousEnabledConfigCombos']),
            function (AppCodeRequestParams $appCodeRequestParams) use ($appCodeArgs): void {
                $appCodeRequestParams->setAppCodeArgs($appCodeArgs);
            }
        );

        $expectedSpanCount = 0;
        if ($isTransactionSpanEnabled) {
            ++$expectedSpanCount;
        }
        if ($shouldAppCodeCreateDummySpan) {
            ++$expectedSpanCount;
        }
        self::assertGreaterThan(0, $expectedSpanCount);
        /** @var positive-int $expectedSpanCount */

        /** @noinspection PhpIfWithCommonPartsInspection */
        if (self::isMainAppCodeHostHttp()) {
            $expectedRootSpanKind = SpanKind::server;
            /** @var HttpAppCodeHostHandle $appCodeHost */
            $expectedRootSpanUrlParts = UrlUtil::buildUrlPartsWithDefaults(port: $appCodeHost->httpServerHandle->getMainPort());
            $rootSpanAttributesExpectations = new AttributesExpectations(
                [
                    TraceAttributes::HTTP_REQUEST_METHOD       => HttpAppCodeRequestParams::DEFAULT_HTTP_REQUEST_METHOD,
                    TraceAttributes::SERVER_ADDRESS            => $expectedRootSpanUrlParts->host,
                    TraceAttributes::SERVER_PORT               => $expectedRootSpanUrlParts->port,
                    TraceAttributes::URL_FULL                  => UrlUtil::buildFullUrl($expectedRootSpanUrlParts),
                    TraceAttributes::URL_PATH                  => $expectedRootSpanUrlParts->path,
                    TraceAttributes::URL_SCHEME                => $expectedRootSpanUrlParts->scheme,
                ]
            );
        } else {
            $expectedRootSpanKind = SpanKind::server;
            $rootSpanAttributesExpectations = new AttributesExpectations(
                attributes:           [],
                notAllowedAttributes: [
                                          TraceAttributes::HTTP_REQUEST_METHOD,
                                          TraceAttributes::HTTP_REQUEST_BODY_SIZE,
                                          TraceAttributes::SERVER_ADDRESS,
                                          TraceAttributes::URL_FULL,
                                          TraceAttributes::URL_PATH,
                                          TraceAttributes::URL_SCHEME,
                                          TraceAttributes::USER_AGENT_ORIGINAL,
                                      ]
            );
        }
        $expectationsForRootSpan = (new SpanExpectationsBuilder())->name(self::getExpectedTransactionSpanName())->kind($expectedRootSpanKind)->attributes($rootSpanAttributesExpectations)->build();

        $expectedDummySpanKind = SpanKind::internal;
        $expectationsForDummySpan = (new SpanExpectationsBuilder())->name(self::APP_CODE_DUMMY_SPAN_NAME)->kind($expectedDummySpanKind)->build();

        $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spans($expectedSpanCount));
        $dbgCtx->add(compact('agentBackendComms'));

        // Assert

        $appCodeContextData = AppCodeContextDataUtil::readDataAsMixedMapFromTempFile($appCodeArgs);
        $dbgCtx->add(compact('appCodeContextData'));
        self::assertTrue($appCodeContextData->getBool(self::DID_APP_CODE_FINISH_SUCCESSFULLY_KEY));

        $rootSpan = null;
        $dummySpan = null;
        if ($isTransactionSpanEnabled) {
            $rootSpans = IterableUtil::toList($agentBackendComms->findRootSpans());
            self::assertCount(1, $rootSpans);
            /** @var Span $rootSpan */
            $rootSpan = ArrayUtilForTests::getFirstValue($rootSpans);
            if ($shouldAppCodeCreateDummySpan) {
                $childSpans = IterableUtil::toList($agentBackendComms->findChildSpans($rootSpan->id));
                self::assertCount(1, $childSpans);
                /** @var Span $dummySpan */
                $dummySpan = ArrayUtilForTests::getFirstValue($childSpans);
            }
        } else {
            $dummySpan = $agentBackendComms->singleSpan();
        }
        $dbgCtx->add(compact('rootSpan', 'dummySpan'));

        self::assertSame($isTransactionSpanEnabled, $rootSpan !== null);
        if ($rootSpan !== null) {
            $expectationsForRootSpan->assertMatches($rootSpan);
        }

        self::assertSame($shouldAppCodeCreateDummySpan, $dummySpan !== null);
        if ($dummySpan !== null) {
            $expectationsForDummySpan->assertMatches($dummySpan);
        }
    }


    /**
     * @dataProvider dataProviderForTestFeatureWithVariousEnabledConfigCombos
     */
    public function testFeatureWithVariousEnabledConfigCombos(MixedMap $testArgs): void
    {
        self::runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTestWithArgs(__CLASS__, __FUNCTION__, $testArgs),
            function () use ($testArgs): void {
                $this->implTestFeatureWithVariousEnabledConfigCombos($testArgs);
            }
        );
    }
}
