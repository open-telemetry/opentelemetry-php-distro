#include "ModuleFunctionsImpl.h"
#include "ConfigurationManager.h"
#include "ConfigurationStorage.h"

#include <php.h>
#include "ModuleGlobals.h"

namespace opentelemetry::php {
extern opentelemetry::php::ConfigurationManager configManager;

void getConfigOptionbyName(std::string_view optionName, zval *return_value) {
    auto value = opentelemetry::php::configManager.getOptionValue(optionName, OTEL_GL(config_)->get());

    std::visit([return_value](auto &&arg) {
        using T = std::decay_t<decltype(arg)>;
        if constexpr (std::is_same_v<T, std::chrono::milliseconds>) {
            ZVAL_DOUBLE(return_value, arg.count());
            return;
        } else if constexpr (std::is_same_v<T, LogLevel>) {
            ZVAL_LONG(return_value, arg);
            return;
        } else if constexpr (std::is_same_v<T, bool>) {
            if (arg) {
                ZVAL_TRUE(return_value);
            } else {
                ZVAL_FALSE(return_value);
            }
            return;
        } else if constexpr (std::is_same_v<T, std::string>) {
            ZVAL_STRINGL(return_value, arg.c_str(), arg.length());
            return;
        } else if constexpr (std::is_same_v<T, std::size_t>) {
            ZVAL_LONG(return_value, arg);
            return;
        } else {
            ZVAL_NULL(return_value);
        }
    }, value);
}

}