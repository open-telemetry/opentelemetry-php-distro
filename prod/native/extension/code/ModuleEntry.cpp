#ifdef HAVE_CONFIG_H
# include "config.h"
#endif
#include "otel_distro_version.h"

#include "ModuleGlobals.h"
// external libraries
#include <main/php.h>
#include <Zend/zend_types.h>

#include "ModuleInit.h"

#include "AutoZval.h"
#include "config/OptionValueProvider.h"
#include "coordinator/CoordinatorSharedDataQueue.h"
#include "coordinator/CoordinatorProcess.h"
#include "CommonUtils.h"
#include "os/OsUtils.h"
#include "InferredSpans.h"
#include "InternalFunctionInstrumentation.h"
#include "Logger.h"
#include "ModuleInfo.h"
#include "ModuleFunctions.h"
#include "PhpBridge.h"
#include "PhpBridgeInterface.h"
#include "RequestScope.h"
#include "ResourceDetector.h"
#include "SigSegvHandler.h"
#include "VendorCustomizationsInterface.h"

ZEND_DECLARE_MODULE_GLOBALS( opentelemetry_distro )

#ifndef ZEND_PARSE_PARAMETERS_NONE
#   define ZEND_PARSE_PARAMETERS_NONE() \
        ZEND_PARSE_PARAMETERS_START(0, 0) \
        ZEND_PARSE_PARAMETERS_END()
#endif

PHP_RINIT_FUNCTION(opentelemetry_distro) {
    OTEL_G(globals)->requestScope_->onRequestInit();
    return SUCCESS;
}

PHP_RSHUTDOWN_FUNCTION(opentelemetry_distro) {
    OTEL_G(globals)->requestScope_->onRequestShutdown();
    return SUCCESS;
}

ZEND_RESULT_CODE  opentelemetry_distro_request_postdeactivate(void) {
    OTEL_G(globals)->requestScope_->onRequestPostDeactivate();
    return ZEND_RESULT_CODE::SUCCESS;
}

PHP_MINFO_FUNCTION(opentelemetry_distro) {
    opentelemetry::php::printPhpInfo(zend_module);
}

namespace opentelemetry::php {

void forkCoordinatorProcess(std::shared_ptr<opentelemetry::php::LoggerInterface> logger, std::function<void(opentelemetry::php::ConfigurationSnapshot const &)> loggerConfigUpdateFunc, std::shared_ptr<coordinator::CoordinatorSharedDataQueue> shareDataQueue, std::shared_ptr<opentelemetry::php::config::OptionValueProvider> optionValueProvider, std::shared_ptr<coordinator::CoordinatorConfigurationProvider> coordinatorConfigProvider, std::shared_ptr<PhpBridgeInterface> phpBridge) {
    auto parentProcessId = getpid();
    auto processId = fork();
    if (processId < 0) {
        if (logger) {
            ELOG_DEBUG(logger, COORDINATOR, "CoordinatorProcess: fork() failed: {} ({})", strerror(errno), errno);
        }
    } else if (processId == 0) {
        ELOG_DEBUG(logger, COORDINATOR, "CoordinatorProcess starting collector process");
        registerSigSegvHandler(logger.get());

        auto resourceDetector = std::make_shared<opentelemetry::php::ResourceDetector>(std::move(phpBridge));

        opentelemetry::php::coordinator::CoordinatorProcess(parentProcessId, processId, logger, loggerConfigUpdateFunc, ::getVendorCustomizations ? ::getVendorCustomizations() : nullptr, optionValueProvider, std::move(shareDataQueue), coordinatorConfigProvider, std::move(resourceDetector)).start();
        ELOG_DEBUG(logger, COORDINATOR, "CoordinatorProcess: collector process is going to finish");
        std::exit(0);
    } else {
        if (logger) {
            ELOG_DEBUG(logger, COORDINATOR, "CoordinatorProcess parent process continues initialization");
        }
    }
}

} // namespace opentelemetry::php

static PHP_GINIT_FUNCTION(opentelemetry_distro) {
    //TODO for ZTS logger must be initialized in MINIT! (share fd between threads) - different lifecycle

    auto logSinkStdErr = std::make_shared<opentelemetry::php::LoggerSinkStdErr>();
    auto logSinkSysLog = std::make_shared<opentelemetry::php::LoggerSinkSysLog>();
    auto logSinkFile = std::make_shared<opentelemetry::php::LoggerSinkFile>();

    auto logger = std::make_shared<opentelemetry::php::Logger>(std::vector<std::shared_ptr<opentelemetry::php::LoggerSinkInterface>>{logSinkStdErr, logSinkSysLog, logSinkFile});

    auto loggerConfigUpdateFunc = [logger = logger, stderrsink = logSinkStdErr, syslogsink = logSinkSysLog, filesink = logSinkFile](opentelemetry::php::ConfigurationSnapshot const &cfg) {
        stderrsink->setLevel(cfg.log_level_stderr);
        syslogsink->setLevel(cfg.log_level_syslog);
        if (filesink) {
            if (cfg.log_file.empty()) {
                filesink->setLevel(LogLevel::logLevel_off);
            } else {
                filesink->setLevel(cfg.log_level_file);
                filesink->reopen(opentelemetry::utils::getParameterizedString(cfg.log_file));
            }
        }

        logger->setLogFeatures(opentelemetry::utils::parseLogFeatures(logger, cfg.log_features));
    };

    ELOGF_DEBUG(logger, MODULE, "%s: GINIT called; parent PID: %d", __FUNCTION__, static_cast<int>(opentelemetry::osutils::getParentProcessId()));
    opentelemetry_distro_globals->globals = nullptr;

    auto shareDataQueue = std::make_shared<opentelemetry::php::coordinator::CoordinatorSharedDataQueue>(logger);
    auto coordinatorConfigProvider = std::make_shared<opentelemetry::php::coordinator::CoordinatorConfigurationProvider>(logger);

    auto phpBridge = std::make_shared<opentelemetry::php::PhpBridge>(logger);

    auto optionValueProvider = std::make_shared<opentelemetry::php::config::OptionValueProvider>([](std::string_view iniName) -> std::optional<std::string> {
        auto val = cfg_get_entry(iniName.data(), iniName.length());

        opentelemetry::php::AutoZval autoZval(val);
        auto optStringView = autoZval.getOptStringView();
        if (!optStringView.has_value()) {
            return std::nullopt;
        }

        return std::string(*optStringView);
    });

    forkCoordinatorProcess(logger, loggerConfigUpdateFunc, shareDataQueue, optionValueProvider, coordinatorConfigProvider, phpBridge);

    auto hooksStorage = std::make_shared<opentelemetry::php::InstrumentedFunctionHooksStorage_t>();

    auto inferredSpans = std::make_shared<opentelemetry::php::InferredSpans>([interruptFlag = reinterpret_cast<void *>(&EG(vm_interrupt))]() {
#if PHP_VERSION_ID >= 80200
        zend_atomic_bool_store_ex(reinterpret_cast<zend_atomic_bool *>(interruptFlag), true);
#else
        *static_cast<zend_bool *>(interruptFlag) = 1;
#endif
    }, [phpBridge](opentelemetry::php::InferredSpans::time_point_t requestTime, opentelemetry::php::InferredSpans::time_point_t now) {
        phpBridge->callInferredSpans(now - requestTime);
    });

    try {
        opentelemetry_distro_globals->globals = new opentelemetry::php::AgentGlobals(logger, loggerConfigUpdateFunc, std::move(phpBridge), std::move(hooksStorage), std::move(inferredSpans), std::move(shareDataQueue), std::move(coordinatorConfigProvider), std::move(optionValueProvider));
    } catch (std::exception const &e) {
        ELOGF_CRITICAL(logger, MODULE, "Unable to allocate AgentGlobals. '%s'", e.what());
    }
}

PHP_GSHUTDOWN_FUNCTION(opentelemetry_distro) {
    if (opentelemetry_distro_globals->globals) {
        ELOGF_DEBUG(opentelemetry_distro_globals->globals->logger_, MODULE, "%s: GSHUTDOWN called; parent PID: %d", __FUNCTION__, static_cast<int>(opentelemetry::osutils::getParentProcessId()));
        delete opentelemetry_distro_globals->globals;
    }
}

zend_module_entry opentelemetry_distro_fake = {STANDARD_MODULE_HEADER,
                                       "opentelemetry", /* Extension name */
                                       nullptr,         /* zend_function_entry */
                                       nullptr,         /* PHP_MINIT - Module initialization */
                                       nullptr,         /* PHP_MSHUTDOWN - Module shutdown */
                                       nullptr,         /* PHP_RINIT - Request initialization */
                                       nullptr,         /* PHP_RSHUTDOWN - Request shutdown */
                                       nullptr,         /* PHP_MINFO - Module info */
                                       "2.0",           /* Version */
                                       0,               /* globals size */
                                       nullptr,         /* PHP_MODULE_GLOBALS */
                                       nullptr,         /* PHP_GINIT */
                                       nullptr,         /* PHP_GSHUTDOWN */
                                       nullptr,         /* post deactivate */
                                       STANDARD_MODULE_PROPERTIES_EX};

PHP_MINIT_FUNCTION(opentelemetry_distro) {
    REGISTER_LONG_CONSTANT("OTEL_PHP_LOG_LEVEL_OFF", logLevel_off, CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("OTEL_PHP_LOG_LEVEL_CRITICAL", logLevel_critical, CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("OTEL_PHP_LOG_LEVEL_ERROR", logLevel_error, CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("OTEL_PHP_LOG_LEVEL_WARNING", logLevel_warning, CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("OTEL_PHP_LOG_LEVEL_INFO", logLevel_info, CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("OTEL_PHP_LOG_LEVEL_DEBUG", logLevel_debug, CONST_CS | CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("OTEL_PHP_LOG_LEVEL_TRACE", logLevel_trace, CONST_CS | CONST_PERSISTENT);

    opentelemetry::php::moduleInit(type, module_number);

    if (!zend_register_internal_module(&opentelemetry_distro_fake)) {
        ELOGF_WARNING(OTEL_G(globals)->logger_, MODULE, "Unable to create artificial OpenTelemetry extension. There might be stability issues.");
    }

    return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(opentelemetry_distro) {
    opentelemetry::php::moduleShutdown(type, module_number);
    return SUCCESS;
}

zend_module_entry opentelemetry_distro_module_entry = {STANDARD_MODULE_HEADER,
                                                       "opentelemetry_distro",                                               /* Extension name */
                                                       opentelemetry::php::module_functions::opentelemetry_distro_functions, /* zend_function_entry */
                                                       PHP_MINIT(opentelemetry_distro),
                                                       PHP_MSHUTDOWN(opentelemetry_distro),
                                                       PHP_RINIT(opentelemetry_distro),
                                                       PHP_RSHUTDOWN(opentelemetry_distro),
                                                       PHP_MINFO(opentelemetry_distro),
                                                       OTEL_DISTRO_VERSION,
                                                       PHP_MODULE_GLOBALS(opentelemetry_distro),
                                                       PHP_GINIT(opentelemetry_distro),
                                                       PHP_GSHUTDOWN(opentelemetry_distro),
                                                       opentelemetry_distro_request_postdeactivate,
                                                       STANDARD_MODULE_PROPERTIES_EX};

#   ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
#   endif
extern "C" ZEND_GET_MODULE(opentelemetry_distro)