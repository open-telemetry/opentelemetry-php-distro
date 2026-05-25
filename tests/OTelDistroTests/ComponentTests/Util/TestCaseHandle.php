<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use Closure;
use OpenTelemetry\Distro\Log\LogLevel;
use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\ExceptionUtil;
use OTelDistroTests\Util\Log\LogCategoryForTests;
use OTelDistroTests\Util\Log\LoggableInterface;
use OTelDistroTests\Util\Log\LoggableToString;
use OTelDistroTests\Util\Log\LoggableTrait;
use OTelDistroTests\Util\Log\Logger;
use OTelDistroTests\Util\TimeUtil;
use PHPUnit\Framework\Assert;

final class TestCaseHandle implements LoggableInterface
{
    use LoggableTrait;

    public const MAX_WAIT_TIME_DATA_FROM_AGENT_SECONDS = 3 * HttpClientUtilForTests::MAX_WAIT_TIME_SECONDS;

    private ResourcesCleanerHandle $resourcesCleaner;

    private MockOTelCollectorHandle $mockOTelCollector;

    /** @var AppCodeInvocation[] */
    public array $appCodeInvocations = [];

    protected ?AppCodeHostHandle $mainAppCodeHost = null;

    protected ?HttpAppCodeHostHandle $additionalHttpAppCodeHost = null;

    private readonly Logger $logger;

    /** @var int[] */
    private array $portsInUse;

    public function __construct(
        private readonly ?LogLevel $escalatedLogLevelForProdCode,
    ) {
        $this->logger = AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__);

        ExceptionUtil::runCatchLogRethrow(
            function (): void {
                $globalTestInfra = ComponentTestsPHPUnitExtension::getGlobalTestInfra();
                $this->resourcesCleaner = $globalTestInfra->getResourcesCleaner();
                $this->mockOTelCollector = $globalTestInfra->getMockOTelCollector();
                $this->portsInUse = $globalTestInfra->getPortsInUse();
                $globalTestInfra->onTestStart();
            }
        );

        $this->logger->addAllContext(compact('this'));
    }

    /**
     * @param null|Closure(AppCodeHostParams): void $setParamsFunc
     */
    public function ensureMainAppCodeHost(?Closure $setParamsFunc = null, string $dbgInstanceName = 'main'): AppCodeHostHandle
    {
        if ($this->mainAppCodeHost === null) {
            $this->mainAppCodeHost = $this->startAppCodeHost(
                function (AppCodeHostParams $params) use ($setParamsFunc): void {
                    $this->autoSetProdOptions($params);
                    if ($setParamsFunc !== null) {
                        $setParamsFunc($params);
                    }
                },
                $dbgInstanceName
            );
        }
        return $this->mainAppCodeHost;
    }

    /**
     * @param null|Closure(HttpAppCodeHostParams): void $setParamsFunc
     *
     * @noinspection PhpUnused
     */
    public function ensureAdditionalHttpAppCodeHost(string $dbgInstanceName, ?Closure $setParamsFunc = null): HttpAppCodeHostHandle
    {
        if ($this->additionalHttpAppCodeHost === null) {
            $this->additionalHttpAppCodeHost = $this->startBuiltinHttpServerAppCodeHost(
                function (HttpAppCodeHostParams $appCodeHostParams) use ($setParamsFunc): void {
                    $this->autoSetProdOptions($appCodeHostParams);
                    if ($setParamsFunc !== null) {
                        $setParamsFunc($appCodeHostParams);
                    }
                },
                $dbgInstanceName
            );
        }
        return $this->additionalHttpAppCodeHost;
    }

    public function waitForEnoughAgentBackendComms(IsEnoughAgentBackendCommsInterface $expectedIsEnough): AgentBackendComms
    {
        Assert::assertNotEmpty($this->appCodeInvocations);
        $accumulator = new AgentBackendCommsAccumulator();
        $hasPassed = (new PollingCheck(__FUNCTION__ . ' passes', intval(TimeUtil::secondsToMicroseconds(self::MAX_WAIT_TIME_DATA_FROM_AGENT_SECONDS))))->run(
            function () use ($expectedIsEnough, $accumulator) {
                $accumulator->addEvents($this->mockOTelCollector->fetchNewAgentBackendCommEvents(shouldWait: true));
                return $accumulator->isEnough($expectedIsEnough);
            }
        );

        $accumulatedData = $accumulator->getResult();
        if (!$hasPassed) {
            DebugContext::getCurrentScope(/* out */ $dbgCtx);
            $accumulatedDataSummary = $accumulatedData->dbgGetSummary();
            $dbgCtx->add(compact('expectedIsEnough', 'accumulatedDataSummary', 'accumulatedData', 'accumulator'));
            Assert::fail('The expected exported data has not arrived; ' . LoggableToString::convert(compact('expectedIsEnough', 'accumulatedDataSummary')));
        }

        return $accumulatedData;
    }

    private function autoSetProdOptions(AppCodeHostParams $params): void
    {
        if ($this->escalatedLogLevelForProdCode !== null) {
            $params->setProdOption(AmbientContextForTests::testConfig()->escalatedRerunsProdCodeLogLevelOptionName(), $this->escalatedLogLevelForProdCode->name);
        }
        /** @noinspection HttpUrlsUsage */
        $params->setProdOption(OptionForProdName::exporter_otlp_endpoint, 'http://' . HttpServerHandle::CLIENT_LOCALHOST_ADDRESS . ':' . $this->mockOTelCollector->getPortForAgent());
    }

    public function addAppCodeInvocation(AppCodeInvocation $appCodeInvocation): void
    {
        $appCodeInvocation->appCodeHostsParams = [];
        if ($this->mainAppCodeHost !== null) {
            $appCodeInvocation->appCodeHostsParams[] = $this->mainAppCodeHost->appCodeHostParams;
        }
        if ($this->additionalHttpAppCodeHost !== null) {
            $appCodeInvocation->appCodeHostsParams[] = $this->additionalHttpAppCodeHost->appCodeHostParams;
        }
        $this->appCodeInvocations[] = $appCodeInvocation;
    }

    /**
     * @return array<string, LogLevel>
     */
    public function getProdCodeLogLevels(OptionForProdName $logLevelOptName): array
    {
        $result = [];
        /** @var ?AppCodeHostHandle $appCodeHost */
        foreach (['mainAppCodeHost' => $this->mainAppCodeHost, 'additionalHttpAppCodeHost' => $this->additionalHttpAppCodeHost] as $dbgDesc => $appCodeHost) {
            if ($appCodeHost !== null) {
                $result[$dbgDesc] = AssertEx::isInstanceOf(LogLevel::class, $appCodeHost->appCodeHostParams->buildProdConfig()->getOptionValueByName($logLevelOptName));
            }
        }
        return $result;
    }

    public function tearDown(): void
    {
        $this->logger->logDebug(__FUNCTION__)?->with(__LINE__, 'Tearing down...');

        ExceptionUtil::runCatchLogRethrow(
            function (): void {
                ComponentTestsPHPUnitExtension::getGlobalTestInfra()->onTestEnd();
            }
        );
    }

    /**
     * @param int[] $ports
     *
     * @return void
     */
    private function addPortsInUse(array $ports): void
    {
        foreach ($ports as $port) {
            Assert::assertNotContains($port, $this->portsInUse);
            $this->portsInUse[] = $port;
        }
    }

    private function startBuiltinHttpServerAppCodeHost(Closure $setParamsFunc, string $dbgInstanceName): BuiltinHttpServerAppCodeHostHandle
    {
        $this->logger->logDebug(__FUNCTION__)?->with(__LINE__, 'Starting built-in HTTP server to host app code ...', compact('dbgInstanceName'));

        $result = new BuiltinHttpServerAppCodeHostHandle($this, $setParamsFunc, $this->resourcesCleaner, $this->portsInUse, $dbgInstanceName);
        $this->addPortsInUse($result->httpServerHandle->ports);

        $this->logger->logDebug(__FUNCTION__)?->with(__LINE__, 'Started built-in HTTP server to host app code', ['ports' => $result->httpServerHandle->ports]);

        return $result;
    }

    /**
     * @param Closure(AppCodeHostParams): void $setParamsFunc
     */
    private function startAppCodeHost(Closure $setParamsFunc, string $dbgInstanceName): AppCodeHostHandle
    {
        return match (AmbientContextForTests::testConfig()->appCodeHostKind()) {
            AppCodeHostKind::CLI_script => new CliScriptAppCodeHostHandle($this, $setParamsFunc, $this->resourcesCleaner, $dbgInstanceName),
            AppCodeHostKind::Builtin_HTTP_server => $this->startBuiltinHttpServerAppCodeHost($setParamsFunc, $dbgInstanceName),
        };
    }

    public function getResourcesCleaner(): ResourcesCleanerHandle
    {
        return $this->resourcesCleaner;
    }

    public function getResourcesCleanerClient(): ResourcesCleanerClient
    {
        return $this->resourcesCleaner->getClient();
    }
}
