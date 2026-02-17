#pragma once

#include "ConfigurationSnapshot.h"
#include "LoggerInterface.h"
#include "ForkableInterface.h"
#include "PeriodicTaskExecutor.h"
#include "ChunkedMessageProcessor.h"
#include "CoordinatorConfigurationProvider.h"
#include "VendorCustomizationsInterface.h"
#include "transport/HttpTransportAsyncInterface.h"

#include <boost/noncopyable.hpp>

#include <atomic>
#include <chrono>
#include <memory>

namespace opentelemetry::php {
class ConfigurationManager;
class ConfigurationStorage;
struct ConfigurationSnapshot;
class ResourceDetector;
namespace config {
class OptionValueProviderInterface;
}
namespace coordinator {
class CoordinatorMessagesDispatcher;
class WorkerRegistry;
} // namespace coordinator
namespace transport {
class OpAmp;
} // namespace transport

} // namespace opentelemetry::php

namespace opentelemetry::php::coordinator {

namespace {
constexpr static std::chrono::minutes cleanUpLostMessagesInterval(1);
} // namespace

class CoordinatorProcess : public boost::noncopyable, public ForkableInterface {

public:
    // clang-format off
    CoordinatorProcess(pid_t parentProcessId,
                        pid_t processId,
                        std::shared_ptr<LoggerInterface> logger,
                        std::function<void(opentelemetry::php::ConfigurationSnapshot const &)> loggerConfigUpdateFunc,
                        std::shared_ptr<VendorCustomizationsInterface> vendorCustomizations_,
                        std::shared_ptr<config::OptionValueProviderInterface> defaultOptionValueProvider,
                        std::shared_ptr<CoordinatorSharedDataQueue> sharedDataQueue,
                        std::shared_ptr<CoordinatorConfigurationProvider> configProvider,
                        std::shared_ptr<opentelemetry::php::ResourceDetector> resourceDetector);
    // clang-format on
    ~CoordinatorProcess();

    void prefork() final;
    void postfork([[maybe_unused]] bool child) final;

    void start() {
        coordinatorLoop();
    }

private:
    void coordinatorLoop();
    void setupPeriodicTasks();

private:
    std::atomic_bool working_ = true;
    int processId_;
    int parentProcessId_;
    std::shared_ptr<LoggerInterface> logger_;

    std::shared_ptr<ConfigurationManager> configManager_;
    std::shared_ptr<ConfigurationStorage> config_;
    std::shared_ptr<WorkerRegistry> workerRegistry_;

    std::shared_ptr<transport::HttpTransportAsyncInterface> httpTransport_;
    std::shared_ptr<transport::OpAmp> opAmp_;

    std::shared_ptr<CoordinatorMessagesDispatcher> messagesDispatcher_;
    ChunkedMessageProcessor processor_;

    std::shared_ptr<CoordinatorConfigurationProvider> configProvider_;

    std::unique_ptr<PeriodicTaskExecutor> periodicTaskExecutor_;
};
}
