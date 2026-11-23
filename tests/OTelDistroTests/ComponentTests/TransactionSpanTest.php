<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use OTelDistroTests\ComponentTests\Util\AppCodeAuxOutputUtil;
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
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use OpenTelemetry\SemConv\Attributes\UserAgentAttributes;

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
    public static function dataProviderForTestTransactionSpan(): iterable
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

    private function implTestTransactionSpan(MixedMap $testArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $testCaseHandle = $this->getTestCaseHandle();
        $transactionSpanEnabled = $testArgs->getNullableBool(OptionForProdName::transaction_span_enabled->name);
        $transactionSpanEnabledCli = $testArgs->getNullableBool(OptionForProdName::transaction_span_enabled_cli->name);
        $isTransactionSpanEnabled = self::isTransactionSpanEnabled($transactionSpanEnabled, $transactionSpanEnabledCli);
        $shouldAppCodeCreateDummySpan = $testArgs->getBool(self::SHOULD_APP_CODE_CREATE_DUMMY_SPAN_KEY);
        // When the option is set to true explicitly, it reaches the application's own copy of the SDK as well (the distro only supplies
        // its default to its own runtime). Since SDK 1.17 that copy can export, so it sends its own root span, in a trace of its own.
        $isAppOwnSdkRootSpanExpected = (self::isMainAppCodeHostHttp() ? $transactionSpanEnabled : $transactionSpanEnabledCli) === true;

        $appCodeHost = $testCaseHandle->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeHostParams) use ($transactionSpanEnabled, $transactionSpanEnabledCli): void {
                $appCodeHostParams->setProdOptionIfNotNull(OptionForProdName::transaction_span_enabled, $transactionSpanEnabled);
                $appCodeHostParams->setProdOptionIfNotNull(OptionForProdName::transaction_span_enabled_cli, $transactionSpanEnabledCli);
            }
        );

        $appCodeRequestArgs = $testArgs->cloneAsArray();
        AppCodeAuxOutputUtil::createTempFile(__CLASS__, $testCaseHandle, /* in,out */ $appCodeRequestArgs);

        ArrayUtilForTests::addAssertingKeyNew(self::SUB_APP_CODE_TO_CALL_KEY, [__CLASS__, 'appCodeCreatesDummySpan'], /* in,out */ $appCodeRequestArgs);
        $appCodeHost->execAppCode(
            AppCodeTarget::asRouted([__CLASS__, 'appCodeSetsHowFinished']),
            function (AppCodeRequestParams $appCodeRequestParams) use ($appCodeRequestArgs): void {
                $appCodeRequestParams->setAppCodeRequestArgs($appCodeRequestArgs);
            }
        );

        $expectedSpanCount = 0;
        if ($isTransactionSpanEnabled) {
            ++$expectedSpanCount;
            if ($isAppOwnSdkRootSpanExpected) {
                ++$expectedSpanCount;
            }
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
                    HttpAttributes::HTTP_REQUEST_METHOD => HttpAppCodeRequestParams::DEFAULT_HTTP_REQUEST_METHOD,
                    ServerAttributes::SERVER_ADDRESS => $expectedRootSpanUrlParts->host,
                    ServerAttributes::SERVER_PORT => $expectedRootSpanUrlParts->port,
                    UrlAttributes::URL_FULL => UrlUtil::buildFullUrl($expectedRootSpanUrlParts),
                    UrlAttributes::URL_PATH => $expectedRootSpanUrlParts->path,
                    UrlAttributes::URL_SCHEME => $expectedRootSpanUrlParts->scheme,
                ],
            );
        } else {
            $expectedRootSpanKind = SpanKind::server;
            $rootSpanAttributesExpectations = new AttributesExpectations(
                attributes: [],
                notAllowedAttributes: [
                    HttpAttributes::HTTP_REQUEST_METHOD,
                    HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE,
                    ServerAttributes::SERVER_ADDRESS,
                    UrlAttributes::URL_FULL,
                    UrlAttributes::URL_PATH,
                    UrlAttributes::URL_SCHEME,
                    UserAgentAttributes::USER_AGENT_ORIGINAL,
                ],
            );
        }
        $expectationsForRootSpan = (new SpanExpectationsBuilder())->name(self::getExpectedTransactionSpanName())->kind($expectedRootSpanKind)->attributes($rootSpanAttributesExpectations)->build();

        $expectedDummySpanKind = SpanKind::internal;
        $expectationsForDummySpan = (new SpanExpectationsBuilder())->name(self::APP_CODE_DUMMY_SPAN_NAME)->kind($expectedDummySpanKind)->build();

        $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spans($expectedSpanCount));
        $dbgCtx->add(compact('agentBackendComms'));

        // Assert

        $appCodeAuxOutput = AppCodeAuxOutputUtil::readDataAsMixedMapFromTempFile($appCodeRequestArgs);
        $dbgCtx->add(compact('appCodeAuxOutput'));
        self::assertTrue($appCodeAuxOutput->getBool(self::DID_APP_CODE_FINISH_SUCCESSFULLY_KEY));

        $rootSpan = null;
        $dummySpan = null;
        if ($isTransactionSpanEnabled) {
            $rootSpans = IterableUtil::toList($agentBackendComms->findRootSpans());
            self::assertCount($isAppOwnSdkRootSpanExpected ? 2 : 1, $rootSpans);
            foreach ($rootSpans as $anyRootSpan) {
                $expectationsForRootSpan->assertMatches($anyRootSpan);
            }
            if ($isAppOwnSdkRootSpanExpected) {
                self::assertNotSame($rootSpans[0]->traceId, $rootSpans[1]->traceId);
            }
            /** @var Span $rootSpan */
            $rootSpan = ArrayUtilForTests::getFirstValue($rootSpans);
            if ($shouldAppCodeCreateDummySpan) {
                // The app code creates the dummy span with the distro's runtime, so its parent is the distro's root span
                /** @var Span $dummySpan */
                $dummySpan = $agentBackendComms->singleSpanByName(self::APP_CODE_DUMMY_SPAN_NAME);
                $dummySpanParent = null;
                foreach ($rootSpans as $anyRootSpan) {
                    if ($anyRootSpan->id === $dummySpan->parentId) {
                        $dummySpanParent = $anyRootSpan;
                    }
                }
                self::assertNotNull($dummySpanParent);
                $rootSpan = $dummySpanParent;
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
     * @dataProvider dataProviderForTestTransactionSpan
     */
    public function testTransactionSpan(MixedMap $testArgs): void
    {
        $this->runAndEscalateLogLevelOnFailure(self::buildDbgDescForTestWithArgs(__CLASS__, __FUNCTION__, $testArgs), fn() => $this->implTestTransactionSpan($testArgs));
    }
}
