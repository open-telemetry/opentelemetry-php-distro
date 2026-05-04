<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests\ConfigTests;

use OpenTelemetry\Distro\PhpPartFacade;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\TestCaseBase;

class ProdAndTestCodeInSyncTest extends TestCaseBase
{
    public function testProdAndTestCodeInSyncTest(): void
    {
        AssertEx::sameConstValues(PhpPartFacade::USER_BOOTSTRAP_PHP_FILE_OPT_NAME, OptionForProdName::user_bootstrap_php_file->name);
    }
}
