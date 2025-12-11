#pragma once

#include <main/php.h>
#include <Zend/zend.h>
#include <Zend/zend_API.h>
#include <Zend/zend_modules.h>

#include "Logger.h"
#include "PhpBridge.h"

#include <memory>

struct BridgeGlobals {
    BridgeGlobals() {
        auto sink = std::make_shared<opentelemetry::php::LoggerSinkStdErr>();
        sink->setLevel(LogLevel::logLevel_trace);
        logger = std::make_shared<opentelemetry::php::Logger>(std::vector<std::shared_ptr<opentelemetry::php::LoggerSinkInterface>>{std::move(sink)});
    }

    std::shared_ptr<opentelemetry::php::Logger> logger;
    opentelemetry::php::PhpBridge bridge{logger};
};

ZEND_BEGIN_MODULE_GLOBALS(phpbridge)
    BridgeGlobals *globals;
ZEND_END_MODULE_GLOBALS(phpbridge)

ZEND_EXTERN_MODULE_GLOBALS(phpbridge)

#ifdef ZTS
#define BRIDGE_G(member) ZEND_MODULE_GLOBALS_ACCESSOR(phpbridge, member)
#else
#define BRIDGE_G(member) (phpbridge_globals.member)
#endif