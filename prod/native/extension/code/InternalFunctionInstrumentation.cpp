#include "ModuleGlobals.h"
#include "InternalFunctionInstrumentation.h"
#include "AutoZval.h"
#include "PhpBridge.h"
#include "WithSpanAttributes.h"
#include "AttrHooksStorage.h"
#include "LoggerInterface.h"
#include "Helpers.h"

#include "Zend/zend.h"
#include "Zend/zend_exceptions.h"
#include "Zend/zend_hash.h"
#include "Zend/zend_globals.h"
#include <Zend/zend_attributes.h>
#include <Zend/zend_observer.h>


#include "InternalFunctionInstrumentationStorage.h"
#include "RequestScope.h"
#include "InstrumentedFunctionHooksStorage.h"
#include "PhpScoper.h"

#include <array>
#include <algorithm>

namespace opentelemetry::php {

using namespace std::literals;

using InternalStorage_t = InternalFunctionInstrumentationStorage<zend_ulong, zif_handler>;

// Forward declaration — defined later in this file.
void handleAndReleaseHookException(zend_object *exception);

/// Calls WithSpanHandler::pre(target, params, class, function, filename, lineno, span_args, attributes)
/// from the observer begin handler when #[WithSpan] is present.
static void callWithSpanHandlerPre(zend_execute_data *execute_data, WithSpanMetadata const &meta) {
    if (!OTEL_GL(requestScope_)->isFunctional()) {
        return;
    }

    bool scoped = OTEL_GL(config_)->get().scoped_deps_enabled;

    // Parameters 0-5: standard pre-hook arguments (same as callPreHook)
    std::array<AutoZval, 8> params;
    getScopeNameOrThis(params[0].get(), execute_data);
    getCallArguments(params[1].get(), execute_data);
    getFunctionDeclaringScope(params[2].get(), execute_data);
    getFunctionName(params[3].get(), execute_data);
    getFunctionDeclarationFileName(params[4].get(), execute_data);
    getFunctionDeclarationLineNo(params[5].get(), execute_data);

    // Parameter 6: span_args array {'name': ..., 'span_kind': ...}
    params[6].arrayInit();
    if (meta.spanName) {
        add_assoc_stringl(params[6].get(), "name", meta.spanName->c_str(), meta.spanName->length());
    }
    if (meta.spanKind) {
        add_assoc_long(params[6].get(), "span_kind", static_cast<zend_long>(*meta.spanKind));
    }

    // Parameter 7: attributes array — start with static attrs from #[WithSpan(attributes: [...])]
    params[7].arrayInit();

    // Re-read WithSpan::$attributes arg (positional 2 / named 'attributes') from op_array
    if (execute_data->func->common.attributes) {
        constexpr std::string_view withSpanLcName = "opentelemetry\\api\\instrumentation\\withspan"sv;
        zend_attribute *wsAttr = zend_get_attribute_str(execute_data->func->common.attributes, withSpanLcName.data(), withSpanLcName.size());
        if (wsAttr) {
            // Find the 'attributes' arg: positional index 2, or named 'attributes'
            for (uint32_t i = 0; i < wsAttr->argc; i++) {
                bool isAttrsArg = false;
                if (!wsAttr->args[i].name && i == 2) {
                    isAttrsArg = true;
                } else if (wsAttr->args[i].name) {
                    std::string_view n{ZSTR_VAL(wsAttr->args[i].name), ZSTR_LEN(wsAttr->args[i].name)};
                    isAttrsArg = (n == "attributes");
                }
                if (isAttrsArg) {
                    zval resolved;
                    ZVAL_UNDEF(&resolved);
                    if (zend_get_attribute_value(&resolved, wsAttr, i, execute_data->func->common.scope) == SUCCESS && Z_TYPE(resolved) == IS_ARRAY) {
                        zend_string *attrKey = nullptr;
                        zval *val = nullptr;
                        ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARR(resolved), attrKey, val) {
                            if (attrKey && val) {
                                zval copy;
                                ZVAL_COPY(&copy, val);
                                zend_hash_update(Z_ARR_P(params[7].get()), attrKey, &copy);
                            }
                        }
                        ZEND_HASH_FOREACH_END();
                        zval_ptr_dtor(&resolved);
                    }
                    break;
                }
            }
        }
    }

    // Append parameter attribute values: read actual arg at call time
    for (auto const &p : meta.paramAttributes) {
        if (p.argIndex < ZEND_CALL_NUM_ARGS(execute_data)) {
            zval *arg = ZEND_CALL_ARG(execute_data, p.argIndex + 1);
            if (arg && Z_TYPE_P(arg) != IS_UNDEF) {
                zval copy;
                ZVAL_COPY(&copy, arg);
                add_assoc_zval_ex(params[7].get(), p.attrKey.c_str(), p.attrKey.length(), &copy);
            }
        }
    }

    // Append property attribute values: read from $this
    if (!meta.propAttributes.empty() && Z_TYPE(execute_data->This) == IS_OBJECT) {
        auto ce = Z_OBJCE(execute_data->This);
        for (auto const &p : meta.propAttributes) {
            auto *prop = getClassPropertyValue(ce, Z_OBJ(execute_data->This), p.propName);
            if (prop && Z_TYPE_P(prop) != IS_UNDEF) {
                zval copy;
                ZVAL_COPY(&copy, prop);
                add_assoc_zval_ex(params[7].get(), p.attrKey.c_str(), p.attrKey.length(), &copy);
            }
        }
    }

    constexpr std::string_view handlerPreUnscoped = "OpenTelemetry\\API\\Instrumentation\\WithSpanHandler::pre"sv;
    auto handlerName = scoped ? PHP_SCOPER_PREFIX "OpenTelemetry\\API\\Instrumentation\\WithSpanHandler::pre"sv : handlerPreUnscoped;

    AutoZval rv;
    try {
        AutomaticExceptionStateRestorer restorer;
        callMethod(nullptr, handlerName, params[0].get(), static_cast<int32_t>(params.size()), rv.get());
        handleAndReleaseHookException(EG(exception));
    } catch (std::exception const &e) {
        ELOGF_CRITICAL(OTEL_GL(logger_), INSTRUMENTATION, "callWithSpanHandlerPre exception: %s", e.what());
    }
}

/// Calls WithSpanHandler::post(target, params, result, exception)
/// from the observer end handler when #[WithSpan] is present.
static void callWithSpanHandlerPost(zend_execute_data *execute_data, zval *retval, zend_object *exception) {
    if (!OTEL_GL(requestScope_)->isFunctional()) {
        return;
    }

    bool scoped = OTEL_GL(config_)->get().scoped_deps_enabled;

    std::array<AutoZval, 4> params;
    getScopeNameOrThis(params[0].get(), execute_data);
    getCallArguments(params[1].get(), execute_data);
    getFunctionReturnValue(params[2].get(), retval);
    getCurrentException(params[3].get(), exception);

    constexpr std::string_view handlerPostUnscoped = "OpenTelemetry\\API\\Instrumentation\\WithSpanHandler::post"sv;
    auto handlerName = scoped ? PHP_SCOPER_PREFIX "OpenTelemetry\\API\\Instrumentation\\WithSpanHandler::post"sv : handlerPostUnscoped;

    AutoZval rv;
    try {
        AutomaticExceptionStateRestorer restorer;
        callMethod(nullptr, handlerName, params[0].get(), static_cast<int32_t>(params.size()), rv.get());
        handleAndReleaseHookException(EG(exception));
    } catch (std::exception const &e) {
        ELOGF_CRITICAL(OTEL_GL(logger_), INSTRUMENTATION, "callWithSpanHandlerPost exception: %s", e.what());
    }
}

void handleAndReleaseHookException(zend_object *exception) {
    if (!exception || !instanceof_function(exception->ce, zend_ce_throwable)) {
        return;
    }

    ELOGF_ERROR(OTEL_GL(logger_), INSTRUMENTATION, "Instrumentation hook error: %s", exceptionToString(exception).c_str());
    OBJ_RELEASE(exception);
}

uint32_t getFunctionArgumentIndex(zend_string *name, zend_function *function) {
	uint32_t numArgs = function->common.num_args;
	if (function->type == ZEND_USER_FUNCTION || (function->common.fn_flags & ZEND_ACC_USER_ARG_INFO)) {
		for (uint32_t i = 0; i < numArgs; i++) {
			if (zend_string_equals(name, function->op_array.arg_info[i].name)) {
				return i;
			}
		}
	} else {
        std::string_view nameSv(ZSTR_VAL(name), ZSTR_LEN(name));
		for (uint32_t i = 0; i < numArgs; i++) {
            std::string_view argName(function->internal_function.arg_info[i].name);
            if (nameSv == argName) {
                return i;
            }
		}
	}
    throw std::runtime_error("argument not found");
}

void argsPostProcessing(AutoZval &functionArgs, AutoZval &returnValue) {
    if (!returnValue.isArray()) {
        return;
    }
    if (zend_is_identical(returnValue.get(), functionArgs.get())) {
        return;
    }

    zend_ulong argIndex = 0;
    zend_string *argStrKey = nullptr;
    zval *argValue = nullptr;

    zend_execute_data *execute_data = EG(current_execute_data);

    uint32_t requiredArgsCount = execute_data->func->type == ZEND_INTERNAL_FUNCTION ? ZEND_CALL_NUM_ARGS(execute_data) : execute_data->func->op_array.last_var;
    uint32_t initalCallNumArgs = ZEND_CALL_NUM_ARGS(execute_data);

    ELOGF_DEBUG(OTEL_GL(logger_), INSTRUMENTATION, "argsPostProcessing requiredArgsCount: %d initialCallNumArgs: %d", requiredArgsCount, initalCallNumArgs);

    uint32_t highestArgIdx = 0;
    ZEND_HASH_FOREACH_KEY_VAL(Z_ARR_P(returnValue.get()), argIndex, argStrKey, argValue) {
        if (!argStrKey) {
            highestArgIdx = std::max(highestArgIdx, (uint32_t)argIndex);
        }
    } ZEND_HASH_FOREACH_END();

    ELOGF_DEBUG(OTEL_GL(logger_), INSTRUMENTATION, "argsPostProcessing highestArgIdx: %d vm_stack free: %d", highestArgIdx, EG(vm_stack_end) - EG(vm_stack_top));

    // extending stack and undefining potential gaps
    if (highestArgIdx + 1 > initalCallNumArgs) {
        uint32_t howManyArgsToAdd = highestArgIdx + 1 - initalCallNumArgs;
        ELOGF_DEBUG(OTEL_GL(logger_), INSTRUMENTATION, "postProcessing trying extend stack frame with %d arguments", howManyArgsToAdd);

        zend_vm_stack_extend_call_frame(&execute_data, initalCallNumArgs, howManyArgsToAdd);

        for (uint32_t idx = 0; idx < howManyArgsToAdd; ++idx) {
            zval *target = ZEND_CALL_ARG(execute_data, execute_data->func->type == ZEND_INTERNAL_FUNCTION ? initalCallNumArgs + idx + 1 :  initalCallNumArgs + idx + 1 + execute_data->func->op_array.T);
            ZVAL_UNDEF(target);
        }
        ZEND_CALL_NUM_ARGS(execute_data) += howManyArgsToAdd;
        ZEND_ADD_CALL_FLAG(execute_data, ZEND_CALL_FREE_EXTRA_ARGS);
        ZEND_ADD_CALL_FLAG(execute_data, ZEND_CALL_MAY_HAVE_UNDEF);
    }

    ZEND_HASH_FOREACH_KEY_VAL(Z_ARR_P(returnValue.get()), argIndex, argStrKey, argValue) {
        if (argStrKey) {
            ELOGF_DEBUG(OTEL_GL(logger_), INSTRUMENTATION, "argsPostProcessing str: %s", ZSTR_VAL(argStrKey));

            try {
                argIndex = getFunctionArgumentIndex(argStrKey, execute_data->func);
            } catch (std::exception const &e) {
                ELOGF_WARNING(OTEL_GL(logger_), INSTRUMENTATION, "postProcessing argument index not found for: '%s'", ZSTR_VAL(argStrKey));
                continue;
            }
        }
        ELOGF_DEBUG(OTEL_GL(logger_), INSTRUMENTATION, "argsPostProcessing idx: %d", argIndex);

        zval *target = nullptr;
        if (argIndex < requiredArgsCount) {
            target = ZEND_CALL_ARG(execute_data, argIndex + 1);
        } else {
            target = ZEND_CALL_ARG(execute_data, execute_data->func->type == ZEND_INTERNAL_FUNCTION ? argIndex + 1 : argIndex + 1 + execute_data->func->op_array.T);

        }
        //TODO consider refs
        zval_ptr_dtor(target);
        ZVAL_COPY(target, argValue);
    } ZEND_HASH_FOREACH_END();
}

void callPreHook(AutoZval &prehook) {
    zend_fcall_info fci = empty_fcall_info;
    zend_fcall_info_cache fcc = empty_fcall_info_cache;

    if (zend_fcall_info_init(const_cast<zval *>(prehook.get()), 0, &fci, &fcc, nullptr, nullptr) == ZEND_RESULT_CODE::FAILURE) {
        throw std::runtime_error("Unable to initialize prehook fcall");
    }

    std::array<AutoZval, 6> parameters;
    getScopeNameOrThis(parameters[0].get(), EG(current_execute_data));
    getCallArguments(parameters[1].get(), EG(current_execute_data));
    getFunctionDeclaringScope(parameters[2].get(), EG(current_execute_data));
    getFunctionName(parameters[3].get(), EG(current_execute_data));
    getFunctionDeclarationFileName(parameters[4].get(), EG(current_execute_data));
    getFunctionDeclarationLineNo(parameters[5].get(), EG(current_execute_data));

    AutoZval ret;
    fci.param_count = parameters.size();
    fci.params = parameters[0].get();
    fci.named_params = nullptr;
    fci.retval = ret.get();
    if (zend_call_function(&fci, &fcc) != SUCCESS) {
        throw std::runtime_error("Unable to call prehook function");
    }

    argsPostProcessing(parameters[1], ret);
}

void callPostHook(AutoZval &hook, zval *return_value, zend_object *exception, zend_execute_data *execute_data) {
    zend_fcall_info fci = empty_fcall_info;
    zend_fcall_info_cache fcc = empty_fcall_info_cache;

    if (zend_fcall_info_init(const_cast<zval *>(hook.get()), 0, &fci, &fcc, nullptr, nullptr) == ZEND_RESULT_CODE::FAILURE) {
        throw std::runtime_error("Unable to initialize posthook fcall");
    }

    std::array<AutoZval, 8> parameters;
    getScopeNameOrThis(parameters[0].get(), EG(current_execute_data));
    getCallArguments(parameters[1].get(), EG(current_execute_data));
    getFunctionReturnValue(parameters[2].get(), return_value);
    getCurrentException(parameters[3].get(), exception);
    getFunctionDeclaringScope(parameters[4].get(), EG(current_execute_data));
    getFunctionName(parameters[5].get(), EG(current_execute_data));
    getFunctionDeclarationFileName(parameters[6].get(), EG(current_execute_data));
    getFunctionDeclarationLineNo(parameters[7].get(), EG(current_execute_data));

    AutoZval hookRv;
    fci.param_count = parameters.size();
    fci.params = parameters[0].get();
    fci.named_params = nullptr;
    fci.retval = hookRv.get();

    if (zend_call_function(&fci, &fcc) != SUCCESS) {
        throw std::runtime_error("Unable to call posthook function");
    }

    if (Z_TYPE_P(hookRv.get()) == IS_UNDEF) {
        return;
    }

    if (!return_value) {
        return;
    }

    // thre is no way to distinguish if posthook returned NULL, becuase in PHP functions are always returning NULL, even if there is no return keyword
    // in that case we can only try to overwrite return value for posthooks with return value type specified explicitly
    if (!(fcc.function_handler->op_array.fn_flags & ZEND_ACC_HAS_RETURN_TYPE) || (ZEND_TYPE_PURE_MASK(fcc.function_handler->common.arg_info[-1].type) & MAY_BE_VOID)) {
        ELOGF_TRACE(OTEL_GL(logger_), INSTRUMENTATION, "callPostHook hook doesn't explicitly specify return type other than void");
        return;
    }

    if (execute_data->func->op_array.fn_flags & ZEND_ACC_HAS_RETURN_TYPE) {
        // uncomment if want to block possibility of adding rv to instrumented void-rv function
        // if ((ZEND_TYPE_PURE_MASK(execute_data->func->common.arg_info[-1].type) & MAY_BE_VOID)) {
        //     return;
        // }
        bool sameType = ZEND_TYPE_CONTAINS_CODE(execute_data->func->common.arg_info[-1].type, Z_TYPE_P(hookRv.get()));
        ELOGF_DEBUG(OTEL_GL(logger_), INSTRUMENTATION, "callPostHook hasRvType: %d, isVoid: %d sameType: %d, hookRvType: %d", execute_data->func->op_array.fn_flags & ZEND_ACC_HAS_RETURN_TYPE, static_cast<bool>(ZEND_TYPE_PURE_MASK(execute_data->func->common.arg_info[-1].type) & MAY_BE_VOID), sameType, hookRv.getType());
    }

    zval_ptr_dtor(return_value);
    ZVAL_COPY(return_value, hookRv.get());
}

inline void callOriginalHandler(zif_handler handler, INTERNAL_FUNCTION_PARAMETERS) {
    zend_try {
        handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    } zend_catch {
        if (*EG(bailout)) {
            LONGJMP(*EG(bailout), FAILURE);
        } else {
            zend_bailout();
        }
    } zend_end_try();
}


void ZEND_FASTCALL internal_function_handler(INTERNAL_FUNCTION_PARAMETERS) {
    auto hash = getClassAndFunctionHashFromExecuteData(execute_data);

    auto originalHandler = InternalStorage_t::getInstance().get(hash);
    if (!originalHandler) {
        auto [cls, func] = getClassAndFunctionName(execute_data);
        ELOGF_CRITICAL(OTEL_GL(logger_), INSTRUMENTATION, "Unable to find function handler " PRsv "::" PRsv, PRsvArg(cls), PRsvArg(func));
        return;
    }

    if (!OTEL_GL(requestScope_)->isFunctional()) {
        callOriginalHandler(originalHandler, INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    auto callbacks = reinterpret_cast<InstrumentedFunctionHooksStorage_t *>(OTEL_GL(hooksStorage_).get())->find(hash);
    if (!callbacks) {
        callOriginalHandler(originalHandler, INTERNAL_FUNCTION_PARAM_PASSTHRU);
        ELOGF_WARNING(OTEL_GL(logger_), INSTRUMENTATION, "Unable to find function callbacks");
        return;
    }

    for (auto &callback : *callbacks) {
        if (callback.first.isNull() || callback.first.isUndef()) {
            continue;
        }

        try {
            AutomaticExceptionStateRestorer restorer;
            callPreHook(callback.first);
            handleAndReleaseHookException(EG(exception));
        } catch (std::exception const &e) {
            auto [cls, func] = getClassAndFunctionName(execute_data);
            ELOGF_CRITICAL(OTEL_GL(logger_), INSTRUMENTATION, "%s hash: 0x%X " PRsv "::" PRsv, e.what(), hash, PRsvArg(cls), PRsvArg(func));
        }
    }

    callOriginalHandler(originalHandler, INTERNAL_FUNCTION_PARAM_PASSTHRU);

    for (auto &callback : *callbacks) {
        if (callback.second.isNull() || callback.second.isUndef()) {
            continue;
        }

        try {
            AutomaticExceptionStateRestorer restorer;
            callPostHook(callback.second, return_value, restorer.getException(), execute_data);

            handleAndReleaseHookException(EG(exception));
        } catch (std::exception const &e) {
            auto [cls, func] = getClassAndFunctionName(execute_data);
            ELOGF_CRITICAL(OTEL_GL(logger_), INSTRUMENTATION, "%s hash: 0x%X " PRsv "::" PRsv, e.what(), hash, PRsvArg(cls), PRsvArg(func));
        }
    }

}


bool instrumentFunction(LoggerInterface *log, std::string_view cName, std::string_view fName, zval *callableOnEntry, zval *callableOnExit) {
    //TODO if called from other place that MINIT - make it thread safe in ZTS
    //TODO use hash struct instead of combined to prevent collisions

    std::string className{cName.data(), cName.length()};
    std::string functionName{fName.data(), fName.length()};

    std::transform(className.begin(), className.end(), className.begin(), [](unsigned char c){ return std::tolower(c); });
    std::transform(functionName.begin(), functionName.end(), functionName.begin(), [](unsigned char c){ return std::tolower(c); });

    zend_ulong hash = hashClassAndFunctionNameLowercase(className, functionName);

    reinterpret_cast<InstrumentedFunctionHooksStorage_t *>(OTEL_GL(hooksStorage_).get())->store(hash, AutoZval{callableOnEntry}, AutoZval{callableOnExit});

    ELOGF_DEBUG(log, INSTRUMENTATION, "instrumentFunction 0x%X " PRsv "::" PRsv " hook stored", hash, PRsvArg(className), PRsvArg(functionName));

    // Internal (native) functions don't go through zend_observer here (not supported on PHP 8.1), so their zif_handler must be patched eagerly. This requires the function to already be resolvable.
    HashTable *table = nullptr;
    if (className.empty()) {
        table = EG(function_table);
    } else if (EG(class_table)) {
        auto ce = static_cast<zend_class_entry *>(zend_hash_str_find_ptr(EG(class_table), className.data(), className.length()));
        if (ce) {
            table = &ce->function_table;
        }
    }

    zend_function *func = table ? reinterpret_cast<zend_function *>(zend_hash_str_find_ptr(table, functionName.data(), functionName.length())) : nullptr;
    if (!func) {
        ELOGF_DEBUG(log, INSTRUMENTATION, "instrumentFunction " PRsv "::" PRsv " not resolvable yet - hook left for lazy zend_observer resolution.", PRsvArg(className), PRsvArg(functionName));
    } else if (func->common.type == ZEND_INTERNAL_FUNCTION) {
        if (func->internal_function.handler != internal_function_handler) {
            InternalStorage_t::getInstance().store(hash, func->internal_function.handler);
            func->internal_function.handler = internal_function_handler;
        }
        ELOGF_DEBUG(log, INSTRUMENTATION, "instrumentFunction " PRsv "::" PRsv " instrumented as internal function, key: 0x%X", PRsvArg(className), PRsvArg(functionName), hash);
    } else {
        ELOGF_DEBUG(log, INSTRUMENTATION, "instrumentFunction " PRsv "::" PRsv " already declared as a user-space function - will be instrumented on first call, key: 0x%X", PRsvArg(className), PRsvArg(functionName), hash);
    }

    return true;
}



void observerFcallBeginHandler(zend_execute_data *execute_data) {
    auto hash = getClassAndFunctionHashFromExecuteData(execute_data);
    ELOGF_TRACE(OTEL_GL(logger_), INSTRUMENTATION, "observerFcallBeginHandler hash 0x%X", hash);

    auto callbacks = reinterpret_cast<InstrumentedFunctionHooksStorage_t *>(OTEL_GL(hooksStorage_).get())->find(hash);
    if (callbacks) {
        for (auto &callback : *callbacks) {
            try {
                AutomaticExceptionStateRestorer restorer;
                callPreHook(callback.first);
                handleAndReleaseHookException(EG(exception));
            } catch (std::exception const &e) {
                auto [cls, func] = getClassAndFunctionName(execute_data);
                ELOGF_CRITICAL(OTEL_GL(logger_), INSTRUMENTATION, "observerFcallBeginHandler. Unable to call prehook for 0x%X " PRsv "::" PRsv ": '%s'", hash, PRsvArg(cls), PRsvArg(func), e.what());
            }
        }
    }

    // Attribute-based WithSpan hook
    auto const *attrMeta = AttrHooksStorage::getInstance().find(hash);
    if (attrMeta) {
        callWithSpanHandlerPre(execute_data, *attrMeta);
    } else if (!callbacks) {
        auto [cls, func] = getClassAndFunctionName(execute_data);
        ELOGF_ERROR(OTEL_GL(logger_), INSTRUMENTATION, "Unable to find prehook handler for 0x%X " PRsv "::" PRsv, hash, PRsvArg(cls), PRsvArg(func));
    }
}

void observerFcallEndHandler(zend_execute_data *execute_data, zval *retval) {
    auto hash = getClassAndFunctionHashFromExecuteData(execute_data);
    ELOGF_TRACE(OTEL_GL(logger_), INSTRUMENTATION, "observerFcallEndHandler hash 0x%X", hash);

    auto callbacks = reinterpret_cast<InstrumentedFunctionHooksStorage_t *>(OTEL_GL(hooksStorage_).get())->find(hash);
    if (callbacks) {
        for (auto &callback : *callbacks) {
            try {
                AutomaticExceptionStateRestorer restorer;
                callPostHook(callback.second, retval, restorer.getException(), execute_data);
                handleAndReleaseHookException(EG(exception));
            } catch (std::exception const &e) {
                auto [cls, func] = getClassAndFunctionName(execute_data);
                ELOGF_CRITICAL(OTEL_GL(logger_), INSTRUMENTATION, "observerFcallEndHandler. Unable to call posthook for 0x%X " PRsv "::" PRsv ": '%s'", hash, PRsvArg(cls), PRsvArg(func), e.what());
            }
        }
    }

    // Attribute-based WithSpan hook
    auto const *attrMeta = AttrHooksStorage::getInstance().find(hash);
    if (attrMeta) {
        AutomaticExceptionStateRestorer restorer;
        callWithSpanHandlerPost(execute_data, retval, restorer.getException());
    } else if (!callbacks) {
        auto [cls, func] = getClassAndFunctionName(execute_data);
        ELOGF_ERROR(OTEL_GL(logger_), INSTRUMENTATION, "Unable to find posthook handler for 0x%X " PRsv "::" PRsv, hash, PRsvArg(cls), PRsvArg(func));
    }
}

zend_observer_fcall_handlers registerObserverHandlers(zend_execute_data *execute_data) {
    if (execute_data->func->common.type == ZEND_INTERNAL_FUNCTION) {
        return {nullptr, nullptr};
    }

    auto hash = getClassAndFunctionHashFromExecuteData(execute_data);
    if (hash == 0) {
        ELOGF_TRACE(OTEL_GL(logger_), INSTRUMENTATION, "registerObserverHandlers main scope");
        return {nullptr, nullptr};
    }

    auto callbacks = reinterpret_cast<InstrumentedFunctionHooksStorage_t *>(OTEL_GL(hooksStorage_).get())->find(hash);
    if (!callbacks) {
        if (OTEL_GL(logger_)->doesMeetsLevelCondition(LogLevel::logLevel_trace)) {
            auto [cls, func] = getClassAndFunctionName(execute_data);
            ELOGF_TRACE(OTEL_GL(logger_), INSTRUMENTATION, "registerObserverHandlers hash: 0x%X " PRsv "::" PRsv ", not marked to be instrumented", hash, PRsvArg(cls), PRsvArg(func));
        }

        // lookup for class interfaces
        auto ce = execute_data->func->common.scope;
        if (ce) {
            for (uint32_t i = 0; i < ce->num_interfaces; ++i) {
                std::string_view ifaceName{ZSTR_VAL(ce->interfaces[i]->name), ZSTR_LEN(ce->interfaces[i]->name)};
                std::string_view funcName{ZSTR_VAL(execute_data->func->common.function_name), ZSTR_LEN(execute_data->func->common.function_name)};
                zend_ulong ifaceHash = hashClassAndFunctionNameLowercase(ifaceName, funcName);

                callbacks = reinterpret_cast<InstrumentedFunctionHooksStorage_t *>(OTEL_GL(hooksStorage_).get())->find(ifaceHash);
                if (callbacks) {
                    if (OTEL_GL(logger_)->doesMeetsLevelCondition(LogLevel::logLevel_trace)) {
                        auto [cls, func] = getClassAndFunctionName(execute_data);
                        ELOGF_TRACE(OTEL_GL(logger_), INSTRUMENTATION, "registerObserverHandlers hash: 0x%X " PRsv "::" PRsv ", will be instrumented because interface 0x%X '" PRsv "' was marked to be instrumented", hash, PRsvArg(cls), PRsvArg(func), ifaceHash, PRzsArg(ce->interfaces[i]->name));
                    }
                    // copy callbacks from interface storage hash to implementation hash
                    for (auto &item : *callbacks) {
                        reinterpret_cast<InstrumentedFunctionHooksStorage_t *>(OTEL_GL(hooksStorage_).get())->store(hash, AutoZval(item.first.get()), AutoZval(item.second.get()));
                    }
                    break;
                }
            }
        }
    }

    if (OTEL_GL(config_)->get().debug_instrument_all && OTEL_GL(requestScope_)->isFunctional()) {
        std::string_view filename(ZSTR_VAL(execute_data->func->op_array.filename), ZSTR_LEN(execute_data->func->op_array.filename));
        if (!(execute_data->func->common.fn_flags & ZEND_ACC_CLOSURE) && filename.find("/opentelemetry/php/distro/") == std::string_view::npos && filename.find("/open-telemetry/") == std::string_view::npos) {
            auto preHookName = OTEL_GL(config_)->get().scoped_deps_enabled ? PHP_SCOPER_PREFIX "OpenTelemetry\\Distro\\PhpPartFacade::debugPreHook"sv : "OpenTelemetry\\Distro\\PhpPartFacade::debugPreHook"sv;
            auto postHookName = OTEL_GL(config_)->get().scoped_deps_enabled ? PHP_SCOPER_PREFIX "OpenTelemetry\\Distro\\PhpPartFacade::debugPostHook"sv : "OpenTelemetry\\Distro\\PhpPartFacade::debugPostHook"sv;
            callbacks = reinterpret_cast<InstrumentedFunctionHooksStorage_t *>(OTEL_GL(hooksStorage_).get())->storeFront(hash, AutoZval(preHookName), AutoZval(postHookName));
        }
    }

    // Attribute-based hooks: register if attr_hooks_enabled and #[WithSpan] is present.
    // Works independently of user hooks registered via hook().
    bool haveAttrHook = false;
    if (OTEL_GL(config_)->get().attr_hooks_enabled) {
        auto *func = execute_data->func;
        if (hasWithSpanAttribute(func)) {
            auto metaOpt = readWithSpanMetadata(func);
            if (metaOpt) {
                AttrHooksStorage::getInstance().store(hash, std::move(*metaOpt));
                haveAttrHook = true;
                ELOGF_DEBUG(OTEL_GL(logger_), INSTRUMENTATION, "registerObserverHandlers hash: 0x%X registered attribute hook (#[WithSpan])", hash);
            }
        }
    }

    if (!callbacks && !haveAttrHook) {
        return {nullptr, nullptr};
    }

    bool havePreHook = haveAttrHook;
    bool havePostHook = haveAttrHook;
    if (callbacks) {
        for (auto const &item : *callbacks) {
            if (!item.first.isNull()) {
                havePreHook = true;
            }
            if (!item.second.isNull()) {
                havePostHook = true;
            }
            if (havePreHook && havePostHook) {
                break;
            }
        }
    }
    ELOGF_TRACE(OTEL_GL(logger_), INSTRUMENTATION, "registerObserverHandlers hash: 0x%X, havePreHooks: %d havePostHooks: %d haveAttrHook: %d", hash, havePreHook, havePostHook, haveAttrHook);

    return {havePreHook ? observerFcallBeginHandler : nullptr, havePostHook ? observerFcallEndHandler : nullptr};
}



}