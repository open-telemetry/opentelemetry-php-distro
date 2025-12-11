
#include "ModuleFunctions.h"
#include "ConfigurationStorage.h"
#include "LoggerInterface.h"
#include "LogFeature.h"
#include "RequestScope.h"
#include "ModuleGlobals.h"
#include "ModuleFunctionsImpl.h"
#include "InternalFunctionInstrumentation.h"
#undef snprintf
#include "coordinator/CoordinatorProcess.h"
#include "transport/OpAmp.h"
#include "PhpBridge.h"
#include "OtlpExporter/LogsConverter.h"
#include "OtlpExporter/MetricConverter.h"
#include "OtlpExporter/SpanConverter.h"

#include <main/php.h>
#include <Zend/zend_API.h>
#include <Zend/zend_closures.h>
#include <Zend/zend_exceptions.h>

namespace opentelemetry::php::module_functions {

// bool is_enabled()
PHP_FUNCTION(is_enabled) {
    RETVAL_BOOL(false);
    ZEND_PARSE_PARAMETERS_NONE();

    RETVAL_BOOL(EAPM_CFG(enabled));
}

ZEND_BEGIN_ARG_INFO_EX(get_config_option_by_name_arginfo, 0, 0, 1)
ZEND_ARG_TYPE_INFO(/* pass_by_ref: */ 0, optionName, IS_STRING, /* allow_null: */ 0)
ZEND_END_ARG_INFO()

/* get_config_option_by_name( string $optionName ): mixed */
PHP_FUNCTION(get_config_option_by_name) {
    ZVAL_NULL(return_value);
    char *optionName = nullptr;
    size_t optionNameLength = 0;

    ZEND_PARSE_PARAMETERS_START(1, 1)
    Z_PARAM_STRING(optionName, optionNameLength)
    ZEND_PARSE_PARAMETERS_END();

    getConfigOptionbyName({optionName, optionNameLength}, /* out */ return_value);
}

ZEND_BEGIN_ARG_INFO_EX(log_feature_arginfo, /* _unused: */ 0, /* return_reference: */ 0, /* required_num_args: */ 7)
ZEND_ARG_TYPE_INFO(/* pass_by_ref: */ 0, isForced, IS_LONG, /* allow_null: */ 0)
ZEND_ARG_TYPE_INFO(/* pass_by_ref: */ 0, level, IS_LONG, /* allow_null: */ 0)
ZEND_ARG_TYPE_INFO(/* pass_by_ref: */ 0, feature, IS_LONG, /* allow_null: */ 0)
ZEND_ARG_TYPE_INFO(/* pass_by_ref: */ 0, file, IS_STRING, /* allow_null: */ 0)
ZEND_ARG_TYPE_INFO(/* pass_by_ref: */ 0, line, IS_LONG, /* allow_null: */ 1)
ZEND_ARG_TYPE_INFO(/* pass_by_ref: */ 0, func, IS_STRING, /* allow_null: */ 0)
ZEND_ARG_TYPE_INFO(/* pass_by_ref: */ 0, message, IS_STRING, /* allow_null: */ 0)
ZEND_END_ARG_INFO()

/* {{{ log_feature(
 *      int $isForced,
 *      int $level,
 *      int $feature,
 *      string $file,
 *      ?int $line,
 *      string $func,
 *      string $message
 *  ): void
 */
PHP_FUNCTION(log_feature) {
    zend_long isForced = 0;
    zend_long level = 0;
    zend_long feature = 0;
    char *file = nullptr;
    size_t fileLength = 0;
    zend_long line = 0;
    bool lineNull = true;
    char *func = nullptr;
    size_t funcLength = 0;
    char *message = nullptr;
    size_t messageLength = 0;

    ZEND_PARSE_PARAMETERS_START(/* min_num_args: */ 7, /* max_num_args: */ 7)
    Z_PARAM_LONG(isForced)
    Z_PARAM_LONG(level)
    Z_PARAM_LONG(feature)
    Z_PARAM_STRING(file, fileLength)
    Z_PARAM_LONG_OR_NULL(line, lineNull)
    Z_PARAM_STRING(func, funcLength)
    Z_PARAM_STRING(message, messageLength)
    ZEND_PARSE_PARAMETERS_END();

    if (isForced || OTEL_G(globals)->logger_->doesFeatureMeetsLevelCondition(static_cast<LogLevel>(level), static_cast<opentelemetry::php::LogFeature>(feature))) {
        if (lineNull) {
            OTEL_G(globals)->logger_->printf(static_cast<LogLevel>(level), "[" PRsv "] [" PRsv "] [" PRsv "] " PRsv, PRsvArg(opentelemetry::php::getLogFeatureName(static_cast<opentelemetry::php::LogFeature>(feature))), PRcsvArg(file, fileLength), PRcsvArg(func, funcLength), PRcsvArg(message, messageLength));
            return;
        }
        OTEL_G(globals)->logger_->printf(static_cast<LogLevel>(level), "[" PRsv "] [" PRsv ":%d] [" PRsv "] " PRsv, PRsvArg(opentelemetry::php::getLogFeatureName(static_cast<opentelemetry::php::LogFeature>(feature))), PRcsvArg(file, fileLength), line, PRcsvArg(func, funcLength), PRcsvArg(message, messageLength));
    }
}
/* }}} */


ZEND_BEGIN_ARG_INFO(no_params_arginfo, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(hook_arginfo, 0, 2, _IS_BOOL, 0)
ZEND_ARG_TYPE_INFO(0, class, IS_STRING, 1)
ZEND_ARG_TYPE_INFO(0, function, IS_STRING, 0)
ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, pre, Closure, 1, "null")
ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, post, Closure, 1, "null")
ZEND_END_ARG_INFO()

PHP_FUNCTION(hook) {
    zend_string *class_name = nullptr;
    zend_string *function_name = nullptr;
    zval *pre = NULL;
    zval *post = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 4)
    Z_PARAM_STR_OR_NULL(class_name)
    Z_PARAM_STR(function_name)
    Z_PARAM_OPTIONAL
    Z_PARAM_OBJECT_OF_CLASS_OR_NULL(pre, zend_ce_closure)
    Z_PARAM_OBJECT_OF_CLASS_OR_NULL(post, zend_ce_closure)
    ZEND_PARSE_PARAMETERS_END();

    std::string_view className = class_name ? std::string_view{ZSTR_VAL(class_name), ZSTR_LEN(class_name)} : std::string_view{};
    std::string_view functionName = function_name ? std::string_view{ZSTR_VAL(function_name), ZSTR_LEN(function_name)} : std::string_view{};

    // if (!OTEL_GL(requestScope_)->isFunctional()) {
    //     ELOGF_DEBUG(OTEL_GL(logger_), MODULE, "hook. Can't instrument " PRsv "::" PRsv " beacuse agent is not functional.", PRsvArg(className), PRsvArg(functionName));
    //     RETURN_BOOL(false);
    //     return;
    // }

    RETURN_BOOL(opentelemetry::php::instrumentFunction(OTEL_GL(logger_).get(), className, functionName, pre, post));
}

ZEND_BEGIN_ARG_INFO_EX(ArgInfoInitialize, 0, 0, 3)
ZEND_ARG_TYPE_INFO(0, endpoint, IS_STRING, 1)
ZEND_ARG_TYPE_INFO(0, contentType, IS_STRING, 0)
ZEND_ARG_TYPE_INFO(0, headers, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

// TODO try to find better place for this function
static opentelemetry::php::transport::HttpEndpointSSLOptions getSSLOptionsForSignalsEndpoint(std::string_view endpointUrl) {
    // NOTE not comparing endpointUrl directly with value from configuration because it might be slightly modified by HttpEndpointResolver.php

    if (endpointUrl.ends_with("/v1/traces") && !EAPM_CFG(OTEL_EXPORTER_OTLP_TRACES_ENDPOINT).empty()) {
        opentelemetry::php::transport::HttpEndpointSSLOptions sslOptions;
        sslOptions.insecureSkipVerify = EAPM_CFG(OTEL_EXPORTER_OTLP_TRACES_INSECURE);
        sslOptions.caInfo = EAPM_CFG(OTEL_EXPORTER_OTLP_TRACES_CERTIFICATE);
        sslOptions.cert = EAPM_CFG(OTEL_EXPORTER_OTLP_TRACES_CLIENT_CERTIFICATE);
        sslOptions.certKey = EAPM_CFG(OTEL_EXPORTER_OTLP_TRACES_CLIENT_KEY);
        sslOptions.certKeyPassword = EAPM_CFG(OTEL_EXPORTER_OTLP_TRACES_CLIENT_KEYPASS);

        return sslOptions;
    }

    if (endpointUrl.ends_with("/v1/metrics") && !EAPM_CFG(OTEL_EXPORTER_OTLP_METRICS_ENDPOINT).empty()) {
        opentelemetry::php::transport::HttpEndpointSSLOptions sslOptions;
        sslOptions.insecureSkipVerify = EAPM_CFG(OTEL_EXPORTER_OTLP_METRICS_INSECURE);
        sslOptions.caInfo = EAPM_CFG(OTEL_EXPORTER_OTLP_METRICS_CERTIFICATE);
        sslOptions.cert = EAPM_CFG(OTEL_EXPORTER_OTLP_METRICS_CLIENT_CERTIFICATE);
        sslOptions.certKey = EAPM_CFG(OTEL_EXPORTER_OTLP_METRICS_CLIENT_KEY);
        sslOptions.certKeyPassword = EAPM_CFG(OTEL_EXPORTER_OTLP_METRICS_CLIENT_KEYPASS);

        return sslOptions;
    }

    if (endpointUrl.ends_with("/v1/logs") && !EAPM_CFG(OTEL_EXPORTER_OTLP_LOGS_ENDPOINT).empty()) {
        opentelemetry::php::transport::HttpEndpointSSLOptions sslOptions;
        sslOptions.insecureSkipVerify = EAPM_CFG(OTEL_EXPORTER_OTLP_LOGS_INSECURE);
        sslOptions.caInfo = EAPM_CFG(OTEL_EXPORTER_OTLP_LOGS_CERTIFICATE);
        sslOptions.cert = EAPM_CFG(OTEL_EXPORTER_OTLP_LOGS_CLIENT_CERTIFICATE);
        sslOptions.certKey = EAPM_CFG(OTEL_EXPORTER_OTLP_LOGS_CLIENT_KEY);
        sslOptions.certKeyPassword = EAPM_CFG(OTEL_EXPORTER_OTLP_LOGS_CLIENT_KEYPASS);

        return sslOptions;
    }

    opentelemetry::php::transport::HttpEndpointSSLOptions sslOptions;
    sslOptions.insecureSkipVerify = EAPM_CFG(OTEL_EXPORTER_OTLP_INSECURE);
    sslOptions.caInfo = EAPM_CFG(OTEL_EXPORTER_OTLP_CERTIFICATE);
    sslOptions.cert = EAPM_CFG(OTEL_EXPORTER_OTLP_CLIENT_CERTIFICATE);
    sslOptions.certKey = EAPM_CFG(OTEL_EXPORTER_OTLP_CLIENT_KEY);
    sslOptions.certKeyPassword = EAPM_CFG(OTEL_EXPORTER_OTLP_CLIENT_KEYPASS);

    return sslOptions;
}

PHP_FUNCTION(initialize) {
    zend_string *endpoint;
    zend_string *contentType;
    zval *headers;

    double timeout = 0.0; // s
    long retryDelay = 0;  // ms
    long maxRetries = 0;

    ZEND_PARSE_PARAMETERS_START(6, 6)
    Z_PARAM_STR(endpoint)
    Z_PARAM_STR(contentType)
    Z_PARAM_ARRAY(headers)
    Z_PARAM_DOUBLE(timeout)
    Z_PARAM_LONG(retryDelay)
    Z_PARAM_LONG(maxRetries)
    ZEND_PARSE_PARAMETERS_END();

    HashTable *ht = Z_ARRVAL_P(headers);

    zval *value = nullptr;
    zend_string *arrkey = nullptr;

    std::vector<std::pair<std::string_view, std::string_view>> endpointHeaders;

    ZEND_HASH_FOREACH_STR_KEY_VAL(ht, arrkey, value) {
        if (value && Z_TYPE_P(value) == IS_STRING) {
            endpointHeaders.emplace_back(std::make_pair(std::string_view(ZSTR_VAL(arrkey), ZSTR_LEN(arrkey)), std::string_view(Z_STRVAL_P(value), Z_STRLEN_P(value))));
        }
    }
    ZEND_HASH_FOREACH_END();

    opentelemetry::php::transport::HttpEndpointSSLOptions sslOptions = getSSLOptionsForSignalsEndpoint(std::string_view(ZSTR_VAL(endpoint), ZSTR_LEN(endpoint)));
    OTEL_GL(coordinatorProcess_)->getCoordinatorSender().initializeConnection(std::string(ZSTR_VAL(endpoint), ZSTR_LEN(endpoint)), ZSTR_HASH(endpoint), std::string(ZSTR_VAL(contentType), ZSTR_LEN(contentType)), endpointHeaders, std::chrono::duration_cast<std::chrono::milliseconds>(std::chrono::duration<double>(timeout)), static_cast<std::size_t>(maxRetries), std::chrono::milliseconds(retryDelay), sslOptions);
}

ZEND_BEGIN_ARG_INFO_EX(ArgInfoSend, 0, 0, 2)
ZEND_ARG_TYPE_INFO(0, endpoint, IS_STRING, 1)
ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 1)
ZEND_END_ARG_INFO()

PHP_FUNCTION(enqueue) {
    zend_string *payload = nullptr;
    zend_string *endpoint = nullptr;
    ZEND_PARSE_PARAMETERS_START(2, 2)
    Z_PARAM_STR(endpoint)
    Z_PARAM_STR(payload)
    ZEND_PARSE_PARAMETERS_END();

    OTEL_GL(coordinatorProcess_)->getCoordinatorSender().enqueue(ZSTR_HASH(endpoint), std::span<std::byte>(reinterpret_cast<std::byte *>(ZSTR_VAL(payload)), ZSTR_LEN(payload)));
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(set_object_property_value_arginfo, 0, 3, _IS_BOOL, 0)
ZEND_ARG_TYPE_INFO(0, object, IS_OBJECT, 0)
ZEND_ARG_TYPE_INFO(0, property_name, IS_STRING, 0)
ZEND_ARG_TYPE_INFO(0, value, IS_MIXED, 0)
ZEND_END_ARG_INFO()

PHP_FUNCTION(force_set_object_property_value) {
    zend_object *object = nullptr;
    zend_string *property_name = nullptr;
    zval *value = nullptr;

    ZEND_PARSE_PARAMETERS_START(3, 3)
    Z_PARAM_OBJ(object)
    Z_PARAM_STR(property_name)
    Z_PARAM_ZVAL(value)
    ZEND_PARSE_PARAMETERS_END();

    RETURN_BOOL(opentelemetry::php::forceSetObjectPropertyValue(object, property_name, value));
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_convert_spans, 0, 1, IS_STRING, 0)
ZEND_ARG_TYPE_INFO(0, batch, IS_ITERABLE, 0)
ZEND_END_ARG_INFO()

PHP_FUNCTION(convert_spans) {
    zval *batch;

    ZEND_PARSE_PARAMETERS_START(1, 1)
    Z_PARAM_ZVAL(batch)
    ZEND_PARSE_PARAMETERS_END();

    try {
        opentelemetry::php::SpanConverter converter;
        auto res = converter.getStringSerialized(opentelemetry::php::AutoZval(batch));
        RETURN_STRINGL(res.c_str(), res.length());
    } catch (std::exception const &e) {
        ELOGF_WARNING(OTEL_GL(logger_).get(), OTLPEXPORT, "Failed to serialize spans batch: '%s'", e.what());
        zend_throw_exception_ex(NULL, 0, "Failed to serialize spans batch: '%s'", e.what());
        RETURN_THROWS();
    }
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_convert_logs, 0, 1, IS_STRING, 0)
ZEND_ARG_TYPE_INFO(0, batch, IS_ITERABLE, 0)
ZEND_END_ARG_INFO()

PHP_FUNCTION(convert_logs) {
    zval *batch;

    ZEND_PARSE_PARAMETERS_START(1, 1)
    Z_PARAM_ZVAL(batch)
    ZEND_PARSE_PARAMETERS_END();

    try {
        opentelemetry::php::LogsConverter converter;
        auto res = converter.getStringSerialized(opentelemetry::php::AutoZval(batch));
        RETURN_STRINGL(res.c_str(), res.length());
    } catch (std::exception const &e) {
        ELOGF_WARNING(OTEL_GL(logger_).get(), OTLPEXPORT, "Failed to serialize logs batch: '%s'", e.what());
        zend_throw_exception_ex(NULL, 0, "Failed to serialize logs batch: '%s'", e.what());
        RETURN_THROWS();
    }
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_convert_metrics, 0, 1, IS_STRING, 0)
ZEND_ARG_TYPE_INFO(0, batch, IS_ITERABLE, 0)
ZEND_END_ARG_INFO()

PHP_FUNCTION(convert_metrics) {
    zval *batch;

    ZEND_PARSE_PARAMETERS_START(1, 1)
    Z_PARAM_ZVAL(batch)
    ZEND_PARSE_PARAMETERS_END();

    try {
        opentelemetry::php::MetricConverter converter;
        auto res = converter.getStringSerialized(opentelemetry::php::AutoZval(batch));
        RETURN_STRINGL(res.c_str(), res.length());
    } catch (std::exception const &e) {
        ELOGF_WARNING(OTEL_GL(logger_).get(), OTLPEXPORT, "Failed to serialize metrics batch: '%s'", e.what());
        zend_throw_exception_ex(NULL, 0, "Failed to serialize metrics batch: '%s'", e.what());
        RETURN_THROWS();
    }
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_get_remote_configuration, 0, 0, IS_ARRAY | IS_STRING | IS_NULL, 0)
ZEND_ARG_TYPE_INFO(/* pass_by_ref: */ 0, fileName, IS_STRING, /* allow_null: */ 1)
ZEND_END_ARG_INFO()

PHP_FUNCTION(get_remote_configuration) {
    zend_string *fileName = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 1)
    Z_PARAM_OPTIONAL
    Z_PARAM_STR_OR_NULL(fileName)
    ZEND_PARSE_PARAMETERS_END();

    std::optional<std::string> fname;
    if (fileName != nullptr) {
        fname.emplace(ZSTR_VAL(fileName), ZSTR_LEN(fileName));
    }

    auto config = OTEL_G(globals)->config_->get().remoteConfigFiles;

    ELOG_DEBUG(OTEL_GL(logger_).get(), CONFIG, "get_remote_configuration snapshot revision: '{}', files count: '{}'", OTEL_G(globals)->config_->get().revision, config.size());

    if (fname.has_value()) {
        if (auto cfgFound = config.find(fname.value()); cfgFound != config.end()) {
            RETURN_STRINGL(cfgFound->second.c_str(), cfgFound->second.length());
        } else {
            RETURN_NULL();
        }
    }

    opentelemetry::php::AutoZval configFiles;
    configFiles.arrayInit();

    for (auto const &item : config) {
        configFiles.arrayAddAssocWithRef(item.first, opentelemetry::php::AutoZval(item.second));
    }

    RETURN_ZVAL(configFiles.get(), 1, 0);
}

// clang-format off
const zend_function_entry opentelemetry_distro_functions[] = {
    ZEND_NS_FE( "OpenTelemetry\\Distro", is_enabled, no_params_arginfo )

    ZEND_NS_FE( "OpenTelemetry\\Distro", get_config_option_by_name, get_config_option_by_name_arginfo)
    ZEND_NS_FE( "OpenTelemetry\\Distro", log_feature, log_feature_arginfo)
    ZEND_NS_FE( "OpenTelemetry\\Distro", hook, hook_arginfo)

    ZEND_NS_FE( "OpenTelemetry\\Distro\\HttpTransport", initialize, ArgInfoInitialize)
    ZEND_NS_FE( "OpenTelemetry\\Distro\\HttpTransport", enqueue, no_params_arginfo)
    ZEND_NS_FE( "OpenTelemetry\\Distro\\InferredSpans", force_set_object_property_value, set_object_property_value_arginfo)

    ZEND_NS_FE( "OpenTelemetry\\Distro\\OtlpExporters", convert_spans, arginfo_convert_spans)
    ZEND_NS_FE( "OpenTelemetry\\Distro\\OtlpExporters", convert_logs, arginfo_convert_logs)
    ZEND_NS_FE( "OpenTelemetry\\Distro\\OtlpExporters", convert_metrics, arginfo_convert_metrics)

    ZEND_NS_FALIAS( "OpenTelemetry\\Distro", get_remote_configuration, get_remote_configuration, arginfo_get_remote_configuration)

    PHP_FE_END
};
// clang-format on

} // namespace opentelemetry::php::module_functions
