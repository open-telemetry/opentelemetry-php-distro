#include "AgentGlobals.h"

#include "PhpBridgeInterface.h"
#include "SharedMemoryState.h"
#include "InferredSpans.h"
#include "PeriodicTaskExecutor.h"
#include "PeriodicTaskExecutor.h"
#include "RequestScope.h"
#include "LoggerInterface.h"
#include "LoggerSinkInterface.h"
#include "ConfigurationStorage.h"
#include "InstrumentedFunctionHooksStorage.h"
#include "CommonUtils.h"
#include "ResourceDetector.h"
#include "coordinator/CoordinatorProcess.h"
#include "coordinator/CoordinatorMessagesDispatcher.h"
#include "coordinator/CoordinatorConfigurationProvider.h"
#include "transport/HttpTransportAsync.h"
#include "transport/OpAmp.h"
#include "DependencyAutoLoaderGuard.h"
#include "LogFeature.h"
#include <signal.h>

namespace opentelemetry::php {
// clang-format off

AgentGlobals::AgentGlobals(std::shared_ptr<LoggerInterface> logger,
        std::shared_ptr<LoggerSinkInterface> logSinkStdErr,
        std::shared_ptr<LoggerSinkInterface> logSinkSysLog,
        std::shared_ptr<LoggerSinkFile> logSinkFile,
        std::shared_ptr<PhpBridgeInterface> bridge,
        std::shared_ptr<InstrumentedFunctionHooksStorageInterface> hooksStorage,
        std::shared_ptr<InferredSpans> inferredSpans,
        ConfigurationStorage::configUpdate_t updateConfigurationSnapshot) :
    config_(std::make_shared<opentelemetry::php::ConfigurationStorage>(std::move(updateConfigurationSnapshot))),
    logger_(std::move(logger)),
    logSinkStdErr_(std::move(logSinkStdErr)),
    logSinkSysLog_(std::move(logSinkSysLog)),
    logSinkFile_(std::move(logSinkFile)),
    bridge_(std::move(bridge)),
    dependencyAutoLoaderGuard_(std::make_shared<DependencyAutoLoaderGuard>(bridge_, logger_)),
    hooksStorage_(std::move(hooksStorage)),
    sapi_(std::make_shared<opentelemetry::php::PhpSapi>(bridge_->getPhpSapiName())),
    inferredSpans_(std::move(inferredSpans)),
    periodicTaskExecutor_(),
    httpTransportAsync_(std::make_shared<opentelemetry::php::transport::HttpTransportAsync<>>(logger_, config_)),
    resourceDetector_(std::make_shared<opentelemetry::php::ResourceDetector>(bridge_)),
    opAmp_(std::make_shared<opentelemetry::php::transport::OpAmp>(logger_, config_, httpTransportAsync_, resourceDetector_)),
    sharedMemory_(std::make_shared<opentelemetry::php::SharedMemoryState>()),
    requestScope_(std::make_shared<opentelemetry::php::RequestScope>(logger_, bridge_, sapi_, sharedMemory_, dependencyAutoLoaderGuard_, inferredSpans_, config_, [hs = hooksStorage_]() { hs->clear(); }, [this]() { return getPeriodicTaskExecutor();}, [this]() { return coordinatorConfigProvider_->triggerUpdateIfChanged(); })),
    messagesDispatcher_(std::make_shared<opentelemetry::php::coordinator::CoordinatorMessagesDispatcher>(logger_, httpTransportAsync_)),
    coordinatorConfigProvider_(std::make_shared<opentelemetry::php::coordinator::CoordinatorConfigurationProvider>(logger_, opAmp_)),
    coordinatorProcess_(std::make_shared<opentelemetry::php::coordinator::CoordinatorProcess>(logger_, messagesDispatcher_, coordinatorConfigProvider_))
    {
        config_->addConfigUpdateWatcher([logger = logger_, stderrsink = logSinkStdErr_, syslogsink = logSinkSysLog_, filesink = logSinkFile_](ConfigurationSnapshot const &cfg) {
            stderrsink->setLevel(cfg.log_level_stderr);
            syslogsink->setLevel(cfg.log_level_syslog);
            if (filesink) {
                if (cfg.log_file.empty()) {
                    filesink->setLevel(LogLevel::logLevel_off);
                } else {
                    filesink->setLevel(cfg.log_level_file);
                    filesink->reopen(utils::getParameterizedString(cfg.log_file));
                }
            }

            logger->setLogFeatures(utils::parseLogFeatures(logger, cfg.log_features));
        });
    }


AgentGlobals::~AgentGlobals() {
    ELOG_DEBUG(logger_, MODULE, "AgentGlobals shutdown");
    config_->removeAllConfigUpdateWatchers();
    opAmp_->removeAllConfigUpdateWatchers();
}

std::shared_ptr<PeriodicTaskExecutor> AgentGlobals::getPeriodicTaskExecutor() {
    if (periodicTaskExecutor_) {
        return periodicTaskExecutor_;
    }

    periodicTaskExecutor_ = std::make_shared<opentelemetry::php::PeriodicTaskExecutor>(
            std::vector<opentelemetry::php::PeriodicTaskExecutor::task_t>{
            [inferredSpans = inferredSpans_](opentelemetry::php::PeriodicTaskExecutor::time_point_t now) { inferredSpans->tryRequestInterrupt(now); }
            },
            []() {
                // block signals for this thread to be handled by main Apache/PHP thread
                // list of signals from Apaches mpm handlers
                opentelemetry::utils::blockSignal(SIGTERM);
                opentelemetry::utils::blockSignal(SIGHUP);
                opentelemetry::utils::blockSignal(SIGINT);
                opentelemetry::utils::blockSignal(SIGWINCH);
                opentelemetry::utils::blockSignal(SIGUSR1);
                opentelemetry::utils::blockSignal(SIGPROF); // php timeout signal
            }
        );
        return periodicTaskExecutor_;
    }


}

