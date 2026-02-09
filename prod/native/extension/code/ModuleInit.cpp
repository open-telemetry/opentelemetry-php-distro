#if defined(PHP_WIN32) && ! defined(CURL_STATICLIB)
#   define CURL_STATICLIB
#endif

#include "otel_distro_version.h"
#include "CommonUtils.h"
#include "ConfigurationManager.h"
#include "ConfigurationSnapshot.h"
#include "ForkHandler.h"
#include "Hooking.h"
#include "InternalFunctionInstrumentation.h"
#include "ModuleIniEntries.h"
#include "ModuleGlobals.h"
#include "SigSegvHandler.h"
#include "os/OsUtils.h"
#include "coordinator/CoordinatorProcess.h"
#include "VendorCustomizationsInterface.h"

#include <curl/curl.h>
#include <inttypes.h> // PRIu64
#include <stdbool.h>
#include <php.h>
#include <zend_compile.h>
#include <zend_exceptions.h>
#include <zend_builtin_functions.h>
#include <Zend/zend_observer.h>
#include "php_error.h"

namespace opentelemetry::php {

void logStartupPreamble(opentelemetry::php::LoggerInterface *logger) {
    constexpr LogLevel level = LogLevel::logLevel_debug;
    constexpr int colWidth = 40;

    using namespace std::literals;

    if (OTEL_G(globals)->vendorCustomizations_) {
        ELOG_NF(logger, level, "{} version: {}", OTEL_G(globals)->vendorCustomizations_->getDistributionName(), OTEL_G(globals)->vendorCustomizations_->getDistributionVersion());
        ELOG_NF(logger, level, "{:<{}}{}", "OpenTelemetry distro base version:", colWidth, OTEL_DISTRO_VERSION);
    } else {
        ELOG_NF(logger, level, OTEL_DISTRO_PRODUCT_NAME);
        ELOGF_NF(logger, level, "%*s%s", -colWidth, "Native part version:", OTEL_DISTRO_VERSION);
    }

    ELOGF_NF(logger, level, "%*s%s", -colWidth, "Process command line:", opentelemetry::utils::sanitizeKeyValueString("OTEL_EXPORTER_OTLP_HEADERS", opentelemetry::osutils::getCommandLine()).c_str());
    ELOGF_NF(logger, level, "%*s%s", -colWidth, "Process environment:", opentelemetry::utils::sanitizeKeyValueString("OTEL_EXPORTER_OTLP_HEADERS", opentelemetry::osutils::getProcessEnvironment()).c_str());
}

void moduleInit(int moduleType, int moduleNumber) {
    auto const &sapi = *OTEL_G(globals)->sapi_;
    auto globals = OTEL_G(globals);

    opentelemetry::php::registerIniEntries(OTEL_GL(logger_).get(), moduleNumber);
    globals->configManager_->update();
    globals->config_->update();

    ELOGF_DEBUG(globals->logger_, MODULE, "%s entered: moduleType: %d, moduleNumber: %d, parent PID: %d, SAPI: %s (%d) is %s", __FUNCTION__, moduleType, moduleNumber, static_cast<int>(opentelemetry::osutils::getParentProcessId()), sapi.getName().data(), static_cast<uint8_t>(sapi.getType()), sapi.isSupported() ? "supported" : "unsupported");
    if (!sapi.isSupported()) {
        return;
    }

    registerSigSegvHandler(globals->logger_.get());

    logStartupPreamble(globals->logger_.get());

    if (!EAPM_CFG(enabled)) {
        ELOGF_INFO(globals->logger_, MODULE, "Extension is disabled");
        return;
    }

    if (EAPM_CFG(bootstrap_php_part_file).empty()) {
        ELOGF_WARNING(globals->logger_, MODULE, "bootstrap_php_part_file configuration option is not set - extension will be disabled");
        return;
    }

    if (globals->coordinatorProcess_->start()) {
        delete globals;
        std::exit(0);
    }

    // add config update watcher in worker process
    globals->coordinatorConfigProvider_->addConfigUpdateWatcher([globals](opentelemetry::php::coordinator::CoordinatorConfigurationProvider::configFiles_t const &cfgFiles) {
        ELOG_DEBUG(globals->logger_, COORDINATOR, "Received config update with {} files. Updating dynamic config and global config storage", cfgFiles.size());
        globals->configManager_->update(cfgFiles);
    });

    globals->coordinatorConfigProvider_->triggerUpdateIfChanged();

    ELOGF_DEBUG(globals->logger_, MODULE, "MINIT Replacing hooks");
    opentelemetry::php::Hooking::getInstance().fetchOriginalHooks();
    opentelemetry::php::Hooking::getInstance().replaceHooks(globals->config_->get().inferred_spans_enabled, globals->config_->get().dependency_autoloader_guard_enabled);

    zend_observer_activate();
    zend_observer_fcall_register(opentelemetry::php::registerObserverHandlers);

    if (php_check_open_basedir_ex(OTEL_GL(config_)->get(&opentelemetry::php::ConfigurationSnapshot::bootstrap_php_part_file).c_str(), false) != 0) {
        ELOGF_WARNING(globals->logger_, MODULE, "OpenTelemetry PHP distro bootstrap file (%s) is located outside of paths allowed by open_basedir ini setting.", OTEL_GL(config_)->get(&opentelemetry::php::ConfigurationSnapshot::bootstrap_php_part_file).c_str());
    }

    // Registering fork handlers in module init to ensure that we will handle all forks properly - even those triggered before request start (e.g. in MINIT of other extensions) or when fpm/apache spawns worker processes. We cannot be sure that some of the classes implementing ForkableInterface will not start thread before request start.
    registerCallbacksToHandleFork();
}

void moduleShutdown( int moduleType, int moduleNumber ) {
    ELOG_DEBUG(OTEL_G(globals)->logger_, MODULE, "moduleShutdown");

    if (!OTEL_G(globals)->sapi_->isSupported()) {
        return;
    }

    if (!EAPM_CFG(enabled)) {
        return;
    }

    if (EAPM_CFG(bootstrap_php_part_file).empty()) {
        return;
    }

    opentelemetry::php::Hooking::getInstance().restoreOriginalHooks();

    // curl_global_cleanup();

    zend_unregister_ini_entries(moduleNumber);

    unregisterSigSegvHandler();
}

} // namespace opentelemetry::php