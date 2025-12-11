#pragma once


#include <string_view>
#include <stdexcept>
#include <Zend/zend_string.h>


namespace opentelemetry::php {

class AutoZendString {
public:
    AutoZendString(const AutoZendString&) = delete;
    AutoZendString& operator=(const AutoZendString&) = delete;
    AutoZendString() = delete;

    template<typename T, std::enable_if_t< std::is_convertible<T, std::string_view>::value, bool> = true >
    AutoZendString(T value) {
        std::string_view data(value);
        value_ = zend_string_init(data.data(), data.length(), 0);
    }

    // WARNING: doesn't add reference
    AutoZendString(zend_string *value) {
        value_ = value;
    }

    ~AutoZendString() {
        if (!value_) {
            return;
        }
        zend_string_release(value_);
    }

    zend_string *get() {
        return value_;
    }

private:
    zend_string *value_ = nullptr;
};


}