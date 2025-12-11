
#pragma once

#include "LoggerInterface.h"
#include "transport/HttpTransportAsyncInterface.h"

#include <functional>
#include <memory>
#include <string>

namespace opentelemetry::php::coordinator {

class CoordinatorTelemetrySignalsSender : public transport::HttpTransportAsyncInterface {
public:
    using sendPayload_t = std::function<bool(std::string const &payload)>;

    CoordinatorTelemetrySignalsSender(std::shared_ptr<LoggerInterface> logger, sendPayload_t sendPayload)
        : logger_(std::move(logger)), sendPayload_(std::move(sendPayload)) {
    }

    ~CoordinatorTelemetrySignalsSender() = default;

    void initializeConnection(std::string endpointUrl, std::size_t endpointHash, std::string contentType, enpointHeaders_t const &endpointHeaders, std::chrono::milliseconds timeout, std::size_t maxRetries, std::chrono::milliseconds retryDelay, opentelemetry::php::transport::HttpEndpointSSLOptions sslOptions);
    void enqueue(std::size_t endpointHash, std::span<std::byte> payload, responseCallback_t callback = {});
    void updateRetryDelay(size_t endpointHash, std::chrono::milliseconds retryDelay) {
    }

private:
    std::shared_ptr<LoggerInterface> logger_;
    sendPayload_t sendPayload_;
};
}
