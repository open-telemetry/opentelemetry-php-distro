<?php

/**
 * PhpUnitExtension is used in phpunit_component_tests.xml
 *
 * @noinspection PhpUnused
 */

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OpenTelemetry\Distro\Log\LogLevel;
use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Log\LogCategoryForTests;
use OTelDistroTests\Util\Log\Logger;
use OTelDistroTests\Util\PHPUnitExtensionBase;
use Override;
use Throwable;

/**
 * Referenced in PHPUnit's configuration file - phpunit_component_tests.xml
 */
final class ComponentTestsPHPUnitExtension extends PHPUnitExtensionBase
{
    private readonly Logger $logger;
    private static ?GlobalTestInfra $globalTestInfra = null;

    public function __construct()
    {
        parent::__construct();

        $this->logger = AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__);
        $this->logger->addContext('appCodeHostKind', AmbientContextForTests::testConfig()->appCodeHostKind());

        try {
            // We spin off test infrastructure servers here and not on demand
            // in self::getGlobalTestInfra() because PHPUnit might fork to run individual tests
            // and ResourcesCleaner would track the PHPUnit child process as its master which would be wrong
            self::$globalTestInfra = new GlobalTestInfra();
        } catch (Throwable $throwable) {
            ($loggerProxy = $this->logger->ifCriticalLevelEnabled(__LINE__, __FUNCTION__))
            && $loggerProxy->logThrowable($throwable, 'Throwable escaped from GlobalTestInfra constructor');
            throw $throwable;
        }
    }

    public function __destruct()
    {
        ($loggerProxy = $this->logger->ifDebugLevelEnabled(__LINE__, __FUNCTION__))
        && $loggerProxy->log('Destroying...');

        self::$globalTestInfra?->getResourcesCleaner()->signalAndWaitForItToExit();
    }

    public static function getGlobalTestInfra(): GlobalTestInfra
    {
        return AssertEx::notNull(self::$globalTestInfra);
    }

    #[Override]
    protected function logLevelForEnvInfo(): LogLevel
    {
        return LogLevel::info;
    }
}
