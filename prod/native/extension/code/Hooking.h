#pragma once

#include <Zend/zend_execute.h>
#include <Zend/zend_types.h>

namespace opentelemetry::php {

class Hooking {
public:
    using zend_execute_internal_t = void (*)(zend_execute_data *execute_data, zval *return_value);
    using zend_interrupt_function_t = void (*)(zend_execute_data *execute_data);
    using zend_compile_file_t = zend_op_array *(*)(zend_file_handle *file_handle, int type);

    static Hooking &getInstance() {
        static Hooking instance;
        return instance;
    }

    void fetchOriginalHooks() {
        original_execute_internal_ = zend_execute_internal;
        original_zend_interrupt_function_ = zend_interrupt_function;
        original_zend_compile_file_ = zend_compile_file;
    }

    void restoreOriginalHooks() {
        zend_execute_internal = original_execute_internal_;
        zend_interrupt_function = original_zend_interrupt_function_;
        zend_compile_file = original_zend_compile_file_;
    }

    zend_execute_internal_t getOriginalExecuteInternal() {
        return original_execute_internal_;
    }

    zend_interrupt_function_t getOriginalZendInterruptFunction() {
        return original_zend_interrupt_function_;
    }

    zend_compile_file_t getOriginalZendCompileFile() {
        return original_zend_compile_file_;
    }

    void replaceHooks(bool enableInferredSpansHooks, bool enableDepenecyAutoloaderGuard);

private:
    Hooking(Hooking const &) = delete;
    void operator=(Hooking const &) = delete;
    Hooking() = default;

    zend_execute_internal_t original_execute_internal_ = nullptr;
    zend_interrupt_function_t original_zend_interrupt_function_ = nullptr;
    zend_compile_file_t original_zend_compile_file_ = nullptr;
};

} // namespace opentelemetry::php