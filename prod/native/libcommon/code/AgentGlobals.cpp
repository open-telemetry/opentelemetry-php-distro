#include "AgentGlobals.h"

#include "ConfigurationManager.h"
#include "PhpBridgeInterface.h"
#include "SharedMemoryState.h"
#include "ForkableRegistry.h"
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
#include "config/OptionValueProviderInterface.h"
#include "config/PrioritizedOptionValueProviderChain.h"
#include "coordinator/CoordinatorProcess.h"
#include "coordinator/CoordinatorMessagesDispatcher.h"
#include "coordinator/CoordinatorConfigurationProvider.h"
#include "coordinator/CoordinatorSharedDataQueue.h"
#include "coordinator/CoordinatorTelemetrySignalsSender.h"
#include "coordinator/WorkerRegistrar.h"
#include "transport/HttpTransportAsync.h"
#include "transport/OpAmp.h"
#include "DependencyAutoLoaderGuard.h"
#include "VendorCustomizationsInterface.h"
#include <memory>
#include <signal.h>

namespace opentelemetry::php {
// clang-format off

AgentGlobals::AgentGlobals(std::shared_ptr<LoggerInterface> logger,
        std::function<void(opentelemetry::php::ConfigurationSnapshot const &)> loggerConfigUpdateFunc,
        std::shared_ptr<PhpBridgeInterface> bridge,
        std::shared_ptr<InstrumentedFunctionHooksStorageInterface> hooksStorage,
        std::shared_ptr<InferredSpans> inferredSpans,
        std::shared_ptr<coordinator::CoordinatorSharedDataQueue> sharedDataQueue,
        std::shared_ptr<coordinator::CoordinatorConfigurationProvider> sharedCoordinatorConfigProvider,
        std::shared_ptr<config::OptionValueProviderInterface> defaultOptionValueProvider) :
    vendorCustomizations_(::getVendorCustomizations ? ::getVendorCustomizations() : nullptr),
    forkableRegistry_(std::make_shared<ForkableRegistry>()),
    configManager_(std::make_shared<ConfigurationManager>(logger,
        std::make_shared<config::PrioritizedOptionValueProviderChain>(std::initializer_list<std::pair<int, std::shared_ptr<config::OptionValueProviderInterface>>>{
            {0, defaultOptionValueProvider},
            vendorCustomizations_ ? vendorCustomizations_->getOptionValueProvider() : std::pair<int, std::shared_ptr<opentelemetry::php::config::OptionValueProviderInterface>>{0, nullptr} // create dummy pair if vendor customizations or its option provider is not available, to avoid checks in PrioritizedOptionValueProviderChain
    }))),
    config_(std::make_shared<opentelemetry::php::ConfigurationStorage>([this](ConfigurationSnapshot &cfg) { return configManager_->updateIfChanged(cfg); })),
    logger_(std::move(logger)),
    bridge_(std::move(bridge)),
    sharedMemory_(std::make_shared<opentelemetry::php::SharedMemoryState>()),
    coordinatorConfigProvider_(std::move(sharedCoordinatorConfigProvider)),
    processor_(std::make_shared<opentelemetry::php::coordinator::ChunkedMessageProcessor>(logger_, sharedDataQueue, [](const std::span<const std::byte> data) { })),
    httpTransportAsync_(std::make_shared<opentelemetry::php::coordinator::CoordinatorTelemetrySignalsSender>(logger_, [this](std::string const &payload) { return processor_->sendPayload(payload); })),
    dependencyAutoLoaderGuard_(std::make_shared<DependencyAutoLoaderGuard>(bridge_, logger_)),
    hooksStorage_(std::move(hooksStorage)),
    sapi_(std::make_shared<opentelemetry::php::PhpSapi>(bridge_->getPhpSapiName())),
    inferredSpans_(std::move(inferredSpans)),
    periodicTaskExecutor_(),
    requestScope_(std::make_shared<opentelemetry::php::RequestScope>(logger_, bridge_, sapi_, sharedMemory_, dependencyAutoLoaderGuard_, inferredSpans_, config_, [hs = hooksStorage_]() { hs->clear(); }, [this]() { return getPeriodicTaskExecutor();}, [this]() { return coordinatorConfigProvider_->triggerUpdateIfChanged(); })),
    workerRegistrar_(std::make_shared<opentelemetry::php::coordinator::WorkerRegistrar>(logger_, [this](const std::string &payload) { return processor_->sendPayload(payload); }))
    {
        forkableRegistry_->registerForkable(workerRegistrar_);

        config_->addConfigUpdateWatcher(loggerConfigUpdateFunc);
}


AgentGlobals::~AgentGlobals() {
    ELOG_DEBUG(logger_, MODULE, "AgentGlobals shutdown");
    config_->removeAllConfigUpdateWatchers();
    forkableRegistry_->clear();
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
    forkableRegistry_->registerForkable(periodicTaskExecutor_);

    return periodicTaskExecutor_;
}


}

