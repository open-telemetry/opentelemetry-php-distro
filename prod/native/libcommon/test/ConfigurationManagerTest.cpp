
#include "ConfigurationManager.h"

#include <string_view>
#include <gtest/gtest.h>
#include <gmock/gmock.h>

using namespace std::literals;

namespace opentelemetry::php {

class MockIniReader {
public:
    MOCK_METHOD(std::optional<std::string>, getIniValue, (std::string_view));
};

class ConfigurationManagerTest : public ::testing::Test {
public:
    MockIniReader iniMock_;
    ConfigurationManager cfg_{[&](std::string_view name) {
        return iniMock_.getIniValue(name);
    }};
};

TEST_F(ConfigurationManagerTest, update) {
    ON_CALL(iniMock_, getIniValue(::testing::_)).WillByDefault(::testing::Return(std::nullopt));
    EXPECT_CALL(iniMock_, getIniValue(::testing::_)).Times(::testing::AtLeast(1));
    //.WillRepeatedly(::testing::Return("Category 5"));


    ConfigurationSnapshot snapshot;
    cfg_.updateIfChanged(snapshot);
    ASSERT_EQ(snapshot.revision, 1u);
    cfg_.update();
    ASSERT_EQ(snapshot.revision, 1u);
    cfg_.update();
    ASSERT_EQ(snapshot.revision, 1u);
    cfg_.update();
    cfg_.updateIfChanged(snapshot);
    ASSERT_EQ(snapshot.revision, 4u);
}

TEST_F(ConfigurationManagerTest, updateSomeOption) {
    EXPECT_CALL(iniMock_, getIniValue(::testing::_)).Times(::testing::AnyNumber()).WillRepeatedly(::testing::Return(std::nullopt));
    // EXPECT_CALL(iniMock_, getIniValue("opentelemetry_distro.api_key")).Times(1).WillOnce(::testing::Return("secret_api_key"s)).RetiresOnSaturation();
    // EXPECT_CALL(iniMock_, getIniValue("opentelemetry_distro.server_timeout")).Times(1).WillOnce(::testing::Return("10s"s)).RetiresOnSaturation();
    EXPECT_CALL(iniMock_, getIniValue("opentelemetry_distro.enabled")).Times(1).WillOnce(::testing::Return("off")).RetiresOnSaturation();

    ConfigurationSnapshot snapshot;
    ASSERT_EQ(snapshot.revision, 0u);

    cfg_.updateIfChanged(snapshot);

    // ASSERT_TRUE(snapshot.api_key.empty());
    // ASSERT_EQ(snapshot.server_timeout, ConfigurationSnapshot().server_timeout); // default value
    ASSERT_EQ(snapshot.enabled, ConfigurationSnapshot().enabled); // default value
    ASSERT_EQ(snapshot.revision, 1u);

    cfg_.update();
    cfg_.updateIfChanged(snapshot);

    ASSERT_EQ(snapshot.revision, 2u);
    // ASSERT_EQ(snapshot.api_key, "secret_api_key"s);
    ASSERT_NE(snapshot.enabled, ConfigurationSnapshot().enabled); // default value
    // ASSERT_EQ(snapshot.server_timeout.count(), 10000ul);
    ASSERT_NE(snapshot.enabled, ConfigurationSnapshot().enabled); // default value
    ASSERT_FALSE(snapshot.enabled);

    EXPECT_CALL(iniMock_, getIniValue("opentelemetry_distro.enabled")).Times(1).WillOnce(::testing::Return("on")).RetiresOnSaturation();
    cfg_.update();
    cfg_.updateIfChanged(snapshot);
}

TEST_F(ConfigurationManagerTest, getOptionValue) {
    EXPECT_CALL(iniMock_, getIniValue(::testing::_)).Times(::testing::AnyNumber()).WillRepeatedly(::testing::Return(std::nullopt));
    // EXPECT_CALL(iniMock_, getIniValue("opentelemetry_distro.api_key")).Times(1).WillOnce(::testing::Return("secret_api_key"s)).RetiresOnSaturation();
    // EXPECT_CALL(iniMock_, getIniValue("opentelemetry_distro.server_timeout")).Times(1).WillOnce(::testing::Return("10s"s)).RetiresOnSaturation();
    EXPECT_CALL(iniMock_, getIniValue("opentelemetry_distro.enabled")).Times(1).WillOnce(::testing::Return("off")).RetiresOnSaturation();

    ConfigurationSnapshot snapshot;
    ASSERT_EQ(snapshot.revision, 0u);

    cfg_.update();
    cfg_.updateIfChanged(snapshot);

    // ASSERT_EQ(std::get<std::string>(cfg_.getOptionValue("api_key"sv, snapshot)), "secret_api_key"s);
    // ASSERT_EQ(std::get<std::chrono::milliseconds>(cfg_.getOptionValue("server_timeout"sv, snapshot)), std::chrono::milliseconds(10000));
    ASSERT_EQ(std::get<bool>(cfg_.getOptionValue("enabled"sv, snapshot)), false);
    ASSERT_TRUE(std::holds_alternative<std::nullopt_t>(cfg_.getOptionValue("unknown"sv, snapshot)));
}

TEST_F(ConfigurationManagerTest, getConfigFromEnvVar_NativeOtelOptions) {
    EXPECT_CALL(iniMock_, getIniValue(::testing::_)).Times(::testing::AnyNumber()).WillRepeatedly(::testing::Return(std::nullopt));

    ConfigurationSnapshot snapshot;
    ASSERT_EQ(snapshot.revision, 0u);

    setenv("OTEL_EXPORTER_OTLP_INSECURE", "true", 1);

    cfg_.update();
    cfg_.updateIfChanged(snapshot);

    ASSERT_EQ(snapshot.OTEL_EXPORTER_OTLP_INSECURE, true);

    setenv("OTEL_EXPORTER_OTLP_INSECURE", "false", 1);
    cfg_.update();
    cfg_.updateIfChanged(snapshot);
    ASSERT_EQ(snapshot.OTEL_EXPORTER_OTLP_INSECURE, false);
}

TEST_F(ConfigurationManagerTest, getConfigFromEnvVar_RegularOptions) {
    EXPECT_CALL(iniMock_, getIniValue(::testing::_)).Times(::testing::AnyNumber()).WillRepeatedly(::testing::Return(std::nullopt));

    ConfigurationSnapshot snapshot;
    ASSERT_EQ(snapshot.revision, 0u);

    setenv("OTEL_PHP_BOOTSTRAP_PHP_PART_FILE", "some_value", 1);

    cfg_.update();
    cfg_.updateIfChanged(snapshot);

    ASSERT_EQ(snapshot.OTEL_PHP_BOOTSTRAP_PHP_PART_FILE, "some_value");

    setenv("OTEL_PHP_BOOTSTRAP_PHP_PART_FILE", "some other value", 1);
    cfg_.update();
    cfg_.updateIfChanged(snapshot);
    ASSERT_EQ(snapshot.OTEL_PHP_BOOTSTRAP_PHP_PART_FILE, "some other value");
}

} // namespace opentelemetry::php
