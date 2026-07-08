#include "BridgeModuleFunctions.h"
#include "BridgeModuleGlobals.h"
#include "AutoZval.h"
#include "PhpBridge.h"
#include "WithSpanAttributes.h"
#include "Helpers.h"
#include <Zend/zend_string.h>

#include <main/php.h>
#include <Zend/zend_API.h>

#include <algorithm>
#include <cctype>

PHP_FUNCTION(detectOpcachePreload) {
    BRIDGE_G(globals)->logger->printf(LogLevel::logLevel_info, "detectOpcachePreload: %d", BRIDGE_G(globals)->bridge.detectOpcachePreload());
}

PHP_FUNCTION(isOpcacheEnabled) {
    BRIDGE_G(globals)->logger->printf(LogLevel::logLevel_info, "isOpcacheEnabled: %d", BRIDGE_G(globals)->bridge.isOpcacheEnabled());
}

PHP_FUNCTION(getExtensionList) {
    auto extensions = BRIDGE_G(globals)->bridge.getExtensionList();
    for (auto const &extension : extensions) {
        BRIDGE_G(globals)->logger->printf(LogLevel::logLevel_info, "name: '%s' version: '%s'", extension.name.c_str(), extension.version.c_str());
    }
}

PHP_FUNCTION(getPhpInfo) {
    auto info = BRIDGE_G(globals)->bridge.getPhpInfo();
    BRIDGE_G(globals)->logger->printf(LogLevel::logLevel_info, "PHP-INFO: %s", info.c_str());
}

PHP_FUNCTION(getPhpSapiName) {
    auto info = BRIDGE_G(globals)->bridge.getPhpSapiName();
    RETURN_STRINGL(info.data(), info.length());
}


PHP_FUNCTION(compileAndExecuteFile) {
    char *fileName = nullptr;
    size_t fileNameLen = 0;

    ZEND_PARSE_PARAMETERS_START(1, 1)
    Z_PARAM_STRING(fileName, fileNameLen)
    ZEND_PARSE_PARAMETERS_END();

    try {
        BRIDGE_G(globals)->bridge.compileAndExecuteFile(std::string_view(fileName, fileNameLen));
    } catch (std::exception const &msg) {
        BRIDGE_G(globals)->logger->printf(LogLevel::logLevel_error, "Native exception caught: '%s'", msg.what());
    }
}


PHP_FUNCTION(findClassEntry) {
    char *className = nullptr;
    size_t classNameLen = 0;

    ZEND_PARSE_PARAMETERS_START(1, 1)
    Z_PARAM_STRING(className, classNameLen)
    ZEND_PARSE_PARAMETERS_END();

    BRIDGE_G(globals)->logger->printf(LogLevel::logLevel_info, "findClassEntry found: %d", static_cast<bool>(opentelemetry::php::findClassEntry(std::string_view(className, classNameLen))));
}

PHP_FUNCTION(getClassStaticPropertyValue) {
    char *className = nullptr;
    size_t classNameLen = 0;
    char *propName = nullptr;
    size_t propNameLen = 0;

    ZEND_PARSE_PARAMETERS_START(2, 2)
    Z_PARAM_STRING(className, classNameLen)
    Z_PARAM_STRING(propName, propNameLen)
    ZEND_PARSE_PARAMETERS_END();

    auto ce = opentelemetry::php::findClassEntry(std::string_view(className, classNameLen));

    if (!ce) {
        BRIDGE_G(globals)->logger->printf(LogLevel::logLevel_info, "getClassStaticPropertyValue class not found");
        RETURN_NULL();
        return;
    }

    auto prop = opentelemetry::php::getClassStaticPropertyValue(ce, std::string_view(propName, propNameLen));
    if (!prop) {
        BRIDGE_G(globals)->logger->printf(LogLevel::logLevel_info, "getClassStaticPropertyValue property not found");
        RETURN_NULL();
        return;
    }

    RETURN_COPY(prop);
}

PHP_FUNCTION(getClassPropertyValue) {
    char *className = nullptr;
    size_t classNameLen = 0;
    char *propName = nullptr;
    size_t propNameLen = 0;
    zval *object = nullptr;

    ZEND_PARSE_PARAMETERS_START(3, 3)
    Z_PARAM_STRING(className, classNameLen)
    Z_PARAM_STRING(propName, propNameLen)
    Z_PARAM_ZVAL(object)
    ZEND_PARSE_PARAMETERS_END();

    auto ce = opentelemetry::php::findClassEntry(std::string_view(className, classNameLen));

    if (!ce) {
        BRIDGE_G(globals)->logger->printf(LogLevel::logLevel_info, "getClassPropertyValue class not found");
        RETURN_NULL();
        return;
    }

    auto prop = opentelemetry::php::getClassPropertyValue(ce, object, std::string_view(propName, propNameLen));
    if (!prop) {
        BRIDGE_G(globals)->logger->printf(LogLevel::logLevel_info, "getClassPropertyValue property not found");
        RETURN_NULL();
        return;
    }

    RETURN_COPY(prop);
}


PHP_FUNCTION(callMethod) {
    char *methodName = nullptr;
    size_t methodNameLen = 0;
    zval *object = nullptr;
    HashTable *args = nullptr;
    zval returnValue;

    ZEND_PARSE_PARAMETERS_START(3, 3)
    Z_PARAM_ZVAL(object)
    Z_PARAM_STRING(methodName, methodNameLen)
    Z_PARAM_ARRAY_HT(args)
    ZEND_PARSE_PARAMETERS_END();

    zend_string *key;
    zval *value;

    std::vector<opentelemetry::php::AutoZval> argsZval;
    argsZval.resize(zend_hash_num_elements(args));

    size_t index = 0;
    ZEND_HASH_FOREACH_STR_KEY_VAL(args, key, value) {
        (void)key;
        ZVAL_COPY(argsZval[index].get(), value);
        index++;
    }
    ZEND_HASH_FOREACH_END();

    bool rv = opentelemetry::php::callMethod(object, std::string_view(methodName, methodNameLen), argsZval.data()->get(), argsZval.size(), &returnValue);
    BRIDGE_G(globals)->logger->printf(LogLevel::logLevel_info, "callMethod rv: %d", rv);

    RETURN_COPY(&returnValue);
}

PHP_FUNCTION(isObjectOfClass) {
    char *className = nullptr;
    size_t classNameLen = 0;
    zval *object = nullptr;

    ZEND_PARSE_PARAMETERS_START(2, 2)
    Z_PARAM_STRING(className, classNameLen)
    Z_PARAM_ZVAL(object)
    ZEND_PARSE_PARAMETERS_END();

    RETURN_BOOL(opentelemetry::php::isObjectOfClass(object, std::string_view(className, classNameLen)));
}

PHP_FUNCTION(getFunctionName) {
    opentelemetry::php::AutoZval zv;
    long framesBack = 0;

    ZEND_PARSE_PARAMETERS_START(1, 1)
    Z_PARAM_LONG(framesBack)
    ZEND_PARSE_PARAMETERS_END();

    auto execData = EG(current_execute_data);
    for (auto frame = 0; frame < framesBack; ++frame) {
        execData = execData->prev_execute_data;
        if (!execData) {
            BRIDGE_G(globals)->logger->printf(LogLevel::logLevel_error, "test failure, can't go %d frames back", framesBack);
            RETURN_NULL();
            return;
        }
    }

    opentelemetry::php::getFunctionName(zv.get(), execData);

    RETURN_COPY(zv.get());
}


PHP_FUNCTION(getFunctionDeclaringScope) {
    opentelemetry::php::AutoZval zv;
    long framesBack = 0;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(framesBack)
    ZEND_PARSE_PARAMETERS_END();

    auto execData = EG(current_execute_data);
    for (auto frame = 0; frame < framesBack; ++frame) {
        execData = execData->prev_execute_data;
        if (!execData) {
            BRIDGE_G(globals)->logger->printf(LogLevel::logLevel_error, "test failure, can't go %d frames back", framesBack);
            RETURN_NULL();
            return;
        }
    }

    opentelemetry::php::getFunctionDeclaringScope(zv.get(), execData);

    RETURN_COPY(zv.get());
}

PHP_FUNCTION(getFunctionDeclarationFileName) {
    opentelemetry::php::AutoZval zv;
    long framesBack = 0;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(framesBack)
    ZEND_PARSE_PARAMETERS_END();

    auto execData = EG(current_execute_data);
    for (auto frame = 0; frame < framesBack; ++frame) {
        execData = execData->prev_execute_data;
        if (!execData) {
            BRIDGE_G(globals)->logger->printf(LogLevel::logLevel_error, "test failure, can't go %d frames back", framesBack);
            RETURN_NULL();
            return;
        }
    }

    opentelemetry::php::getFunctionDeclarationFileName(zv.get(), execData);

    RETURN_COPY(zv.get());
}

PHP_FUNCTION(getFunctionDeclarationLineNo) {
    opentelemetry::php::AutoZval zv;
    long framesBack = 0;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(framesBack)
    ZEND_PARSE_PARAMETERS_END();

    auto execData = EG(current_execute_data);
    for (auto frame = 0; frame < framesBack; ++frame) {
        execData = execData->prev_execute_data;
        if (!execData) {
            BRIDGE_G(globals)->logger->printf(LogLevel::logLevel_error, "test failure, can't go %d frames back", framesBack);
            RETURN_NULL();
            return;
        }
    }

    opentelemetry::php::getFunctionDeclarationLineNo(zv.get(), execData);

    RETURN_COPY(zv.get());
}

PHP_FUNCTION(getCallArguments) {
    opentelemetry::php::AutoZval zv;
    long framesBack = 0;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(framesBack)
    ZEND_PARSE_PARAMETERS_END();

    auto execData = EG(current_execute_data);
    for (auto frame = 0; frame < framesBack; ++frame) {
        execData = execData->prev_execute_data;
        if (!execData) {
            BRIDGE_G(globals)->logger->printf(LogLevel::logLevel_error, "test failure, can't go %d frames back", framesBack);
            RETURN_NULL();
            return;
        }
    }

    opentelemetry::php::getCallArguments(zv.get(), execData);

    RETURN_COPY(zv.get());
}


PHP_FUNCTION(getScopeNameOrThis) {
    opentelemetry::php::AutoZval zv;
    long framesBack = 0;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(framesBack)
    ZEND_PARSE_PARAMETERS_END();

    auto execData = EG(current_execute_data);
    for (auto frame = 0; frame < framesBack; ++frame) {
        execData = execData->prev_execute_data;
        if (!execData) {
            BRIDGE_G(globals)->logger->printf(LogLevel::logLevel_error, "test failure, can't go %d frames back", framesBack);
            RETURN_NULL();
            return;
        }
    }

    opentelemetry::php::getScopeNameOrThis(zv.get(), execData);

    RETURN_COPY(zv.get());
}

PHP_FUNCTION(getExceptionName) {
    zval *object = nullptr;

    ZEND_PARSE_PARAMETERS_START(1, 1)
    Z_PARAM_ZVAL(object)
    ZEND_PARSE_PARAMETERS_END();

    auto name = opentelemetry::php::getExceptionName(Z_OBJ_P(object));
    RETURN_STRINGL(name.data(), name.length());
}

PHP_FUNCTION(getCurrentException) {
    zend_object *object = nullptr;

    ZEND_PARSE_PARAMETERS_START(1, 1)
    Z_PARAM_OBJ(object)
    ZEND_PARSE_PARAMETERS_END();

    opentelemetry::php::AutoZval zv;

    opentelemetry::php::getCurrentException(zv.get(), object);
    RETURN_COPY(zv.get());
}

PHP_FUNCTION(getCompiledFiles) {
    BRIDGE_G(globals)->bridge.getCompiledFiles([](std::string_view file) { BRIDGE_G(globals)->logger->printf(LogLevel::logLevel_info, PRsv, PRsvArg(file)); });
    RETURN_NULL();
}

PHP_FUNCTION(getNewlyCompiledFiles) {
    long lastClass = 0;
    long lastFunction = 0;

    ZEND_PARSE_PARAMETERS_START(2, 2)
    Z_PARAM_LONG(lastClass)
    Z_PARAM_LONG(lastFunction)
    ZEND_PARSE_PARAMETERS_END();

    auto [retLastClass, retLastFunc] = BRIDGE_G(globals)->bridge.getNewlyCompiledFiles([](std::string_view file) { BRIDGE_G(globals)->logger->printf(LogLevel::logLevel_info, PRsv, PRsvArg(file)); }, lastClass, lastFunction);

    zval zlastClass, zlastFunc;
    ZVAL_LONG(&zlastClass, retLastClass);
    ZVAL_LONG(&zlastFunc, retLastFunc);

    RETURN_ARR(zend_new_pair(&zlastClass, &zlastFunc));
}

PHP_FUNCTION(getPhpVersionMajorMinor) {
    auto [major, minor] = BRIDGE_G(globals)->bridge.getPhpVersionMajorMinor();

    zval zMajor, zMinor;
    ZVAL_LONG(&zMajor, major);
    ZVAL_LONG(&zMinor, minor);

    RETURN_ARR(zend_new_pair(&zMajor, &zMinor));
}

PHP_FUNCTION(getWithSpanMetadata) {
    char *className  = nullptr;
    size_t classLen  = 0;
    char *methodName = nullptr;
    size_t methodLen = 0;

    ZEND_PARSE_PARAMETERS_START(2, 2)
    Z_PARAM_STRING(className, classLen)
    Z_PARAM_STRING(methodName, methodLen)
    ZEND_PARSE_PARAMETERS_END();

    // Look up class (class_table keys are lowercase)
    std::string lowerClass(className, classLen);
    std::transform(lowerClass.begin(), lowerClass.end(), lowerClass.begin(), [](unsigned char c){ return std::tolower(c); });
    auto ce = opentelemetry::php::findClassEntry(lowerClass);
    if (!ce) {
        BRIDGE_G(globals)->logger->printf(LogLevel::logLevel_error, "getWithSpanMetadata: class not found: %s", className);
        RETURN_NULL();
    }

    // Look up method (function_table keys are lowercase)
    std::string lowerMethod(methodName, methodLen);
    std::transform(lowerMethod.begin(), lowerMethod.end(), lowerMethod.begin(), [](unsigned char c){ return std::tolower(c); });
    auto *func = reinterpret_cast<zend_function *>(
        zend_hash_str_find_ptr(&ce->function_table, lowerMethod.data(), lowerMethod.length()));
    if (!func) {
        BRIDGE_G(globals)->logger->printf(LogLevel::logLevel_error, "getWithSpanMetadata: method not found: %s::%s", className, methodName);
        RETURN_NULL();
    }

    auto metaOpt = opentelemetry::php::readWithSpanMetadata(func);
    if (!metaOpt) {
        RETURN_NULL();
    }

    auto const &meta = *metaOpt;

    // Build result array
    opentelemetry::php::AutoZval result;
    result.arrayInit();

    // span_name
    {
        opentelemetry::php::AutoZval v;
        if (meta.spanName) {
            v.setString(*meta.spanName);
        } else {
            v.setNull();
        }
        result.arrayAddAssocWithRef("span_name", v);
    }

    // span_kind
    {
        opentelemetry::php::AutoZval v;
        if (meta.spanKind) {
            v.setLong(static_cast<zend_long>(*meta.spanKind));
        } else {
            v.setNull();
        }
        result.arrayAddAssocWithRef("span_kind", v);
    }

    // param_attributes
    {
        opentelemetry::php::AutoZval paramAttrs;
        paramAttrs.arrayInit();
        for (auto const &p : meta.paramAttributes) {
            opentelemetry::php::AutoZval entry;
            entry.arrayInit();
            opentelemetry::php::AutoZval idxZv{static_cast<zend_long>(p.argIndex)};
            opentelemetry::php::AutoZval keyZv;
            keyZv.setString(p.attrKey);
            entry.arrayAddAssocWithRef("arg_index", idxZv);
            entry.arrayAddAssocWithRef("attr_key", keyZv);
            paramAttrs.arrayAddNextWithRef(entry);
        }
        result.arrayAddAssocWithRef("param_attributes", paramAttrs);
    }

    // prop_attributes
    {
        opentelemetry::php::AutoZval propAttrs;
        propAttrs.arrayInit();
        for (auto const &p : meta.propAttributes) {
            opentelemetry::php::AutoZval entry;
            entry.arrayInit();
            opentelemetry::php::AutoZval propZv;
            propZv.setString(p.propName);
            opentelemetry::php::AutoZval keyZv;
            keyZv.setString(p.attrKey);
            entry.arrayAddAssocWithRef("prop_name", propZv);
            entry.arrayAddAssocWithRef("attr_key", keyZv);
            propAttrs.arrayAddNextWithRef(entry);
        }
        result.arrayAddAssocWithRef("prop_attributes", propAttrs);
    }

    RETURN_COPY(result.get());
}

// Exposes opentelemetry::php::hashClassAndFunctionNameLowercase() directly so phpt tests can
// verify it against the hash zend_observer actually computes for a real call
// (see getCurrentCallHash below) without needing to resolve the class/function at all.
PHP_FUNCTION(hashClassAndFunctionNameLowercase) {
    char *className = nullptr;
    size_t classNameLen = 0;
    char *functionName = nullptr;
    size_t functionNameLen = 0;

    ZEND_PARSE_PARAMETERS_START(2, 2)
    Z_PARAM_STRING(className, classNameLen)
    Z_PARAM_STRING(functionName, functionNameLen)
    ZEND_PARSE_PARAMETERS_END();

    zend_ulong hash = opentelemetry::php::hashClassAndFunctionNameLowercase(std::string_view(className, classNameLen), std::string_view(functionName, functionNameLen));
    RETURN_LONG(static_cast<zend_long>(hash));
}

// Exposes opentelemetry::php::getClassAndFunctionHashFromExecuteData() for a real call frame -
// i.e. the hash the observer dispatch path (registerObserverHandlers/observerFcallBeginHandler/
// observerFcallEndHandler) would compute for that call. framesBack follows the same convention
// as getFunctionName() above: 0 is this internal function's own frame, 1 is its immediate caller.
PHP_FUNCTION(getCurrentCallHash) {
    long framesBack = 0;

    ZEND_PARSE_PARAMETERS_START(1, 1)
    Z_PARAM_LONG(framesBack)
    ZEND_PARSE_PARAMETERS_END();

    auto execData = EG(current_execute_data);
    for (auto frame = 0; frame < framesBack; ++frame) {
        execData = execData->prev_execute_data;
        if (!execData) {
            BRIDGE_G(globals)->logger->printf(LogLevel::logLevel_error, "test failure, can't go %d frames back", framesBack);
            RETURN_NULL();
            return;
        }
    }

    zend_ulong hash = opentelemetry::php::getClassAndFunctionHashFromExecuteData(execData);
    RETURN_LONG(static_cast<zend_long>(hash));
}

// Exposes opentelemetry::php::lowercaseHash() - our own DJBX33A-with-inline-tolower reimplementation - so it can be compared directly against PHP's actual built-in hash
// function (zendBuiltinStringHash below) for the same (already-lowercased) bytes.
PHP_FUNCTION(lowercaseHash) {
    char *str = nullptr;
    size_t strLen = 0;

    ZEND_PARSE_PARAMETERS_START(1, 1)
    Z_PARAM_STRING(str, strLen)
    ZEND_PARSE_PARAMETERS_END();

    zend_ulong hash = opentelemetry::php::lowercaseHash(std::string_view(str, strLen));
    RETURN_LONG(static_cast<zend_long>(hash));
}

// Exposes PHP's actual built-in string hash function (zend_inline_hash_func, the same recurrence ZSTR_HASH() uses) directly, with no lowering
PHP_FUNCTION(zendBuiltinStringHash) {
    char *str = nullptr;
    size_t strLen = 0;

    ZEND_PARSE_PARAMETERS_START(1, 1)
    Z_PARAM_STRING(str, strLen)
    ZEND_PARSE_PARAMETERS_END();

    zend_ulong hash = zend_inline_hash_func(str, strLen);
    RETURN_LONG(static_cast<zend_long>(hash));
}

ZEND_BEGIN_ARG_INFO(no_paramters_arginfo, 0)
ZEND_END_ARG_INFO()

// clang-format off
const zend_function_entry phpbridge_functions[] = {
    PHP_FE( detectOpcachePreload, no_paramters_arginfo )
    PHP_FE( isOpcacheEnabled, no_paramters_arginfo )

    PHP_FE( getExtensionList, no_paramters_arginfo )
    PHP_FE( getPhpInfo, no_paramters_arginfo )
    PHP_FE( getPhpSapiName, no_paramters_arginfo )
    PHP_FE( compileAndExecuteFile, no_paramters_arginfo )

    PHP_FE( findClassEntry, no_paramters_arginfo )
    PHP_FE( getClassStaticPropertyValue, no_paramters_arginfo )
    PHP_FE( getClassPropertyValue, no_paramters_arginfo )
    PHP_FE( callMethod, no_paramters_arginfo )
    PHP_FE( isObjectOfClass, no_paramters_arginfo )
    PHP_FE( getFunctionName, no_paramters_arginfo )
    PHP_FE( getFunctionDeclaringScope, no_paramters_arginfo )
    PHP_FE( getFunctionDeclarationFileName, no_paramters_arginfo )
    PHP_FE( getFunctionDeclarationLineNo, no_paramters_arginfo )
    PHP_FE( getCallArguments, no_paramters_arginfo )
    PHP_FE( getScopeNameOrThis, no_paramters_arginfo )
    PHP_FE( getExceptionName, no_paramters_arginfo )
    PHP_FE( getCurrentException, no_paramters_arginfo )

    PHP_FE( getCompiledFiles, no_paramters_arginfo )
    PHP_FE( getNewlyCompiledFiles, no_paramters_arginfo )
    PHP_FE( getPhpVersionMajorMinor, no_paramters_arginfo )

    PHP_FE( getWithSpanMetadata, no_paramters_arginfo )

    PHP_FE( hashClassAndFunctionNameLowercase, no_paramters_arginfo )
    PHP_FE( getCurrentCallHash, no_paramters_arginfo )
    PHP_FE( lowercaseHash, no_paramters_arginfo )
    PHP_FE( zendBuiltinStringHash, no_paramters_arginfo )

    PHP_FE_END
};
// clang-format on
