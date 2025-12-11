#include "CoordinatorProcess.h"

namespace opentelemetry::php::coordinator {

void CoordinatorProcess::coordinatorLoop() {
    configProvider_->beginConfigurationFetching();
    setupPeriodicTasks();
    periodicTaskExecutor_->resumePeriodicTasks();

    char buffer[maxMqPayloadSize];
    while (working_.load()) {
        size_t receivedSize = 0;
        unsigned int priority = 0;

        try {
            if (commandQueue_->timed_receive(buffer, maxMqPayloadSize, receivedSize, priority, std::chrono::steady_clock::now() + std::chrono::milliseconds(10))) {
                processor_.processReceivedChunk(reinterpret_cast<const CoordinatorPayload *>(buffer), receivedSize);
            }
        } catch (std::exception &ex) {
            ELOG_DEBUG(logger_, COORDINATOR, "CoordinatorProcess: message_queue receive failed: '{}'", ex.what());
            continue;
        }
    }
    ELOG_DEBUG(logger_, COORDINATOR, "CoordinatorProcess coordinator loop exiting");
}

void CoordinatorProcess::setupPeriodicTasks() {
    periodicTaskExecutor_ = std::make_unique<PeriodicTaskExecutor>(std::vector<PeriodicTaskExecutor::task_t>{[this](PeriodicTaskExecutor::time_point_t now) {
        // Check parent process is alive
        if (getppid() != parentProcessId_) {
            ELOG_DEBUG(logger_, COORDINATOR, "CoordinatorProcess: parent process has exited, shutting down coordinator process");
            working_ = false;
        }

        static auto lastCleanupTime = std::chrono::steady_clock::now();
        if (now - lastCleanupTime >= cleanUpLostMessagesInterval) {
            processor_.cleanupAbandonedMessages(now, std::chrono::seconds(10));
            lastCleanupTime = now;
        }
    }});
    periodicTaskExecutor_->setInterval(std::chrono::milliseconds(100));
}

bool CoordinatorProcess::enqueueMessage(const void *data, size_t size) {
    try {
        commandQueue_->try_send(data, size, 0);
        return true;
    } catch (boost::interprocess::interprocess_exception &ex) {
        if (logger_) {
            ELOG_DEBUG(logger_, COORDINATOR, "CoordinatorProcess: message_queue send failed: {}", ex.what());
        }
        return false;
    }
}

} // namespace opentelemetry::php::coordinator
