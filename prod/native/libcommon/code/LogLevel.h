#pragma once

#include <string_view>

/**
 * The order is important because lower numeric values are considered contained in higher ones
 * for example logLevel_error means that both logLevel_error and logLevel_critical is enabled.
 */


// namespace opentelemetry::php {


enum LogLevel {
    /**
     * logLevel_off should not be used by logging statements - it is used only in configuration.
     */
    logLevel_off = 0,
    logLevel_critical,
    logLevel_error,
    logLevel_warning,
    logLevel_info,
    logLevel_debug,
    logLevel_trace,

    first = logLevel_off,
    last = logLevel_trace
};

// }

std::string_view getLogLevelName(LogLevel level);