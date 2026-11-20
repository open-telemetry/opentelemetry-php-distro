#include "transport/OpAmp.h"
#include "Logger.h"
#include "ConfigurationStorage.h"
#include "ResourceDetector.h"
#include "PhpBridgeInterface.h"
#include "../PhpBridgeMock.h"

#include <gtest/gtest.h>
#include <gmock/gmock.h>

using namespace std::chrono_literals;
using namespace std::literals;

namespace opentelemetry::php::transport {

class MockHttpTransportAsync : public HttpTransportAsyncInterface {
public:
    MOCK_METHOD(void, initializeConnection, (std::string, std::size_t, std::string, enpointHeaders_t const &, std::chrono::milliseconds, std::size_t, std::chrono::milliseconds, HttpEndpointSSLOptions), (override));
    MOCK_METHOD(void, enqueue, (std::size_t, std::span<std::byte>, responseCallback_t), (override));
    MOCK_METHOD(void, updateRetryDelay, (size_t, std::chrono::milliseconds), (override));
};

class OpAmpTest : public ::testing::Test {
public:
    OpAmpTest() {
        if (std::getenv("OTEL_PHP_DEBUG_LOG_TESTS")) {
            auto serr = std::make_shared<opentelemetry::php::LoggerSinkStdErr>();
            serr->setLevel(logLevel_trace);
            reinterpret_cast<opentelemetry::php::Logger *>(log_.get())->attachSink(serr);
        }
    }

protected:
    std::shared_ptr<opentelemetry::php::LoggerInterface> log_ = std::make_shared<opentelemetry::php::Logger>(std::vector<std::shared_ptr<opentelemetry::php::LoggerSinkInterface>>());
    std::shared_ptr<MockHttpTransportAsync> transport_ = std::make_shared<MockHttpTransportAsync>();

    std::shared_ptr<ConfigurationStorage> makeConfig(std::function<bool(ConfigurationSnapshot &)> updater = [](ConfigurationSnapshot &) { return false; }) {
        return std::make_shared<ConfigurationStorage>(updater);
    }

    std::shared_ptr<OpAmp> createOpAmp(std::shared_ptr<ConfigurationStorage> config = nullptr, std::shared_ptr<opentelemetry::php::ResourceDetector> resourceDetector = nullptr) {
        if (!config) {
            config = makeConfig();
        }
        return std::make_shared<OpAmp>(log_, config, transport_, resourceDetector);
    }

    std::shared_ptr<opentelemetry::php::ResourceDetector> makeResourceDetector() {
        auto bridge = std::make_shared<opentelemetry::php::test::PhpBridgeMock>();
        EXPECT_CALL(*bridge, getPhpVersionMajorMinor()).WillRepeatedly(::testing::Return(std::make_pair(8, 3)));
        EXPECT_CALL(*bridge, phpUname(::testing::_)).WillRepeatedly(::testing::Return("Linux"));
        return std::make_shared<opentelemetry::php::ResourceDetector>(bridge);
    }
};

TEST_F(OpAmpTest, DefaultIntervals) {
    auto opamp = createOpAmp();

    // Zero values should be ignored - no crash, no update
    opamp->updateHeartbeatInterval(0ms);
    opamp->updatePollingInterval(0ms);
}

TEST_F(OpAmpTest, UpdateHeartbeatInterval) {
    auto opamp = createOpAmp();

    opamp->updateHeartbeatInterval(5000ms);

    // Sub-second values should also be accepted
    opamp->updateHeartbeatInterval(500ms);
}

TEST_F(OpAmpTest, UpdatePollingInterval) {
    auto opamp = createOpAmp();

    opamp->updatePollingInterval(10000ms);

    // Sub-second values should be accepted
    opamp->updatePollingInterval(250ms);
}

TEST_F(OpAmpTest, ZeroIntervalIgnored) {
    auto opamp = createOpAmp();

    opamp->updateHeartbeatInterval(0ms);
    opamp->updatePollingInterval(0ms);

    opamp->updateHeartbeatInterval(std::chrono::milliseconds(-1));
    opamp->updatePollingInterval(std::chrono::milliseconds(-1));
}

TEST_F(OpAmpTest, ConfigWatcherNotification) {
    auto opamp = createOpAmp();

    int notificationCount = 0;
    OpAmp::configFiles_t receivedConfig;

    auto connection = opamp->addConfigUpdateWatcher([&](OpAmp::configFiles_t const &cfg) {
        notificationCount++;
        receivedConfig = cfg;
    });

    auto cfg = opamp->getConfiguration();
    ASSERT_TRUE(cfg.empty());

    opamp->removeConfigUpdateWatcher(connection);
}

TEST_F(OpAmpTest, RemoveAllConfigUpdateWatchers) {
    auto opamp = createOpAmp();

    int count1 = 0, count2 = 0;
    opamp->addConfigUpdateWatcher([&](OpAmp::configFiles_t const &) { count1++; });
    opamp->addConfigUpdateWatcher([&](OpAmp::configFiles_t const &) { count2++; });

    opamp->removeAllConfigUpdateWatchers();
}

TEST_F(OpAmpTest, EmptyEndpointDoesNotStartThread) {
    auto opamp = createOpAmp();

    EXPECT_CALL(*transport_, initializeConnection(::testing::_, ::testing::_, ::testing::_, ::testing::_, ::testing::_, ::testing::_, ::testing::_, ::testing::_)).Times(0);
    EXPECT_CALL(*transport_, enqueue(::testing::_, ::testing::_, ::testing::_)).Times(0);

    opamp->startCommunication();
}

TEST_F(OpAmpTest, DestructorShutsDownCleanly) {
    {
        auto opamp = createOpAmp();
    }
}

TEST_F(OpAmpTest, MultipleIntervalUpdates) {
    auto opamp = createOpAmp();

    for (int i = 1; i <= 10; i++) {
        opamp->updateHeartbeatInterval(std::chrono::milliseconds(i * 100));
        opamp->updatePollingInterval(std::chrono::milliseconds(i * 50));
    }
}

TEST_F(OpAmpTest, HeartbeatSentAtIntervals) {
    // Configure with non-empty endpoint and short intervals
    auto config = makeConfig([](ConfigurationSnapshot &cfg) {
        cfg.opamp_endpoint = "http://localhost:4320";
        cfg.opamp_heartbeat_interval = 50ms;
        cfg.opamp_polling_interval = 50ms;
        return true;
    });
    config->update();

    auto resourceDetector = makeResourceDetector();
    auto opamp = createOpAmp(config, resourceDetector);

    std::atomic_int enqueueCount = 0;

    // Allow any number of enqueue calls, count them
    EXPECT_CALL(*transport_, initializeConnection(::testing::_, ::testing::_, ::testing::_, ::testing::_, ::testing::_, ::testing::_, ::testing::_, ::testing::_)).Times(1);
    EXPECT_CALL(*transport_, enqueue(::testing::_, ::testing::_, ::testing::_))
        .WillRepeatedly([&enqueueCount](std::size_t, std::span<std::byte>, MockHttpTransportAsync::responseCallback_t) {
            enqueueCount++;
        });

    opamp->startCommunication();

    // Wait for heartbeat thread to send a few heartbeats
    // With 50ms interval, in 300ms we expect at least 4-5 sends (1 initial + heartbeats)
    std::this_thread::sleep_for(300ms);

    auto count = enqueueCount.load();
    // 1 initial + at least 4 heartbeats at 50ms intervals over 300ms
    ASSERT_GE(count, 5) << "Expected at least 5 enqueue calls (1 initial + 4 heartbeats), got " << count;
}

TEST_F(OpAmpTest, HeartbeatRespectsUpdatedInterval) {
    auto config = makeConfig([](ConfigurationSnapshot &cfg) {
        cfg.opamp_endpoint = "http://localhost:4320";
        cfg.opamp_heartbeat_interval = 50ms;
        cfg.opamp_polling_interval = 50ms;
        return true;
    });
    config->update();

    auto resourceDetector = makeResourceDetector();
    auto opamp = createOpAmp(config, resourceDetector);

    std::atomic_int enqueueCount = 0;

    EXPECT_CALL(*transport_, initializeConnection(::testing::_, ::testing::_, ::testing::_, ::testing::_, ::testing::_, ::testing::_, ::testing::_, ::testing::_)).Times(1);
    EXPECT_CALL(*transport_, enqueue(::testing::_, ::testing::_, ::testing::_))
        .WillRepeatedly([&enqueueCount](std::size_t, std::span<std::byte>, MockHttpTransportAsync::responseCallback_t) {
            enqueueCount++;
        });

    opamp->startCommunication();

    // Let it run with fast intervals
    std::this_thread::sleep_for(200ms);
    auto countFast = enqueueCount.load();

    // Now slow it down significantly
    opamp->updateHeartbeatInterval(5000ms);
    opamp->updatePollingInterval(5000ms);

    enqueueCount = 0;
    std::this_thread::sleep_for(200ms);
    auto countSlow = enqueueCount.load();

    // Fast phase should have many more calls than slow phase
    ASSERT_GE(countFast, 3) << "Fast phase should have at least 3 sends";
    ASSERT_LE(countSlow, 1) << "Slow phase (5s interval) should have at most 1 send in 200ms, got " << countSlow;
}

TEST_F(OpAmpTest, SeparateHeartbeatAndPollingIntervals) {
    auto config = makeConfig([](ConfigurationSnapshot &cfg) {
        cfg.opamp_endpoint = "http://localhost:4320";
        cfg.opamp_heartbeat_interval = 2000ms;  // slow heartbeat
        cfg.opamp_polling_interval = 50ms;       // fast polling
        return true;
    });
    config->update();

    auto resourceDetector = makeResourceDetector();
    auto opamp = createOpAmp(config, resourceDetector);

    std::atomic_int enqueueCount = 0;

    EXPECT_CALL(*transport_, initializeConnection(::testing::_, ::testing::_, ::testing::_, ::testing::_, ::testing::_, ::testing::_, ::testing::_, ::testing::_)).Times(1);
    EXPECT_CALL(*transport_, enqueue(::testing::_, ::testing::_, ::testing::_))
        .WillRepeatedly([&enqueueCount](std::size_t, std::span<std::byte>, MockHttpTransportAsync::responseCallback_t) {
            enqueueCount++;
        });

    opamp->startCommunication();

    // With polling at 50ms and heartbeat at 2000ms, over 300ms
    // we should see sends driven by the fast polling interval
    std::this_thread::sleep_for(300ms);

    auto count = enqueueCount.load();
    // 1 initial + at least 4 from polling (heartbeat hasn't fired yet at 2000ms)
    ASSERT_GE(count, 5) << "Polling at 50ms should drive sends even with slow heartbeat, got " << count;
}

} // namespace opentelemetry::php::transport
