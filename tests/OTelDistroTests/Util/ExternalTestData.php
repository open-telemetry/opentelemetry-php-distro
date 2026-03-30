<?php

declare(strict_types=1);

namespace OTelDistroTests\Util;

use OpenTelemetry\Distro\Util\StaticClassTrait;
use OpenTelemetry\Distro\Util\TextUtil;

final class ExternalTestData
{
    use StaticClassTrait;

    public static function readJsonSpecsFile(string $relativePathToFile): mixed
    {
        /** @var ?string $relPathFromTestsToJsonSpecs */
        static $relPathFromTestsToJsonSpecs = null;
        if ($relPathFromTestsToJsonSpecs === null) {
            $relPathFromTestsToJsonSpecs = FileUtil::adaptUnixDirectorySeparators('external_test_data/APM_Agents_shared/json-specs');
        }
        /** @var string $relPathFromTestsToJsonSpecs */
        $filePath = FileUtil::normalizePath(FileUtil::partsToPath(TestsRootDir::getFullPath(), $relPathFromTestsToJsonSpecs, $relativePathToFile));

        $fileContent = '';
        FileUtil::readLines(
            $filePath,
            function (string $line) use (&$fileContent): void {
                if (TextUtil::isPrefixOf('//', trim($line))) {
                    return;
                }
                $fileContent .= $line;
            }
        );

        return JsonUtil::decode($fileContent);
    }
}
