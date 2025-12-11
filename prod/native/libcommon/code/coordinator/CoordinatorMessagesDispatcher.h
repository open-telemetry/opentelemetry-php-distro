 #pragma once



#include "LoggerInterface.h"
#include "transport/HttpTransportAsyncInterface.h"

#include <memory>
#include <span>


namespace opentelemetry::php::coordinator {


class CoordinatorMessagesDispatcher {
public:
    CoordinatorMessagesDispatcher(std::shared_ptr<LoggerInterface> logger, std::shared_ptr<transport::HttpTransportAsyncInterface> httpTransport) : logger_(std::move(logger)), httpTransport_(std::move(httpTransport)) {
    }

    ~CoordinatorMessagesDispatcher() = default;

    void processRecievedMessage(const std::span<const std::byte> data);

private:
    std::shared_ptr<LoggerInterface> logger_;
    std::shared_ptr<transport::HttpTransportAsyncInterface> httpTransport_;
};

}