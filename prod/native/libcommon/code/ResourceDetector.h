#pragma once

#include "CommonUtils.h"
#include "PhpBridgeInterface.h"

#include <map>
#include <string>
#include <string_view>
#include <cstring>

using namespace std::literals;

namespace opentelemetry::php {

class ResourceDetector {
public:
    static constexpr const char *OTEL_RESOURCE_ATTRIBUTES = "OTEL_RESOURCE_ATTRIBUTES";
    static constexpr const char *OTEL_SERVICE_NAME = "OTEL_SERVICE_NAME";

    ResourceDetector(std::shared_ptr<opentelemetry::php::PhpBridgeInterface> bridge) : bridge_(std::move(bridge)) {
        getFromEnvironment();
        getHostAndOsAttributes();
    }

    std::string get(std::string const &key) {
        if (auto search = resourceAttributes_.find(key); search != resourceAttributes_.end()) {
            return search->second;
        }
        return {};
    }

    std::map<std::string, std::string>::const_iterator cbegin() const noexcept {
        return resourceAttributes_.begin();
    }

    std::map<std::string, std::string>::const_iterator cend() const noexcept {
        return resourceAttributes_.end();
    }

    std::map<std::string, std::string>::iterator begin() noexcept {
        return resourceAttributes_.begin();
    }

    std::map<std::string, std::string>::iterator end() noexcept {
        return resourceAttributes_.end();
    }

protected:
    void getFromEnvironment();
    void getHostAndOsAttributes();

private:
    std::shared_ptr<opentelemetry::php::PhpBridgeInterface> bridge_;
    std::map<std::string, std::string> resourceAttributes_;
};
} // namespace opentelemetry::php
