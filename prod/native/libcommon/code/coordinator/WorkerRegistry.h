#pragma once

#include "LoggerInterface.h"
#include <cstddef>
#include <memory>
#include <unordered_set>
#include <sys/types.h>
#include <signal.h>
#include <errno.h>

namespace opentelemetry::php::coordinator {

class WorkerRegistry {
public:
    WorkerRegistry(std::shared_ptr<LoggerInterface> logger) : logger_(std::move(logger)) {
    }

    void registerWorker(pid_t processId, pid_t parentProcessId) {
        ELOG_DEBUG(logger_, COORDINATOR, "WorkerRegistry: registering worker with process id {} and parent process id {}", processId, parentProcessId);
        std::lock_guard<std::mutex> lock(mutex_);
        workers_.emplace(processId);
    }

    void unregisterWorker(pid_t processId) {
        ELOG_DEBUG(logger_, COORDINATOR, "WorkerRegistry: removing worker with process id {}", processId);
        std::lock_guard<std::mutex> lock(mutex_);
        workers_.erase(processId);
    }

    bool hasWorker(pid_t processId) const {
        std::lock_guard<std::mutex> lock(mutex_);
        return workers_.find(processId) != workers_.end();
    }

    void verifyWorkersAlive() {
        std::lock_guard<std::mutex> lock(mutex_);
        for (auto it = workers_.begin(); it != workers_.end();) {
            pid_t pid = *it;
            if (kill(pid, 0) == -1 && errno == ESRCH) {
                ELOG_DEBUG(logger_, COORDINATOR, "WorkerRegistry: worker with process id {} is not alive, removing from registry", pid);
                it = workers_.erase(it);
            } else {
                ++it;
            }
        }
    }

    std::size_t getWorkerCount() const {
        std::lock_guard<std::mutex> lock(mutex_);
        return workers_.size();
    }

private:
    std::shared_ptr<LoggerInterface> logger_;
    std::unordered_set<pid_t> workers_;
    mutable std::mutex mutex_;
};
}