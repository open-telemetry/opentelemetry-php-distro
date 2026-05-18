<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\ScopedDepsTestApp;

final class ScopedDepsTestShared
{
    public const APP_LOG_LINE_PREFIX = '[OTel PHP Distro tests] [ScopedDepsTest]';

    public const TESTS_REPO_ROOT_DIR_PATH_ENV_VAR_NAME_SUFFIX = 'tests_repo_root_dir_path';
    public const APP_CODE_AUX_OUTPUT_FILE_PATH_ENV_VAR_NAME_SUFFIX = 'app_code_aux_output_file_path';
    public const IS_APP_COMPATIBLE_WITH_PSR_LOG_RETURN_TYPE_ENV_VAR_NAME_SUFFIX = 'is_app_compatible_with_psr_log_return_type';
    public const LOG_LEVEL_ENV_VAR_NAME_SUFFIX = 'log_level';

    public const OTEL_SDK_PACKAGE_NAME = 'open-telemetry/sdk';
    public const PSR_LOG_PACKAGE_NAME = 'psr/log';
    public const ALL_PACKAGE_NAMES = [self::OTEL_SDK_PACKAGE_NAME, self::PSR_LOG_PACKAGE_NAME];

    public const PSR_LOG_ABSTRACTLOGGER_CLASS_NAME = 'Psr\Log\AbstractLogger';
    public const PSR_LOG_ABSTRACT_LOGGER_METHOD_NAME = 'emergency';
    public const OPENTELEMETRY_SDK_SDK_CLASS_NAME = 'OpenTelemetry\SDK\Sdk';
    public const OPENTELEMETRY_SDK_IMMUTABLESPAN_TRACE_CLASS_NAME = 'OpenTelemetry\SDK\Trace\ImmutableSpan';
    public const ALL_CLASS_NAMES = [self::PSR_LOG_ABSTRACTLOGGER_CLASS_NAME, self::OPENTELEMETRY_SDK_SDK_CLASS_NAME, self::OPENTELEMETRY_SDK_IMMUTABLESPAN_TRACE_CLASS_NAME];

    public const DISTRO_ENABLED_CFG_OPT_NAME = 'enabled';
    public const DEBUG_SCOPER_ENABLED_CFG_OPT_NAME = 'debug_scoper_enabled';
    public const SCOPING_PREFIX = 'OTelDistroScoped';

    public const PACKAGES_VERSIONS_KEY = 'packages_versions';
    public const CLASSES_SOURCE_CODE_FILES_PATHS_KEY = 'classes_source_code_files_paths';
    public const PSR_LOG_HAS_RETURN_TYPE_KEY = 'psr_log_has_return_type';

    public const DISTRO_VENDOR_DIR_PATH_KEY = 'distro_vendor_dir_path';
    public const APP_VENDOR_DIR_PATH_KEY = 'app_vendor_dir_path';

    public static function buildEnvVarName(string $envVarNameSuffix): string
    {
        return str_replace("\\", '_', __NAMESPACE__) . '_' . $envVarNameSuffix;
    }

    /**
     * @phpstan-return 'Distro'|'App'
     */
    public static function buildDistroOrAppKey(bool $isDistro): string
    {
        return $isDistro ? 'Distro' : 'App';
    }

    /**
     * @phpstan-return 'scoped'|'not scoped'
     */
    public static function buildScopedKey(bool $isScoped): string
    {
        return ($isScoped ? '' : 'not ') . 'scoped';
    }
}
