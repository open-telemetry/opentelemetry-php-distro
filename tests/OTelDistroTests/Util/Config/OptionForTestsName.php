<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Config;

use OTelDistroTests\Util\EnumUtilForTestsTrait;

enum OptionForTestsName
{
    use EnumUtilForTestsTrait;

    case app_code_bootstrap_php_part_file;
    case app_code_ext_binary;
    case app_code_host_kind;
    case app_code_php_exe;

    case data_per_process;
    case data_per_request;

    case escalated_reruns_prod_code_log_level_option_name;
    case escalated_reruns_max_count;

    case group;

    case log_level;
    case logs_directory;

    case matrix_row;

    case mysql_host;
    case mysql_port;
    case mysql_user;
    case mysql_password;
    case mysql_db;

    case postgresql_host;
    case postgresql_port;
    case postgresql_user;
    case postgresql_password;
    case postgresql_db;

    public const ENV_VAR_NAME_PREFIX = 'OTEL_PHP_TESTS_';

    public function toEnvVarName(): string
    {
        return EnvVarsRawSnapshotSource::optionNameToEnvVarName(self::ENV_VAR_NAME_PREFIX, $this->name);
    }
}
