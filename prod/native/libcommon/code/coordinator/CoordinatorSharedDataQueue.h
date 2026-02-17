#pragma once

#include "LoggerInterface.h"
#include <boost/interprocess/ipc/message_queue.hpp>
#include <chrono>
#include <memory>

namespace opentelemetry::php::coordinator {

struct CoordinatorPayload {
    pid_t senderProcessId;
    uint64_t msgId;
    std::size_t payloadTotalSize;
    std::size_t payloadOffset;
    std::array<std::byte, 4064> payload; // it must be last field in the struct. sizeof(CoordinatorPayload) = 4096 bytes with current payload size
};

struct CoordinatorSharedDataQueue {
public:
    constexpr static size_t maxMqPayloadSize = sizeof(CoordinatorPayload);
    constexpr static size_t maxQueueSize = 100;

    CoordinatorSharedDataQueue(std::shared_ptr<LoggerInterface> logger) : logger_(std::move(logger)) {
    }

    bool enqueueMessage(const void *data, size_t size) {
        try {
            queue_->try_send(data, size, 0);
            return true;
        } catch (boost::interprocess::interprocess_exception &ex) {
            if (logger_) {
                ELOG_DEBUG(logger_, COORDINATOR, "CoordinatorProcess: message_queue send failed: {}", ex.what());
            }
            return false;
        }
    }

    bool tryReceiveMessage(char *buffer, size_t bufferSize, size_t &receivedSize) {
        try {
            unsigned int priority = 0;
            return queue_->timed_receive(buffer, bufferSize, receivedSize, priority, std::chrono::steady_clock::now() + std::chrono::milliseconds(100));
        } catch (std::exception &ex) {
            if (logger_) {
                ELOG_DEBUG(logger_, COORDINATOR, "CoordinatorProcess: message_queue receive failed: '{}'", ex.what());
            }
            return false;
        }
    }
private:
    std::shared_ptr<boost::interprocess::message_queue> queue_{std::make_shared<boost::interprocess::message_queue>(maxQueueSize, maxMqPayloadSize)};
    std::shared_ptr<LoggerInterface> logger_;
};

} // namespace opentelemetry::php::coordinator