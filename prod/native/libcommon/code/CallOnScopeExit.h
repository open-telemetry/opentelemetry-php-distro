#pragma once

#include <utility>

namespace opentelemetry::utils {

template<typename T>
class callOnScopeExit {
public:
    callOnScopeExit(T fn) : fn_(std::move(fn)) {
    }
    ~callOnScopeExit() {
        fn_();
    }
private:
    T fn_;
};

}
