<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Config;

use OpenTelemetry\Distro\Log\LogLevel;
use OTelDistroTests\ComponentTests\Util\PhpSerializationUtil;
use OTelDistroTests\ComponentTests\Util\TestInfraDataPerProcess;
use OTelDistroTests\ComponentTests\Util\TestInfraDataPerRequest;
use OTelDistroTests\ComponentTests\Util\TestMatrixRow;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class OptionsForTestsMetadata
{
    use OptionsMetadataTrait;

    /**
     * Constructor is hidden
     */
    private function __construct()
    {
        $parseTestInfraDataPerProcess = function (string $rawValue): TestInfraDataPerProcess {
            return PhpSerializationUtil::unserializeFromStringAssertType($rawValue, TestInfraDataPerProcess::class);
        };
        $parseTestInfraDataPerRequest = function (string $rawValue): TestInfraDataPerRequest {
            return PhpSerializationUtil::unserializeFromStringAssertType($rawValue, TestInfraDataPerRequest::class);
        };

        $parseTestMatrixRow = function (string $rawValue): TestMatrixRow {
            return TestMatrixRow::parse($rawValue);
        };

        /** @var array{OptionForTestsName, OptionMetadata<mixed>}[] $optNameMetaPairs */
        $optNameMetaPairs = [
            [OptionForTestsName::app_code_host_kind, new NullableAppCodeHostKindOptionMetadata()],
            [OptionForTestsName::app_code_php_exe, new NullableStringOptionMetadata()],
            [OptionForTestsName::app_code_bootstrap_php_part_file, new NullableStringOptionMetadata()],
            [OptionForTestsName::app_code_ext_binary, new NullableStringOptionMetadata()],

            [OptionForTestsName::data_per_process, new NullableCustomOptionMetadata($parseTestInfraDataPerProcess)],
            [OptionForTestsName::data_per_request, new NullableCustomOptionMetadata($parseTestInfraDataPerRequest)],

            [OptionForTestsName::escalated_reruns_max_count, new IntOptionMetadata(minValidValue: 0, maxValidValue: null, defaultValue: 10)],
            [OptionForTestsName::escalated_reruns_prod_code_log_level_option_name, new NullableStringOptionMetadata()],

            [OptionForTestsName::group, new NullableTestGroupNameOptionMetadata()],

            [OptionForTestsName::log_level, new LogLevelOptionMetadata(LogLevel::info)],
            [OptionForTestsName::logs_directory, new NullableStringOptionMetadata()],

            [OptionForTestsName::matrix_row, new NullableCustomOptionMetadata($parseTestMatrixRow)],

            [OptionForTestsName::mysql_host, new NullableStringOptionMetadata()],
            [OptionForTestsName::mysql_port, new NullableIntOptionMetadata(1, 65535)],
            [OptionForTestsName::mysql_user, new NullableStringOptionMetadata()],
            [OptionForTestsName::mysql_password, new NullableStringOptionMetadata()],
            [OptionForTestsName::mysql_db, new NullableStringOptionMetadata()],

            [OptionForTestsName::postgresql_host, new NullableStringOptionMetadata()],
            [OptionForTestsName::postgresql_port, new NullableIntOptionMetadata(1, 65535)],
            [OptionForTestsName::postgresql_user, new NullableStringOptionMetadata()],
            [OptionForTestsName::postgresql_password, new NullableStringOptionMetadata()],
            [OptionForTestsName::postgresql_db, new NullableStringOptionMetadata()],
        ];
        $this->optionsNameValueMap = self::convertPairsToMap($optNameMetaPairs, OptionForTestsName::cases());
    }
}
