#include "CoordinatorProcess.h"
#include "CoordinatorMessagesDispatcher.h"
#include "WorkerRegistry.h"

#include "ConfigurationManager.h"
#include "ConfigurationStorage.h"
#include "config/PrioritizedOptionValueProviderChain.h"

#include "ResourceDetector.h"
#include "coordinator/WorkerRegistry.h"
#include "transport/OpAmp.h"
#include "transport/HttpTransportAsync.h"
#include "VendorCustomizationsInterface.h"
#include <exception>

namespace opentelemetry::php::coordinator {

// clang-format off
CoordinatorProcess::CoordinatorProcess(
        pid_t parentProcessId,
        pid_t processId,
        std::shared_ptr<LoggerInterface> logger,
        std::function<void(opentelemetry::php::ConfigurationSnapshot const &)> loggerConfigUpdateFunc,
        std::shared_ptr<VendorCustomizationsInterface> vendorCustomizations_,
        std::shared_ptr<config::OptionValueProviderInterface> defaultOptionValueProvider,
        std::shared_ptr<CoordinatorSharedDataQueue> sharedDataQueue,
        std::shared_ptr<CoordinatorConfigurationProvider> configProvider,
        std::shared_ptr<opentelemetry::php::ResourceDetector> resourceDetector) :
            processId_(processId),
            parentProcessId_(parentProcessId),
            logger_(std::move(logger)),
            configManager_(std::make_shared<ConfigurationManager>(logger_, std::make_shared<config::PrioritizedOptionValueProviderChain>(std::initializer_list<std::pair<int, std::shared_ptr<config::OptionValueProviderInterface>>>{
                {0, defaultOptionValueProvider}, vendorCustomizations_ ? vendorCustomizations_->getOptionValueProvider() : std::pair<int, std::shared_ptr<opentelemetry::php::config::OptionValueProviderInterface>>{0, nullptr} // create dummy pair if vendor customizations or its option provider is not available, to avoid checks in PrioritizedOptionValueProviderChain
            }))),
            config_(std::make_shared<opentelemetry::php::ConfigurationStorage>([this](ConfigurationSnapshot &cfg) { return configManager_->updateIfChanged(cfg); })),
            workerRegistry_(std::make_shared<WorkerRegistry>(logger_)),
            httpTransport_(std::make_shared<transport::HttpTransportAsync<>>(logger_, config_)),
            opAmp_(std::make_shared<opentelemetry::php::transport::OpAmp>(logger_, config_, httpTransport_, std::move(resourceDetector))),
            messagesDispatcher_(std::make_shared<CoordinatorMessagesDispatcher>(logger_, httpTransport_, workerRegistry_)),
            processor_{logger_, sharedDataQueue, [this](const std::span<const std::byte> data) { messagesDispatcher_->processRecievedMessage(data); }},
            configProvider_(std::move(configProvider)) {

        opAmp_->addConfigUpdateWatcher([this](opentelemetry::php::transport::OpAmp::configFiles_t const &configFiles) {
            configProvider_->storeConfigFiles(configFiles);
        });
        config_->addConfigUpdateWatcher(loggerConfigUpdateFunc);

        configManager_->update();
        config_->update();
    }
// clang-format on

CoordinatorProcess::~CoordinatorProcess() {
    ELOG_DEBUG(logger_, COORDINATOR, "CoordinatorProcess shutting down");
    opAmp_->removeAllConfigUpdateWatchers();
    config_->removeAllConfigUpdateWatchers();
}

void CoordinatorProcess::prefork() {
    periodicTaskExecutor_->prefork();
    opAmp_->prefork();
    static_cast<transport::HttpTransportAsync<> *>(httpTransport_.get())->prefork();
}

void CoordinatorProcess::postfork([[maybe_unused]] bool child) {
    periodicTaskExecutor_->postfork(child);
    opAmp_->postfork(child);
    static_cast<transport::HttpTransportAsync<> *>(httpTransport_.get())->postfork(child);
}

void CoordinatorProcess::coordinatorLoop() {
    opAmp_->startCommunication();
    setupPeriodicTasks();
    periodicTaskExecutor_->resumePeriodicTasks();

    char buffer[CoordinatorSharedDataQueue::maxMqPayloadSize];
    while (working_.load()) {
        try {
            processor_.tryReceiveMessage(buffer, CoordinatorSharedDataQueue::maxMqPayloadSize);
        } catch (std::exception const &ex) {
            ELOG_WARNING(logger_, COORDINATOR, "CoordinatorProcess: exception in coordinator loop: '{}'", ex.what());
        }
    }
    ELOG_DEBUG(logger_, COORDINATOR, "CoordinatorProcess coordinator loop exiting");
}

void CoordinatorProcess::setupPeriodicTasks() {
    periodicTaskExecutor_ = std::make_unique<PeriodicTaskExecutor>(std::vector<PeriodicTaskExecutor::task_t>{[this, registry = workerRegistry_](PeriodicTaskExecutor::time_point_t now) {
        // Check parent process is alive
        if (getppid() != parentProcessId_) {
            ELOG_DEBUG(logger_, COORDINATOR, "CoordinatorProcess: parent process has exited. Checking if workers are still alive.");
            registry->verifyWorkersAlive();
            if (registry->getWorkerCount() > 0) {
                ELOG_DEBUG(logger_, COORDINATOR, "CoordinatorProcess: there are still {} alive workers, continuing work", registry->getWorkerCount());
            } else {
                working_ = false;
            }
        }

        static auto lastCleanupTime = std::chrono::steady_clock::now();
        if (now - lastCleanupTime >= cleanUpLostMessagesInterval) {
            processor_.cleanupAbandonedMessages(now, std::chrono::seconds(10));
            lastCleanupTime = now;

            ELOG_DEBUG(logger_, COORDINATOR, "CoordinatorProcess: there are still {} alive workers, continuing work", registry->getWorkerCount());
        }
    }});
    periodicTaskExecutor_->setInterval(std::chrono::milliseconds(100));
}

} // namespace opentelemetry::php::coordinator
