#ifdef HAVE_CONFIG_H
# include "config.h"
#endif
#include "BridgeModuleGlobals.h"
#include "BridgeModuleFunctions.h"
#include "AutoZvalFunctions.h"

#include "otel_distro_version.h"


#include <main/php.h>
#include <Zend/zend_types.h>

ZEND_DECLARE_MODULE_GLOBALS(phpbridge);

#ifndef ZEND_PARSE_PARAMETERS_NONE
#   define ZEND_PARSE_PARAMETERS_NONE() \
        ZEND_PARSE_PARAMETERS_START(0, 0) \
        ZEND_PARSE_PARAMETERS_END()
#endif

PHP_RINIT_FUNCTION(phpbridge) {
    return SUCCESS;
}

PHP_RSHUTDOWN_FUNCTION(phpbridge) {
    return SUCCESS;
}

ZEND_RESULT_CODE  PhpBridgePostDeactivate(void) {
    return ZEND_RESULT_CODE::SUCCESS;
}

PHP_MINFO_FUNCTION(phpbridge) {
}


PHP_GINIT_FUNCTION(phpbridge) {
    phpbridge_globals->globals = new BridgeGlobals();
}

PHP_GSHUTDOWN_FUNCTION(phpbridge) {
    delete phpbridge_globals->globals;
    phpbridge_globals->globals = nullptr;
}

PHP_MINIT_FUNCTION(phpbridge) {
    register_AutoZval_class();
    return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(phpbridge) {
    return SUCCESS;
}

// clang-format off
zend_module_entry phpbridge_module_entry = {
    STANDARD_MODULE_HEADER,
    "otel_phpbridge",                /* Extension name */
    phpbridge_functions,                /* zend_function_entry */
    PHP_MINIT(phpbridge),               /* PHP_MINIT - Module initialization */
    PHP_MSHUTDOWN(phpbridge),           /* PHP_MSHUTDOWN - Module shutdown */
    PHP_RINIT(phpbridge),               /* PHP_RINIT - Request initialization */
    PHP_RSHUTDOWN(phpbridge),           /* PHP_RSHUTDOWN - Request shutdown */
    PHP_MINFO(phpbridge),               /* PHP_MINFO - Module info */
    OTEL_DISTRO_VERSION,            /* Version */
    PHP_MODULE_GLOBALS(phpbridge),      /* PHP_MODULE_GLOBALS */
    PHP_GINIT(phpbridge),               /* PHP_GINIT */
    PHP_GSHUTDOWN(phpbridge),           /* PHP_GSHUTDOWN */
    PhpBridgePostDeactivate,            /* post deactivate */
    STANDARD_MODULE_PROPERTIES_EX
};
// clang-format off

#   ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
#   endif
extern "C" ZEND_GET_MODULE(phpbridge)