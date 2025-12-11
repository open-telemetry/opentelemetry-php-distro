#pragma once

#include "LoggerInterface.h"
#include "transport/HttpEndpointSSLOptions.h"

#include <chrono>
#include <functional>
#include <memory>
#include <string>
#include <string_view>
#include <vector>
#include <curl/curl.h>

#include <boost/noncopyable.hpp>

namespace opentelemetry::php::transport {

class CurlSender {
public:
    CurlSender(std::shared_ptr<LoggerInterface> logger, std::chrono::milliseconds timeout, HttpEndpointSSLOptions const &sslOptions);

    CurlSender(CurlSender &&) = delete;
    CurlSender &operator=(CurlSender &&) = delete;
    CurlSender(CurlSender const &) = delete;
    CurlSender &operator=(CurlSender const &) = delete;

    ~CurlSender() {
        if (handle_) {
            curl_easy_cleanup(handle_);
        }
    }

    int16_t sendPayload(std::string const &endpointUrl, struct curl_slist *headers, std::vector<std::byte> const &payload, std::function<void(std::string_view)> headerCallback, std::string *responseBuffer = nullptr) const;

private:
    CURL *handle_ = nullptr;
    std::shared_ptr<LoggerInterface> log_;
};

} // namespace opentelemetry::php::transport