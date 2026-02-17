#include "ConfigurationManager.h"

#include "Logger.h"
#include <string_view>
#include <gtest/gtest.h>
#include <gmock/gmock.h>

using namespace std::literals;

namespace opentelemetry::php {

class MockOptionValueProvider : public config::OptionValueProviderInterface {
public:
    MOCK_METHOD(std::optional<std::string>, getIniOptionValue, (std::string_view));
    MOCK_METHOD(std::optional<std::string>, getEnvironmentOptionValue, (std::string_view));
    MOCK_METHOD(std::optional<std::string>, getDynamicOptionValue, (std::string_view));
    MOCK_METHOD(void, update, (configFiles_t const &));
};

class ConfigurationManagerTest : public ::testing::Test {
public:
    ConfigurationManagerTest() {

        if (std::getenv("OTEL_PHP_DEBUG_LOG_TESTS")) {
            auto serr = std::make_shared<opentelemetry::php::LoggerSinkStdErr>();
            serr->setLevel(logLevel_trace);
            reinterpret_cast<opentelemetry::php::Logger *>(log_.get())->attachSink(serr);
        }
    }

protected:
    std::shared_ptr<opentelemetry::php::LoggerInterface> log_ = std::make_shared<opentelemetry::php::Logger>(std::vector<std::shared_ptr<opentelemetry::php::LoggerSinkInterface>>());
    std::shared_ptr<MockOptionValueProvider> optionValueProviderMock_ = std::make_shared<MockOptionValueProvider>();
    ConfigurationManager cfg_{log_, optionValueProviderMock_};
};

TEST_F(ConfigurationManagerTest, update) {
    EXPECT_CALL(*optionValueProviderMock_, getIniOptionValue(::testing::_)).Times(::testing::AtLeast(1)).WillRepeatedly(::testing::Return(std::nullopt));
    EXPECT_CALL(*optionValueProviderMock_, getEnvironmentOptionValue(::testing::_)).Times(::testing::AtLeast(1)).WillRepeatedly(::testing::Return(std::nullopt));
    EXPECT_CALL(*optionValueProviderMock_, getDynamicOptionValue(::testing::_)).Times(::testing::AtLeast(1)).WillRepeatedly(::testing::Return(std::nullopt));
    EXPECT_CALL(*optionValueProviderMock_, update(::testing::_)).Times(::testing::Exactly(3));

    ConfigurationSnapshot snapshot;
    cfg_.updateIfChanged(snapshot);
    ASSERT_EQ(snapshot.revision, 1u);
    cfg_.update({});
    ASSERT_EQ(snapshot.revision, 1u);
    cfg_.update({});
    ASSERT_EQ(snapshot.revision, 1u);
    cfg_.update({});
    cfg_.updateIfChanged(snapshot);
    ASSERT_EQ(snapshot.revision, 4u);
}

TEST_F(ConfigurationManagerTest, updateSomeOption) {
    EXPECT_CALL(*optionValueProviderMock_, getEnvironmentOptionValue(::testing::_)).Times(::testing::AtLeast(1)).WillRepeatedly(::testing::Return(std::nullopt));
    EXPECT_CALL(*optionValueProviderMock_, getDynamicOptionValue(::testing::_)).Times(::testing::AtLeast(1)).WillRepeatedly(::testing::Return(std::nullopt));

    EXPECT_CALL(*optionValueProviderMock_, getIniOptionValue(::testing::_)).Times(::testing::AnyNumber()).WillRepeatedly(::testing::Return(std::nullopt));
    EXPECT_CALL(*optionValueProviderMock_, getIniOptionValue("opentelemetry_distro.enabled")).Times(1).WillOnce(::testing::Return("off")).RetiresOnSaturation();
    EXPECT_CALL(*optionValueProviderMock_, update(::testing::_)).Times(::testing::Exactly(2));

    ConfigurationSnapshot snapshot;
    ASSERT_EQ(snapshot.revision, 0u);

    cfg_.updateIfChanged(snapshot);

    ASSERT_EQ(snapshot.enabled, ConfigurationSnapshot().enabled); // default value
    ASSERT_EQ(snapshot.revision, 1u);

    cfg_.update({});
    cfg_.updateIfChanged(snapshot);

    ASSERT_EQ(snapshot.revision, 2u);
    ASSERT_NE(snapshot.enabled, ConfigurationSnapshot().enabled); // default value
    ASSERT_NE(snapshot.enabled, ConfigurationSnapshot().enabled); // default value
    ASSERT_FALSE(snapshot.enabled);

    EXPECT_CALL(*optionValueProviderMock_, getIniOptionValue("opentelemetry_distro.enabled")).Times(1).WillOnce(::testing::Return("on")).RetiresOnSaturation();
    cfg_.update({});
    cfg_.updateIfChanged(snapshot);
}

TEST_F(ConfigurationManagerTest, getOptionValue) {
    EXPECT_CALL(*optionValueProviderMock_, getIniOptionValue(::testing::_)).Times(::testing::AnyNumber()).WillRepeatedly(::testing::Return(std::nullopt));
    EXPECT_CALL(*optionValueProviderMock_, getIniOptionValue("opentelemetry_distro.enabled")).Times(1).WillOnce(::testing::Return("off")).RetiresOnSaturation();
    EXPECT_CALL(*optionValueProviderMock_, getEnvironmentOptionValue(::testing::_)).Times(::testing::AtLeast(1)).WillRepeatedly(::testing::Return(std::nullopt));
    EXPECT_CALL(*optionValueProviderMock_, getDynamicOptionValue(::testing::_)).Times(::testing::AtLeast(1)).WillRepeatedly(::testing::Return(std::nullopt));
    EXPECT_CALL(*optionValueProviderMock_, update(::testing::_)).Times(::testing::Exactly(1));

    ConfigurationSnapshot snapshot;
    ASSERT_EQ(snapshot.revision, 0u);

    cfg_.update({});
    cfg_.updateIfChanged(snapshot);

    ASSERT_EQ(std::get<bool>(cfg_.getOptionValue("enabled"sv, snapshot)), false);
    ASSERT_TRUE(std::holds_alternative<std::nullopt_t>(cfg_.getOptionValue("unknown"sv, snapshot)));
}

TEST_F(ConfigurationManagerTest, getConfigFromEnvVar_NativeOtelOptions) {
    EXPECT_CALL(*optionValueProviderMock_, getDynamicOptionValue(::testing::_)).Times(::testing::AtLeast(1)).WillRepeatedly(::testing::Return(std::nullopt));
    EXPECT_CALL(*optionValueProviderMock_, getIniOptionValue(::testing::_)).Times(::testing::AnyNumber()).WillRepeatedly(::testing::Return(std::nullopt));
    EXPECT_CALL(*optionValueProviderMock_, getEnvironmentOptionValue(::testing::_)).Times(::testing::AnyNumber()).WillRepeatedly(::testing::Return(std::nullopt));
    EXPECT_CALL(*optionValueProviderMock_, getEnvironmentOptionValue("OTEL_EXPORTER_OTLP_INSECURE")).Times(::testing::Exactly(2)).WillOnce(::testing::Return("true")).WillOnce(::testing::Return("false")).RetiresOnSaturation();
    EXPECT_CALL(*optionValueProviderMock_, update(::testing::_)).Times(::testing::Exactly(2));

    ConfigurationSnapshot snapshot;
    ASSERT_EQ(snapshot.revision, 0u);

    cfg_.update({});
    cfg_.updateIfChanged(snapshot);

    ASSERT_EQ(snapshot.OTEL_EXPORTER_OTLP_INSECURE, true);

    cfg_.update({});
    cfg_.updateIfChanged(snapshot);
    ASSERT_EQ(snapshot.OTEL_EXPORTER_OTLP_INSECURE, false);
}

TEST_F(ConfigurationManagerTest, getConfigFromEnvVar_RegularOptions) {
    EXPECT_CALL(*optionValueProviderMock_, update(::testing::_)).Times(::testing::Exactly(2));
    EXPECT_CALL(*optionValueProviderMock_, getDynamicOptionValue(::testing::_)).Times(::testing::AtLeast(1)).WillRepeatedly(::testing::Return(std::nullopt));
    EXPECT_CALL(*optionValueProviderMock_, getIniOptionValue(::testing::_)).Times(::testing::AnyNumber()).WillRepeatedly(::testing::Return(std::nullopt));
    EXPECT_CALL(*optionValueProviderMock_, getEnvironmentOptionValue(::testing::_)).Times(::testing::AnyNumber()).WillRepeatedly(::testing::Return(std::nullopt));
    EXPECT_CALL(*optionValueProviderMock_, getEnvironmentOptionValue("OTEL_PHP_BOOTSTRAP_PHP_PART_FILE")).Times(::testing::Exactly(2)).WillOnce(::testing::Return("some_value")).WillOnce(::testing::Return("some other value")).RetiresOnSaturation();

    ConfigurationSnapshot snapshot;
    ASSERT_EQ(snapshot.revision, 0u);

    cfg_.update({});
    cfg_.updateIfChanged(snapshot);

    ASSERT_EQ(snapshot.OTEL_PHP_BOOTSTRAP_PHP_PART_FILE, "some_value");

    cfg_.update({});
    cfg_.updateIfChanged(snapshot);
    ASSERT_EQ(snapshot.OTEL_PHP_BOOTSTRAP_PHP_PART_FILE, "some other value");
}

TEST_F(ConfigurationManagerTest, dynamicOptionTakesPrecedenceOverEnvVarAndIni) {
    EXPECT_CALL(*optionValueProviderMock_, getDynamicOptionValue(::testing::_)).Times(::testing::AnyNumber()).WillRepeatedly(::testing::Return(std::nullopt));
    EXPECT_CALL(*optionValueProviderMock_, getDynamicOptionValue("bootstrap_php_part_file")).Times(::testing::Exactly(1)).WillRepeatedly(::testing::Return("some_value")).RetiresOnSaturation();

    EXPECT_CALL(*optionValueProviderMock_, getIniOptionValue(::testing::_)).Times(::testing::AnyNumber()).WillRepeatedly(::testing::Return(std::nullopt));
    EXPECT_CALL(*optionValueProviderMock_, getIniOptionValue("opentelemetry_distro.bootstrap_php_part_file")).Times(::testing::Exactly(0));

    EXPECT_CALL(*optionValueProviderMock_, getEnvironmentOptionValue(::testing::_)).Times(::testing::AnyNumber()).WillRepeatedly(::testing::Return(std::nullopt));
    EXPECT_CALL(*optionValueProviderMock_, getEnvironmentOptionValue("OTEL_PHP_BOOTSTRAP_PHP_PART_FILE")).Times(::testing::Exactly(0));

    EXPECT_CALL(*optionValueProviderMock_, update(::testing::_)).Times(::testing::Exactly(1));

    ConfigurationSnapshot snapshot;
    ASSERT_EQ(snapshot.revision, 0u);

    cfg_.update({});
    cfg_.updateIfChanged(snapshot);

    ASSERT_EQ(snapshot.OTEL_PHP_BOOTSTRAP_PHP_PART_FILE, "some_value");
}

TEST_F(ConfigurationManagerTest, iniOptionTakesPrecedenceOverEnvVar) {
    EXPECT_CALL(*optionValueProviderMock_, getDynamicOptionValue(::testing::_)).Times(::testing::AnyNumber()).WillRepeatedly(::testing::Return(std::nullopt));

    EXPECT_CALL(*optionValueProviderMock_, getIniOptionValue(::testing::_)).Times(::testing::AnyNumber()).WillRepeatedly(::testing::Return(std::nullopt));
    EXPECT_CALL(*optionValueProviderMock_, getIniOptionValue("opentelemetry_distro.bootstrap_php_part_file")).Times(::testing::Exactly(1)).WillOnce(::testing::Return("some_value")).RetiresOnSaturation();

    EXPECT_CALL(*optionValueProviderMock_, getEnvironmentOptionValue(::testing::_)).Times(::testing::AnyNumber()).WillRepeatedly(::testing::Return(std::nullopt));
    EXPECT_CALL(*optionValueProviderMock_, getEnvironmentOptionValue("OTEL_PHP_BOOTSTRAP_PHP_PART_FILE")).Times(::testing::Exactly(0));

    EXPECT_CALL(*optionValueProviderMock_, update(::testing::_)).Times(::testing::Exactly(1));

    ConfigurationSnapshot snapshot;
    ASSERT_EQ(snapshot.revision, 0u);

    cfg_.update({});
    cfg_.updateIfChanged(snapshot);

    ASSERT_EQ(snapshot.OTEL_PHP_BOOTSTRAP_PHP_PART_FILE, "some_value");
}

} // namespace opentelemetry::php
