<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\Log\LogCategoryForTests;
use OTelDistroTests\Util\Log\Logger;
use Override;
use PHPUnit\Framework\Assert;

final class HelperSleepsAndExitsWithArgCode extends SpawnedProcessBase
{
    private readonly Logger $logger;

    public function __construct()
    {
        parent::__construct();

        $this->logger = AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__)->addAllContext(compact('this'));

        ($loggerProxy = $this->logger->ifDebugLevelEnabled(__LINE__, __FUNCTION__)) && $loggerProxy->log('Done');
    }

    #[Override]
    protected function processConfig(): void
    {
        parent::processConfig();
        AmbientContextForTests::testConfig()->validateForAppCode();
    }

    public static function run(): void
    {
        self::runSkeleton(
            function (SpawnedProcessBase $thisObj): void {
                Assert::assertInstanceOf(self::class, $thisObj);
                $thisObj->runImpl();
            }
        );
    }

    #[Override]
    protected function isThisProcessTestScoped(): bool
    {
        return true;
    }

    private function runImpl(): never
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        Assert::assertSame('cli', php_sapi_name());

        /**
         * @see https://www.php.net/manual/en/reserved.variables.argv.php
         *
         * $argv
         * Note: This variable is not available when register_argc_argv is disabled.
         *
         * @see https://www.php.net/manual/en/ini.core.php#ini.register-argc-argv
         */
        /** @var list<string> $argv */
        global $argv;
        $dbgCtx->add(compact('argv'));
        AssertEx::countAtLeast(3, $argv);

        $secondsToSleep = AssertEx::stringIsInt($argv[1]);
        $exitCodeToExit = AssertEx::stringIsInt($argv[2]);

        echo basename(__FILE__) . ": Sleeping: $secondsToSleep seconds..." . PHP_EOL;
        sleep($secondsToSleep);

        echo basename(__FILE__) . ": Exiting with code: $exitCodeToExit" . PHP_EOL;
        exit($exitCodeToExit);
    }
}
