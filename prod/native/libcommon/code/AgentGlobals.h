#pragma once

#include "PhpSapi.h"
#include "Logger.h"
#include "transport/HttpTransportAsyncInterface.h"

#include <functional>
#include <memory>

namespace opentelemetry {

namespace php {
class ResourceDetector;
namespace transport {
class OpAmp;
}
} // namespace php
} // namespace opentelemetry

namespace opentelemetry::php {

class ForkableRegistry;
class LoggerInterface;
class PhpBridgeInterface;
class InferredSpans;
class PeriodicTaskExecutor;
class SharedMemoryState;
class RequestScope;
class ConfigurationManager;
class ConfigurationStorage;
struct ConfigurationSnapshot;
class LoggerSinkInterface;
class LogSinkFile;
class InstrumentedFunctionHooksStorageInterface;
class DependencyAutoLoaderGuard;
class VendorCustomizationsInterface;
namespace config {
class OptionValueProviderInterface;
}
namespace coordinator {
class CoordinatorConfigurationProvider;
class CoordinatorSharedDataQueue;
class ChunkedMessageProcessor;
class WorkerRegistrar;

} // namespace coordinator
namespace transport {
class HttpTransportAsyncInterface;
} // namespace transport

// clang-format off

class AgentGlobals {
public:
    AgentGlobals(std::shared_ptr<LoggerInterface> logger,
        std::function<void(opentelemetry::php::ConfigurationSnapshot const &)> loggerConfigUpdateFunc,
        std::shared_ptr<PhpBridgeInterface> bridge,
        std::shared_ptr<InstrumentedFunctionHooksStorageInterface> hooksStorage,
        std::shared_ptr<InferredSpans> inferredSpans,
        std::shared_ptr<coordinator::CoordinatorSharedDataQueue> sharedDataQueue,
        std::shared_ptr<coordinator::CoordinatorConfigurationProvider> sharedCoordinatorConfigProvider,
        std::shared_ptr<config::OptionValueProviderInterface> optionValueProvider);

    ~AgentGlobals();

    std::shared_ptr<PeriodicTaskExecutor> getPeriodicTaskExecutor();

    std::shared_ptr<VendorCustomizationsInterface> vendorCustomizations_;
    std::shared_ptr<ForkableRegistry> forkableRegistry_;
    std::shared_ptr<ConfigurationManager> configManager_;
    std::shared_ptr<ConfigurationStorage> config_;
    std::shared_ptr<LoggerInterface> logger_;
    std::shared_ptr<LoggerSinkInterface> logSinkStdErr_;
    std::shared_ptr<LoggerSinkInterface> logSinkSysLog_;
    std::shared_ptr<LoggerSinkFile> logSinkFile_;
    std::shared_ptr<PhpBridgeInterface> bridge_;
    std::shared_ptr<SharedMemoryState> sharedMemory_;
    std::shared_ptr<coordinator::CoordinatorConfigurationProvider> coordinatorConfigProvider_;
    std::shared_ptr<coordinator::ChunkedMessageProcessor> processor_;
    std::shared_ptr<transport::HttpTransportAsyncInterface> httpTransportAsync_;
    std::shared_ptr<DependencyAutoLoaderGuard> dependencyAutoLoaderGuard_;
    std::shared_ptr<InstrumentedFunctionHooksStorageInterface> hooksStorage_;
    std::shared_ptr<PhpSapi> sapi_;
    std::shared_ptr<InferredSpans> inferredSpans_;
    std::shared_ptr<PeriodicTaskExecutor> periodicTaskExecutor_;
    std::shared_ptr<RequestScope> requestScope_;

    std::shared_ptr<coordinator::WorkerRegistrar> workerRegistrar_;
};

} // namespace opentelemetry::php
