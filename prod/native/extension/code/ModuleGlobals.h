#pragma once

#include "AgentGlobals.h"
#include "PhpErrorData.h"

#include <main/php.h>
#include <Zend/zend.h>
#include <Zend/zend_API.h>
#include <Zend/zend_modules.h>

#include <memory>

ZEND_BEGIN_MODULE_GLOBALS(opentelemetry_distro)
    opentelemetry::php::AgentGlobals *globals;
ZEND_END_MODULE_GLOBALS(opentelemetry_distro)

ZEND_EXTERN_MODULE_GLOBALS(opentelemetry_distro)
#ifdef ZTS
#define OTEL_G(member) ZEND_MODULE_GLOBALS_ACCESSOR(opentelemetry_distro, member)
#define OTEL_GL(member) ZEND_MODULE_GLOBALS_ACCESSOR(opentelemetry_distro, globals)->member
#else
#define OTEL_G(member) (opentelemetry_distro_globals.member)
#define OTEL_GL(member) (opentelemetry_distro_globals.globals)->member
#endif
#define EAPM_CFG(option) (*OTEL_GL(config_))->option