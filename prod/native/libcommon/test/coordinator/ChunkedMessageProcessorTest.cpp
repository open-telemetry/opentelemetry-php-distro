#include "coordinator/ChunkedMessageProcessor.h"
#include "Logger.h"
#include "coordinator/CoordinatorSharedDataQueue.h"
#include "gmock/gmock.h"
#include <algorithm>

#include <gtest/gtest.h>
#include <gmock/gmock.h>

namespace opentelemetry::php::coordinator {

class ChunkedMessageProcessorActionsMock {
public:
    MOCK_METHOD(void, processReceivedMessage, (const std::span<const std::byte> data));
};

class TestableChunkedMessageProcessor : public opentelemetry::php::coordinator::ChunkedMessageProcessor {
public:
    template <typename... Args>
        TestableChunkedMessageProcessor(Args&&... args)
            : ChunkedMessageProcessor(std::forward<Args>(args)...) {}

    FRIEND_TEST(ChunkedMessageProcessorTest, ShortPayloadIsImmediatelyProcessedUponReception);
    FRIEND_TEST(ChunkedMessageProcessorTest, LongerPayloadIsStoredUntilCompleteUponReception);
    FRIEND_TEST(ChunkedMessageProcessorTest, cleanupAbandonedMessagesRemovesPartialMessage);
};

class ChunkedMessageProcessorTest : public ::testing::Test {
public:
    ChunkedMessageProcessorTest() {

        if (std::getenv("OTEL_PHP_DEBUG_LOG_TESTS")) {
            auto serr = std::make_shared<opentelemetry::php::LoggerSinkStdErr>();
            serr->setLevel(logLevel_trace);
            reinterpret_cast<opentelemetry::php::Logger *>(log_.get())->attachSink(serr);
        }
    }

protected:
    ::testing::StrictMock<ChunkedMessageProcessorActionsMock> mock_;
    std::shared_ptr<LoggerInterface> log_ = std::make_shared<opentelemetry::php::Logger>(std::vector<std::shared_ptr<LoggerSinkInterface>>());
    std::shared_ptr<CoordinatorSharedDataQueue> sharedDataQueue_ = std::make_shared<CoordinatorSharedDataQueue>(log_);
    std::shared_ptr<TestableChunkedMessageProcessor> processor_{std::make_shared<TestableChunkedMessageProcessor>(log_, sharedDataQueue_, [&](const std::span<const std::byte> data) { mock_.processReceivedMessage(data); })};
};

TEST_F(ChunkedMessageProcessorTest, sendPayload) {
    std::string testPayload(17000, 'A');

    ASSERT_GT(testPayload.size(), CoordinatorSharedDataQueue::maxMqPayloadSize);
    EXPECT_TRUE(processor_->sendPayload(testPayload));

    EXPECT_CALL(mock_, processReceivedMessage(::testing::_)).Times(1).WillOnce(::testing::WithArgs<0>(::testing::Invoke([&](std::span<const std::byte> data) {
        EXPECT_EQ(data.size(), testPayload.size());
        ASSERT_TRUE(std::ranges::equal(data, std::as_bytes(std::span<const char>(testPayload.data(), testPayload.size()))));
    })));

    char buffer[CoordinatorSharedDataQueue::maxMqPayloadSize];
    while (processor_->tryReceiveMessage(buffer, CoordinatorSharedDataQueue::maxMqPayloadSize))
        ;
}

TEST_F(ChunkedMessageProcessorTest, sendPayload_maxPayloadDataSize) {
    std::string testPayload(sizeof(CoordinatorPayload::payload), 'A');

    ASSERT_EQ(testPayload.size(), sizeof(CoordinatorPayload::payload));
    EXPECT_TRUE(processor_->sendPayload(testPayload));

    EXPECT_CALL(mock_, processReceivedMessage(::testing::_)).Times(1).WillOnce(::testing::WithArgs<0>(::testing::Invoke([&](std::span<const std::byte> data) {
        EXPECT_EQ(data.size(), testPayload.size());
        ASSERT_TRUE(std::ranges::equal(data, std::as_bytes(std::span<const char>(testPayload.data(), testPayload.size()))));
    })));

    char buffer[CoordinatorSharedDataQueue::maxMqPayloadSize];
    while (processor_->tryReceiveMessage(buffer, CoordinatorSharedDataQueue::maxMqPayloadSize))
        ;
}

TEST_F(ChunkedMessageProcessorTest, sendPayload_maxPayloadDataSizePlusOne) {
    std::string testPayload(sizeof(CoordinatorPayload::payload) + 1, 'A');

    ASSERT_EQ(testPayload.size(), sizeof(CoordinatorPayload::payload) + 1);
    EXPECT_TRUE(processor_->sendPayload(testPayload));

    EXPECT_CALL(mock_, processReceivedMessage(::testing::_)).Times(1).WillOnce(::testing::WithArgs<0>(::testing::Invoke([&](std::span<const std::byte> data) {
        EXPECT_EQ(data.size(), testPayload.size());
        ASSERT_TRUE(std::ranges::equal(data, std::as_bytes(std::span<const char>(testPayload.data(), testPayload.size()))));
    })));

    char buffer[CoordinatorSharedDataQueue::maxMqPayloadSize];
    while (processor_->tryReceiveMessage(buffer, CoordinatorSharedDataQueue::maxMqPayloadSize))
        ;
}

TEST_F(ChunkedMessageProcessorTest, sendPayload_smallSingleChunk) {
    std::string testPayload = "ABCDEF";

    EXPECT_TRUE(processor_->sendPayload(testPayload));

    EXPECT_CALL(mock_, processReceivedMessage(::testing::_)).Times(1).WillOnce(::testing::WithArgs<0>(::testing::Invoke([&](std::span<const std::byte> data) {
        EXPECT_EQ(data.size(), testPayload.size());
        ASSERT_TRUE(std::ranges::equal(data, std::as_bytes(std::span<const char>(testPayload.data(), testPayload.size()))));
    })));

    char buffer[CoordinatorSharedDataQueue::maxMqPayloadSize];
    while (processor_->tryReceiveMessage(buffer, CoordinatorSharedDataQueue::maxMqPayloadSize))
        ;
}

TEST_F(ChunkedMessageProcessorTest, sendPayload_emptyPayload) {
    std::string testPayload = "";

    EXPECT_TRUE(processor_->sendPayload(testPayload));

    EXPECT_CALL(mock_, processReceivedMessage(::testing::_)).Times(0);

    char buffer[CoordinatorSharedDataQueue::maxMqPayloadSize];
    while (processor_->tryReceiveMessage(buffer, CoordinatorSharedDataQueue::maxMqPayloadSize))
        ;
}

TEST_F(ChunkedMessageProcessorTest, cleanupAbandonedMessagesRemovesPartialMessage) {
    constexpr size_t capacity = sizeof(CoordinatorPayload::payload);
    size_t totalSize = capacity * 2 + 10; // message needing 3 chunks
    // std::vector<std::byte> data(totalSize, std::byte{1});

    CoordinatorPayload chunk;
    chunk.senderProcessId = 1;
    chunk.msgId = 777;
    chunk.payloadTotalSize = totalSize;
    chunk.payloadOffset = 0;
    chunk.payload.fill(std::byte{1});

    ASSERT_TRUE(processor_->recievedMessages_.empty());

    EXPECT_NO_THROW(processor_->processReceivedChunk(&chunk, sizeof(CoordinatorPayload)));

    ASSERT_EQ(processor_->recievedMessages_.size(), 1u);

    std::this_thread::sleep_for(std::chrono::milliseconds(10));

    chunk.senderProcessId = 2;
    EXPECT_NO_THROW(processor_->processReceivedChunk(&chunk, sizeof(CoordinatorPayload)));

    auto now = std::chrono::steady_clock::now();
    processor_->cleanupAbandonedMessages(now, std::chrono::milliseconds(9)); // should cleanup only first message at first attempt
    ASSERT_EQ(processor_->recievedMessages_.size(), 1u);

    // Cleanup after large time advance
    processor_->cleanupAbandonedMessages(now + std::chrono::hours(1), std::chrono::seconds(1));

    ASSERT_TRUE(processor_->recievedMessages_.empty());
}

TEST_F(ChunkedMessageProcessorTest, processReceivedChunkWithInvalidSize) {
    CoordinatorPayload chunk;
    chunk.senderProcessId = getpid();
    chunk.msgId = 1;
    chunk.payloadTotalSize = 100;
    chunk.payloadOffset = 0;

    // Size smaller than header
    EXPECT_THROW(processor_->processReceivedChunk(&chunk, offsetof(CoordinatorPayload, payload) - 1), std::runtime_error);
}

TEST_F(ChunkedMessageProcessorTest, processReceivedChunkWithMismatchedOffset) {
    CoordinatorPayload chunk;
    chunk.senderProcessId = getpid();
    chunk.msgId = 1;
    chunk.payloadTotalSize = 10000;
    chunk.payloadOffset = 0;

    processor_->processReceivedChunk(&chunk, sizeof(CoordinatorPayload));

    // Send chunk with wrong offset (not sequential)
    chunk.payloadOffset = 8000; // skipping chunks
    EXPECT_THROW(processor_->processReceivedChunk(&chunk, sizeof(CoordinatorPayload)), std::runtime_error);
}
}