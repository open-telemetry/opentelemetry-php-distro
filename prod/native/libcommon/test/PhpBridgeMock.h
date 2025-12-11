#pragma once

#include "PhpBridgeInterface.h"
#include <gmock/gmock.h>

namespace opentelemetry::php::test {

class PhpBridgeMock : public PhpBridgeInterface {
public:
    MOCK_METHOD(bool, callInferredSpans, (std::chrono::milliseconds duration), (const, override));
    MOCK_METHOD(bool, callPHPSideEntryPoint, (LogLevel logLevel, std::chrono::time_point<std::chrono::system_clock> requestInitStart), (const, override));
    MOCK_METHOD(bool, callPHPSideExitPoint, (), (const, override));
    MOCK_METHOD(bool, callPHPSideErrorHandler, (int type, std::string_view errorFilename, uint32_t errorLineno, std::string_view message), (const, override));

    MOCK_METHOD(std::vector<phpExtensionInfo_t>, getExtensionList, (), (const, override));
    MOCK_METHOD(std::string, getPhpInfo, (), (const, override));

    MOCK_METHOD(std::string_view, getPhpSapiName, (), (const, override));

    MOCK_METHOD(std::optional<std::string_view>, getCurrentExceptionMessage, (), (const, override));

    MOCK_METHOD(void, compileAndExecuteFile, (std::string_view fileName), (const, override));

    MOCK_METHOD(void, enableAccessToServerGlobal, (), (const, override));

    MOCK_METHOD(bool, detectOpcachePreload, (), (const, override));
    MOCK_METHOD(bool, isScriptRestricedByOpcacheAPI, (), (const, override));
    MOCK_METHOD(bool, detectOpcacheRestartPending, (), (const, override));
    MOCK_METHOD(bool, isOpcacheEnabled, (), (const, override));

    MOCK_METHOD(void, getCompiledFiles, (std::function<void(std::string_view)> recordFile), (const, override));
    MOCK_METHOD((std::pair<std::size_t, std::size_t>), getNewlyCompiledFiles, (std::function<void(std::string_view)> recordFile, std::size_t lastClassIndex, std::size_t lastFunctionIndex), (const, override));

    MOCK_METHOD((std::pair<int, int>), getPhpVersionMajorMinor, (), (const, override));
    MOCK_METHOD(std::string, phpUname, (char mode), (const));
};

} // namespace opentelemetry::php::test
