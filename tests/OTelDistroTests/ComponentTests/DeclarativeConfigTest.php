<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use OTelDistroTests\ComponentTests\Util\AgentBackendComms;
use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\AppCodeTarget;
use OTelDistroTests\ComponentTests\Util\AttributesExpectations;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\ComponentTestsPHPUnitExtension;
use OTelDistroTests\ComponentTests\Util\HttpServerHandle;
use OTelDistroTests\ComponentTests\Util\WaitForOTelSignalCounts;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\DebugContextScopeRef;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\MixedMap;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\TelemetryIncubatingAttributes;

/**
 * @group does_not_require_external_services
 */
final class DeclarativeConfigTest extends ComponentTestCaseBase
{
    private const YAML_TEMPLATE_FILE = __DIR__ . '/TestData/declarative_config_test.yaml';
    private const EXPECTED_SERVICE_NAME = 'declarative-config-component-test';
    private const EXPECTED_CUSTOM_ATTRIBUTE_VALUE = 'test-value-from-yaml';

    private const SUPPORTED_FILE_FORMAT = '1.0';
    /** The format of the previous SDK version (sdk-configuration 0.9.0), rejected since sdk-configuration 0.10.0 */
    private const UNSUPPORTED_FILE_FORMAT = '1.0-rc.2';
    private const SERVICE_NAME_FOR_UNSUPPORTED_FILE_FORMAT = 'declarative-config-unsupported-file-format';
    private const SERVICE_NAME_FOR_CONTROL = 'declarative-config-control';

    private function buildYamlConfigFile(string $fileFormat = self::SUPPORTED_FILE_FORMAT, string $serviceName = self::EXPECTED_SERVICE_NAME): string
    {
        $mockCollectorPort = ComponentTestsPHPUnitExtension::getGlobalTestInfra()->getMockOTelCollector()->getPortForAgent();
        /** @noinspection HttpUrlsUsage */
        $endpoint = 'http://' . HttpServerHandle::CLIENT_LOCALHOST_ADDRESS . ':' . $mockCollectorPort;
        $yamlContent = file_get_contents(self::YAML_TEMPLATE_FILE);
        self::assertNotFalse($yamlContent);
        $yamlContent = str_replace('${OTEL_EXPORTER_OTLP_ENDPOINT}', $endpoint, $yamlContent);
        // Guard against a template that changed and made the replacements below silently do nothing
        self::assertStringContainsString('file_format: "' . self::SUPPORTED_FILE_FORMAT . '"', $yamlContent);
        self::assertStringContainsString('value: ' . self::EXPECTED_SERVICE_NAME, $yamlContent);
        $yamlContent = str_replace('file_format: "' . self::SUPPORTED_FILE_FORMAT . '"', 'file_format: "' . $fileFormat . '"', $yamlContent);
        $yamlContent = str_replace('value: ' . self::EXPECTED_SERVICE_NAME, 'value: ' . $serviceName, $yamlContent);
        $tmpFile = tempnam(sys_get_temp_dir(), 'otel_decl_cfg_') . '.yaml';
        file_put_contents($tmpFile, $yamlContent);
        return $tmpFile;
    }

    private function implTestDeclarativeConfigResourceAttributes(): void
    {
        $yamlConfigFile = $this->buildYamlConfigFile();

        // Pre-initialize app code host with OTEL_CONFIG_FILE env var
        // ensureMainAppCodeHost is lazy - subsequent calls return the same instance
        $this->getTestCaseHandle()->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeHostParams) use ($yamlConfigFile): void {
                self::ensureTransactionSpanEnabled($appCodeHostParams);
                self::disableTimingDependentFeatures($appCodeHostParams);
                $appCodeHostParams->setAdditionalEnvVar('OTEL_CONFIG_FILE', $yamlConfigFile);
            }
        );

        self::implTestForAppCodeSetsHowFinished(
            testArgs: new MixedMap([]),
            subAppCode: [__CLASS__, 'appCodeEmpty'],
            additionalAssertCode: function (DebugContextScopeRef $dbgCtx, AgentBackendComms $agentBackendComms): void {
                $resources = IterableUtil::toList($agentBackendComms->resources());
                $dbgCtx->add(compact('resources'));
                AssertEx::isPositiveInt(count($resources));

                $resourceAttributesExpectations = new AttributesExpectations(
                    attributes: [
                        ServiceAttributes::SERVICE_NAME                         => self::EXPECTED_SERVICE_NAME,
                        'test.custom.attribute'                                 => self::EXPECTED_CUSTOM_ATTRIBUTE_VALUE,
                        TelemetryIncubatingAttributes::TELEMETRY_DISTRO_NAME    => 'opentelemetry-php-distro',
                    ],
                );

                foreach ($resources as $resource) {
                    $resourceAttributesExpectations->assertMatches($resource->attributes);
                }
            }
        );
    }

    public function testDeclarativeConfigResourceAttributes(): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTest(__CLASS__, __FUNCTION__),
            function (): void {
                $this->implTestDeclarativeConfigResourceAttributes();
            }
        );
    }

    /**
     * An unsupported `file_format` must not break the distro or the application: the SDK logs a warning and stays
     * a noop, so the application runs normally and exports nothing.
     *
     * A second app code host with a valid file is the positive control. Without it "nothing arrived" could be
     * a false pass of a test that simply did not wait for the data.
     */
    private function implTestDeclarativeConfigUnsupportedFileFormat(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $testCaseHandle = $this->getTestCaseHandle();

        $unsupportedFormatConfigFile = $this->buildYamlConfigFile(self::UNSUPPORTED_FILE_FORMAT, self::SERVICE_NAME_FOR_UNSUPPORTED_FILE_FORMAT);
        $controlConfigFile = $this->buildYamlConfigFile(self::SUPPORTED_FILE_FORMAT, self::SERVICE_NAME_FOR_CONTROL);

        $unsupportedFormatAppCodeHost = $testCaseHandle->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeHostParams) use ($unsupportedFormatConfigFile): void {
                self::ensureTransactionSpanEnabled($appCodeHostParams);
                self::disableTimingDependentFeatures($appCodeHostParams);
                $appCodeHostParams->setAdditionalEnvVar('OTEL_CONFIG_FILE', $unsupportedFormatConfigFile);
            },
            dbgInstanceName: 'unsupported file_format'
        );
        $controlAppCodeHost = $testCaseHandle->ensureAdditionalHttpAppCodeHost(
            dbgInstanceName: 'control',
            setParamsFunc: function (AppCodeHostParams $appCodeHostParams) use ($controlConfigFile): void {
                self::ensureTransactionSpanEnabled($appCodeHostParams);
                self::disableTimingDependentFeatures($appCodeHostParams);
                $appCodeHostParams->setAdditionalEnvVar('OTEL_CONFIG_FILE', $controlConfigFile);
            }
        );

        // execAppCode fails the test if the application does not finish successfully
        $unsupportedFormatAppCodeHost->execAppCode(AppCodeTarget::asRouted([__CLASS__, 'appCodeEmpty']));
        $controlAppCodeHost->execAppCode(AppCodeTarget::asRouted([__CLASS__, 'appCodeEmpty']));

        // Exactly one span is expected: the root span of the control host. A span of the host with the unsupported
        // format would exceed the expected count and fail the test.
        $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spans(1));
        $dbgCtx->add(compact('agentBackendComms'));

        $serviceNames = [];
        foreach ($agentBackendComms->resources() as $resource) {
            if ($resource->attributes->tryToGetValue(ServiceAttributes::SERVICE_NAME, /* out */ $serviceName)) {
                $serviceNames[] = $serviceName;
            }
        }
        $dbgCtx->add(compact('serviceNames'));
        self::assertContains(self::SERVICE_NAME_FOR_CONTROL, $serviceNames);
        self::assertNotContains(self::SERVICE_NAME_FOR_UNSUPPORTED_FILE_FORMAT, $serviceNames);
    }

    public function testDeclarativeConfigUnsupportedFileFormat(): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTest(__CLASS__, __FUNCTION__),
            function (): void {
                $this->implTestDeclarativeConfigUnsupportedFileFormat();
            }
        );
    }
}
