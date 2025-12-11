// set OTEL_PHP_DEBUG_LOG_TESTS envrionment variable to enable trace log to stderr

#include "transport/HttpEndpoints.h"
#include "ConfigurationStorage.h"
#include "Logger.h"

#include <gtest/gtest.h>
#include <gmock/gmock.h>
#include <pthread.h>

namespace opentelemetry::php::transport {

class HttpEndpointsTests : public ::testing::Test {
public:
    HttpEndpointsTests() {

        if (std::getenv("OTEL_PHP_DEBUG_LOG_TESTS")) {
            auto serr = std::make_shared<opentelemetry::php::LoggerSinkStdErr>();
            serr->setLevel(logLevel_trace);
            reinterpret_cast<opentelemetry::php::Logger *>(log_.get())->attachSink(serr);
        }
    }

protected:
    bool configUpdater(opentelemetry::php::ConfigurationSnapshot &cfg) {
        cfg = configForUpdate_;
        return true;
    }

    opentelemetry::php::ConfigurationSnapshot configForUpdate_;
    std::shared_ptr<LoggerInterface> log_ = std::make_shared<opentelemetry::php::Logger>(std::vector<std::shared_ptr<LoggerSinkInterface>>());
    std::shared_ptr<ConfigurationStorage> config_ = std::make_shared<ConfigurationStorage>([this](opentelemetry::php::ConfigurationSnapshot &cfg) { return configUpdater(cfg); });
};

class TestableHttpEndpoints : public HttpEndpoints {
public:
    template <typename... Args>
    TestableHttpEndpoints(Args &&...args) : HttpEndpoints(std::forward<Args>(args)...) {
    }
    FRIEND_TEST(HttpEndpointsTests, add_parseError);
    FRIEND_TEST(HttpEndpointsTests, add_SameServer);
    FRIEND_TEST(HttpEndpointsTests, getConnection);
};

TEST_F(HttpEndpointsTests, add_parseError) {
    HttpEndpoint::enpointHeaders_t headers;
    TestableHttpEndpoints endpoints(log_);

    EXPECT_THROW(endpoints.add("local", 1234, "some-type", headers, 100ms, 3, 100ms, HttpEndpointSSLOptions()), std::runtime_error);
    ASSERT_TRUE(endpoints.endpoints_.empty());
    ASSERT_TRUE(endpoints.connections_.empty());
}

TEST_F(HttpEndpointsTests, add_SameServer) {
    HttpEndpoint::enpointHeaders_t headers;
    TestableHttpEndpoints endpoints(log_);

    HttpEndpointSSLOptions options;

    endpoints.add("http://local/traces", 1234, "some-type", headers, 100ms, 3, 100ms, options);
    endpoints.add("http://local/metrics", 5678, "some-type", headers, 100ms, 3, 100ms, options);
    endpoints.add("https://local/logs", 9898, "some-type", headers, 100ms, 3, 100ms, options);
    ASSERT_EQ(endpoints.endpoints_.size(), 3u);
    ASSERT_EQ(endpoints.connections_.size(), 2u);
}

TEST_F(HttpEndpointsTests, getConnection) {
    HttpEndpoint::enpointHeaders_t cheaders;
    TestableHttpEndpoints endpoints(log_);

    HttpEndpointSSLOptions options;
    endpoints.add("http://local/traces", 1234, "some-type", cheaders, 100ms, 3, 100ms, options);
    endpoints.add("http://local/metrics", 5678, "some-type", cheaders, 100ms, 3, 100ms, options);
    endpoints.add("https://local/logs", 9898, "some-type", cheaders, 100ms, 3, 100ms, options);

    auto [endpointUrl, headers, connId, conn, maxRetries, retryDelay] = endpoints.getConnection(1234);
    ASSERT_EQ(endpointUrl, "http://local/traces");

    auto [endpointUrl2, headers2, connId2, conn2, maxRetries2, retryDelay2] = endpoints.getConnection(5678);
    ASSERT_EQ(endpointUrl2, "http://local/metrics");

    ASSERT_EQ(connId, connId2);

    auto [endpointUrl3, headers3, connId3, conn3, maxRetries3, retryDelay3] = endpoints.getConnection(9898);
    ASSERT_EQ(endpointUrl3, "https://local/logs");

    ASSERT_NE(connId, connId3);
}

} // namespace opentelemetry::php::transport