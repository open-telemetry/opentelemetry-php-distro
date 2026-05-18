<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use Override;

final class CliScriptAppCodeHost extends AppCodeHostBase
{
    #[Override]
    protected function runImpl(): void
    {
        $this->callAppCode();
    }
}
