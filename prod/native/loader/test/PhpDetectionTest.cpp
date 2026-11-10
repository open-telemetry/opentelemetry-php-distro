#include "phpdetection.h"

#include <gtest/gtest.h>

namespace opentelemetry::loader {

using namespace std::string_view_literals;

// getZendVersion() extracts the engine version out of what get_zend_version() returns.
// php-src formats it as "Zend Engine v<version>, Copyright (c) Zend Technologies".

TEST(PhpDetectionTest, getZendVersionParsesEngineVersionString) {
    EXPECT_EQ(getZendVersion("Zend Engine v4.3.0, Copyright (c) Zend Technologies"sv), "4.3.0"sv);
    EXPECT_EQ(getZendVersion("Zend Engine v4.4.27, Copyright (c) Zend Technologies"sv), "4.4.27"sv);
}

TEST(PhpDetectionTest, getZendVersionRejectsUnexpectedFormat) {
    EXPECT_TRUE(getZendVersion(""sv).empty());
    EXPECT_TRUE(getZendVersion("not a version string"sv).empty());
    // Any future change to the prefix in php-src must be noticed rather than silently
    // producing an empty version, which the loader reports as "Can't find Zend/PHP Engine version".
    EXPECT_TRUE(getZendVersion("Zend Engine 4.3.0"sv).empty());
}

TEST(PhpDetectionTest, getMajorMinorVersionDropsThePatchComponent) {
    EXPECT_EQ(getMajorMinorVersion("4.3.0"sv), "4.3"sv);
    EXPECT_EQ(getMajorMinorVersion("4.4.27"sv), "4.4"sv);
    // No patch component to drop.
    EXPECT_EQ(getMajorMinorVersion("4.3"sv), "4"sv);
    EXPECT_EQ(getMajorMinorVersion("4"sv), "4"sv);
}

// The table in getZendModuleApiVersion() is what selects opentelemetry_php_distro_<n>.so. A wrong
// entry means dispatching to an extension built for a different PHP, so each supported mapping is
// asserted explicitly rather than derived.

TEST(PhpDetectionTest, supportedVersionsMapToTheirModuleApi) {
    struct Expected {
        std::string_view zendVersion;
        int phpVersion;
        int moduleApi;
    };
    constexpr std::array supported{
        Expected{"4.2"sv, 82, 20220829},
        Expected{"4.3"sv, 83, 20230831},
        Expected{"4.4"sv, 84, 20240924},
        Expected{"4.5"sv, 85, 20250925},
    };

    for (auto const &entry : supported) {
        auto const [zendVersion, phpVersion, moduleApi, isSupported] =
            getZendModuleApiVersion(entry.zendVersion);
        EXPECT_EQ(zendVersion, entry.zendVersion);
        EXPECT_EQ(phpVersion, entry.phpVersion) << "for Zend Engine " << entry.zendVersion;
        EXPECT_EQ(moduleApi, entry.moduleApi) << "for Zend Engine " << entry.zendVersion;
        EXPECT_TRUE(isSupported) << "for Zend Engine " << entry.zendVersion;
    }
}

TEST(PhpDetectionTest, php81MapsToItsModuleApiWhicheverWayItsSupportFlagGoes) {
    auto const php81 = getZendModuleApiVersion("4.1"sv);
    EXPECT_EQ(std::get<0>(php81), "4.1"sv);
    EXPECT_EQ(std::get<1>(php81), 81);
    EXPECT_EQ(std::get<2>(php81), 20210902);
}

// Every PHP the distro does not ship an extension for must be reported as unsupported, so that
// get_module() stands down instead of dispatching. This is the behaviour an operator relies on
// when the distro is installed host-wide or injected speculatively.

TEST(PhpDetectionTest, knownButUnsupportedVersionsAreRejected) {
    constexpr std::array unsupported{
        "4.0"sv, // PHP 8.0
        "3.4"sv, // PHP 7.4
        "3.0"sv, // PHP 7.0
        "2.6"sv, // PHP 5.6
        "2.0"sv, // PHP 5.0
    };

    for (auto const &zendVersion : unsupported) {
        auto const [_, phpVersion, moduleApi, isSupported] = getZendModuleApiVersion(zendVersion);
        EXPECT_FALSE(isSupported) << "Zend Engine " << zendVersion << " must not be supported";
        // The module API is still reported, because get_module() uses it to fill in a build id
        // the engine will accept while the loader stands down.
        EXPECT_NE(moduleApi, 0) << "Zend Engine " << zendVersion;
        EXPECT_NE(phpVersion, 0) << "Zend Engine " << zendVersion;
    }
}

// A PHP newer than anything in the table -- the situation on the day after a PHP release -- must
// fail closed rather than fall through to a nearby entry.
TEST(PhpDetectionTest, unknownVersionsAreRejectedAndYieldNoModuleApi) {
    for (auto const &zendVersion : {"4.6"sv, "5.0"sv, "9.9"sv, ""sv, "garbage"sv}) {
        auto const [reported, phpVersion, moduleApi, isSupported] =
            getZendModuleApiVersion(zendVersion);
        EXPECT_EQ(reported, zendVersion);
        EXPECT_FALSE(isSupported) << "unknown Zend Engine " << zendVersion;
        EXPECT_EQ(moduleApi, 0) << "unknown Zend Engine " << zendVersion;
        EXPECT_EQ(phpVersion, 0) << "unknown Zend Engine " << zendVersion;
    }
}

// The two pure stages compose the way get_module() uses them.
TEST(PhpDetectionTest, engineVersionStringResolvesToTheExtensionToLoad) {
    auto const version = getMajorMinorVersion(
        getZendVersion("Zend Engine v4.3.12, Copyright (c) Zend Technologies"sv));
    ASSERT_EQ(version, "4.3"sv);

    auto const [_, phpVersion, moduleApi, isSupported] = getZendModuleApiVersion(version);
    EXPECT_TRUE(isSupported);
    EXPECT_EQ(phpVersion, 83);
    EXPECT_EQ(moduleApi, 20230831);
}

// isThreadSafe() is deliberately not covered here: it answers from the process's global dynamic
// symbol table, so its result is a property of how the host loaded PHP rather than of the engine,
// and it cannot be exercised meaningfully from a unit test binary that never loads PHP.

} // namespace opentelemetry::loader
