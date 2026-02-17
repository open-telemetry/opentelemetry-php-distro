#pragma once

#include "LoggerInterface.h"

#include <chrono>
#include <cstring>
#include <functional>
#include <memory>
#include <mutex>
#include <unordered_map>
#include <stdexcept>
#include <vector>

#include "CoordinatorSharedDataQueue.h"

namespace opentelemetry::php::coordinator {

class ChunkedMessage {
public:
    ChunkedMessage(std::size_t totalSize) : totalSize_(totalSize) {
        data_.reserve(totalSize_);
    }

    // return true if message is complete
    bool addNextChunk(const std::span<const std::byte> chunkData) {
        if (data_.size() + chunkData.size_bytes() > totalSize_) {
            throw std::runtime_error("ChunkedMessage: chunk exceeds total size");
        }

        data_.insert(data_.end(), chunkData.begin(), chunkData.end());
        lastUpdated_ = std::chrono::steady_clock::now();
        return data_.size() == totalSize_;
    }

    const std::vector<std::byte> &getData() const {
        return data_;
    }

    void swapData(std::vector<std::byte> &second) {
        data_.swap(second);
    }

    const std::chrono::steady_clock::time_point &getLastUpdated() const {
        return lastUpdated_;
    }

    size_t getTotalSize() const {
        return totalSize_;
    }

    size_t getCurrentSize() const {
        return data_.size();
    }

private:
    std::size_t totalSize_;
    std::vector<std::byte> data_;
    std::chrono::steady_clock::time_point lastUpdated_;
};

class ChunkedMessageProcessor {
public:
    using sendBuffer_t = std::function<bool(const void *, size_t)>;
    using processMessage_t = std::function<void(const std::span<const std::byte>)>;

    using msgId_t = uint64_t;

    ChunkedMessageProcessor(std::shared_ptr<LoggerInterface> logger, std::shared_ptr<CoordinatorSharedDataQueue> sharedDataQueue, processMessage_t processMessage) : logger_(logger), sharedDataQueue_(std::move(sharedDataQueue)), processMessage_(std::move(processMessage)) {
    }

    bool sendPayload(const std::string &payload);
    void processReceivedChunk(const CoordinatorPayload *chunk, size_t chunkSize);
    void cleanupAbandonedMessages(std::chrono::steady_clock::time_point now, std::chrono::milliseconds maxAge);

    bool tryReceiveMessage(char *buffer, size_t bufferSize);

private:
    bool sendBuffer(const void *data, size_t size);

protected:
    std::mutex mutex_;
    std::shared_ptr<LoggerInterface> logger_;
    std::shared_ptr<CoordinatorSharedDataQueue> sharedDataQueue_;
    processMessage_t processMessage_;
    std::unordered_map<pid_t, std::unordered_map<msgId_t, ChunkedMessage>> recievedMessages_;
    msgId_t msgId_ = 0; // it is not protected by mutex, because it is only used for sending messages and sending is single-threaded in current implementation
};

} // namespace opentelemetry::php::coordinator