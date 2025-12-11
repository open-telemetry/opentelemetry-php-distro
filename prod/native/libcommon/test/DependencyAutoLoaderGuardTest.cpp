#include "DependencyAutoLoaderGuard.h"
#include "PhpBridgeMock.h"
#include "Logger.h"

#include <string_view>
#include <gtest/gtest.h>
#include <gmock/gmock.h>

using namespace std::literals;

namespace opentelemetry::php::test {

using namespace std::string_view_literals;

class DependencyAutoLoaderGuardTest : public ::testing::Test {
public:
    DependencyAutoLoaderGuardTest() {
        if (std::getenv("OTEL_PHP_DEBUG_LOG_TESTS")) {
            auto serr = std::make_shared<opentelemetry::php::LoggerSinkStdErr>();
            serr->setLevel(logLevel_trace);
            reinterpret_cast<opentelemetry::php::Logger *>(log_.get())->attachSink(serr);
        }
    }
    std::shared_ptr<LoggerInterface> log_ = std::make_shared<opentelemetry::php::Logger>(std::vector<std::shared_ptr<LoggerSinkInterface>>());
    std::shared_ptr<PhpBridgeMock> bridge_{std::make_shared<::testing::StrictMock<PhpBridgeMock>>()};
    DependencyAutoLoaderGuard guard_{bridge_, log_};
};

TEST_F(DependencyAutoLoaderGuardTest, discardAppFileBecauseItWasDeliveredByDistro) {
    EXPECT_CALL(*bridge_, getPhpVersionMajorMinor()).Times(::testing::Exactly(1)).WillOnce(::testing::Return(std::pair<int, int>(8, 4)));

    guard_.setBootstrapPath("/otel/prod/php/bootstrap.php");

    ASSERT_FALSE(guard_.shouldDiscardFileCompilation("/otel/prod/php/vendor_84/first-package/test.php"));  // file from otel distro scope - no action
    ASSERT_FALSE(guard_.shouldDiscardFileCompilation("/otel/prod/php/vendor_84/second-package/test.php")); // file from otel distro scope - no action

    // clang-format off
    EXPECT_CALL(*bridge_, getNewlyCompiledFiles(::testing::_, 0, 0)).Times(::testing::Exactly(1)).WillOnce(
        ::testing::DoAll(
            ::testing::InvokeArgument<0>("/otel/prod/php/vendor_84/first-package/test.php"sv), // we have that file in cache
            ::testing::InvokeArgument<0>("/otel/prod/php/vendor_84/second-package/test.php"sv), // we have that file in cache
            ::testing::Return(std::pair<int, int>(2, 1)))); // returns index in class/file hashmaps
    // clang-format on

    ASSERT_TRUE(guard_.shouldDiscardFileCompilation("/app/vendor/first-package/test.php")); // file from app scope - test it - should discard - file is Distro delivered
}

TEST_F(DependencyAutoLoaderGuardTest, discardSecondAppFileBecauseItWasDeliveredByDistro) {
    EXPECT_CALL(*bridge_, getPhpVersionMajorMinor()).Times(::testing::Exactly(1)).WillOnce(::testing::Return(std::pair<int, int>(8, 4)));

    guard_.setBootstrapPath("/otel/prod/php/bootstrap.php");

    ASSERT_FALSE(guard_.shouldDiscardFileCompilation("/otel/prod/php/vendor_84/first-package/test.php"));  // file from otel distro scope - no action
    ASSERT_FALSE(guard_.shouldDiscardFileCompilation("/otel/prod/php/vendor_84/second-package/test.php")); // file from otel distro scope - no action

    // clang-format off
    EXPECT_CALL(*bridge_, getNewlyCompiledFiles(::testing::_, 0, 0)).Times(::testing::Exactly(1)).WillOnce(
        ::testing::DoAll(
            ::testing::InvokeArgument<0>("/otel/prod/php/vendor_84/first-package/test.php"sv), // we have that file in cache
            ::testing::InvokeArgument<0>("/otel/prod/php/vendor_84/second-package/test.php"sv), // we have that file in cache
            ::testing::Return(std::pair<int, int>(2, 1)))); // returns index in class/file hashmaps
    // clang-format on

    ASSERT_TRUE(guard_.shouldDiscardFileCompilation("/app/vendor/second-package/test.php")); // file from app scope - test it - should discard - file is Distro delivered
}

TEST_F(DependencyAutoLoaderGuardTest, getCompiledFilesListProgressively) {
    EXPECT_CALL(*bridge_, getPhpVersionMajorMinor()).Times(::testing::Exactly(1)).WillOnce(::testing::Return(std::pair<int, int>(8, 4)));

    guard_.setBootstrapPath("/otel/prod/php/bootstrap.php");

    ASSERT_FALSE(guard_.shouldDiscardFileCompilation("/otel/prod/php/vendor_84/first-package/test.php"));  // file from otel distro scope - no action
    ASSERT_FALSE(guard_.shouldDiscardFileCompilation("/otel/prod/php/vendor_84/second-package/test.php")); // file from otel distro scope - no action

    ::testing::InSequence s;

    // clang-format off
    EXPECT_CALL(*bridge_, getNewlyCompiledFiles(::testing::_, 0, 0)).Times(::testing::Exactly(1)).WillOnce(
        ::testing::DoAll(
            ::testing::InvokeArgument<0>("/otel/prod/php/vendor_84/first-package/test.php"sv), // we have that file in cache
            ::testing::Return(std::pair<int, int>(10, 20)))); // returns index in class/file hashmaps
    // clang-format on

    ASSERT_TRUE(guard_.shouldDiscardFileCompilation("/app/vendor/first-package/test.php")); // file from app scope - test it - should discard - file is Distro delivered

    // clang-format off
    EXPECT_CALL(*bridge_, getNewlyCompiledFiles(::testing::_, 10, 20)).Times(::testing::Exactly(1)).WillOnce(
        ::testing::DoAll(
            ::testing::InvokeArgument<0>("/otel/prod/php/vendor_84/second-package/test.php"sv), // we have that file in cache
            ::testing::Return(std::pair<int, int>(11, 21)))); // returns index in class/file hashmaps
    // clang-format on

    ASSERT_TRUE(guard_.shouldDiscardFileCompilation("/app/vendor/second-package/test.php")); // file from app scope - test it - should discard - file is Distro delivered

    // clang-format off
    EXPECT_CALL(*bridge_, getNewlyCompiledFiles(::testing::_, 11, 21)).Times(::testing::Exactly(1)).WillOnce(
        ::testing::DoAll(
            ::testing::Return(std::pair<int, int>(11, 21)))); // returns index in class/file hashmaps
    // clang-format on

    ASSERT_FALSE(guard_.shouldDiscardFileCompilation("/app/vendor/third-package/test.php")); // file from app scope - test it - should NOT discard - file is NOT Distro delivered
}

TEST_F(DependencyAutoLoaderGuardTest, fileNotInVendorFolder) {
    EXPECT_CALL(*bridge_, getPhpVersionMajorMinor()).Times(::testing::Exactly(1)).WillOnce(::testing::Return(std::pair<int, int>(8, 4)));
    guard_.setBootstrapPath("/otel/prod/php/bootstrap.php");

    ASSERT_FALSE(guard_.shouldDiscardFileCompilation("/otel/prod/php/vendor_84/first-package/test.php")); // file from otel distro scope - no action

    ::testing::InSequence s;

    // clang-format off
    EXPECT_CALL(*bridge_, getNewlyCompiledFiles(::testing::_, 0, 0)).Times(::testing::Exactly(1)).WillOnce(
        ::testing::DoAll(
            ::testing::InvokeArgument<0>("/otel/prod/php/vendor_84/first-package/test.php"sv), // we have that file in cache
            ::testing::Return(std::pair<int, int>(10, 20)))); // returns index in class/file hashmaps
    // clang-format on

    ASSERT_FALSE(guard_.shouldDiscardFileCompilation("/app/first-package/test.php")); // file from app scope - test it - should discard - file is Distro delivered
}

TEST_F(DependencyAutoLoaderGuardTest, wrongVendorFolder_shouldntHappen) {
    EXPECT_CALL(*bridge_, getPhpVersionMajorMinor()).Times(::testing::Exactly(1)).WillOnce(::testing::Return(std::pair<int, int>(8, 4)));

    guard_.setBootstrapPath("/otel/prod/php/bootstrap.php");

    ::testing::InSequence s;

    // clang-format off
    EXPECT_CALL(*bridge_, getNewlyCompiledFiles(::testing::_, 0, 0)).Times(::testing::Exactly(1)).WillOnce(
        ::testing::DoAll(
            ::testing::InvokeArgument<0>("/otel/prod/php/vendor_80/first-package/test.php"sv), // we have that file in cache
            ::testing::Return(std::pair<int, int>(2, 1)))); // returns index in class/file hashmaps
    // clang-format on

    ASSERT_FALSE(guard_.shouldDiscardFileCompilation("/otel/prod/php/vendor_80/first-package/test.php")); // file NOT from otel distro scope - wrong vendor folder

    // clang-format off
    EXPECT_CALL(*bridge_, getNewlyCompiledFiles(::testing::_, 2, 1)).Times(::testing::Exactly(1)).WillOnce(
        ::testing::DoAll(
            ::testing::Return(std::pair<int, int>(2, 1)))); // returns index in class/file hashmaps
    // clang-format on

    ASSERT_FALSE(guard_.shouldDiscardFileCompilation("/app/vendor/first-package/test.php"));
}

} // namespace opentelemetry::php::test
