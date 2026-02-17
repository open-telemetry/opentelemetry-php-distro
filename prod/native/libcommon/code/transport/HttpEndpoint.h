#pragma once

#include "CommonUtils.h"
#include "HttpEndpointSSLOptions.h"

#include <chrono>
#include <string>
#include <string_view>
#include <curl/curl.h>

namespace opentelemetry::php::transport {

using namespace std::literals;

class HttpEndpoint {
public:
    using enpointHeaders_t = std::vector<std::pair<std::string_view, std::string_view>>;
    using connectionId_t = std::size_t;

    HttpEndpoint(HttpEndpoint &&) = delete;
    HttpEndpoint &operator=(HttpEndpoint &&) = delete;
    HttpEndpoint(HttpEndpoint const &) = delete;
    HttpEndpoint &operator=(HttpEndpoint const &) = delete;

    HttpEndpoint(std::string endpoint, std::string_view contentType, enpointHeaders_t const &headers, std::size_t maxRetries, std::chrono::milliseconds retryDelay) : endpoint_(std::move(endpoint)), maxRetries_(maxRetries), retryDelay_(retryDelay) {
        auto connectionDetails = utils::getConnectionDetailsFromURL(endpoint_);
        if (!connectionDetails) {
            std::string msg = "Unable to parse connection details from endpoint: "s;
            msg.append(endpoint_);
            throw std::runtime_error(msg);
        }
        connectionId_ = std::hash<std::string>{}(connectionDetails.value());

        fillCurlHeaders(contentType, headers);
    }

    ~HttpEndpoint() {
        if (curlHeaders_) {
            curl_slist_free_all(curlHeaders_);
            curlHeaders_ = nullptr;
        }
    }

    std::string const &getEndpoint() const {
        return endpoint_;
    }

    struct curl_slist *getHeaders() {
        return curlHeaders_;
    }

    connectionId_t getConnectionId() const {
        return connectionId_;
    }

    std::size_t getMaxRetries() const {
        return maxRetries_;
    }

    std::chrono::milliseconds getRetryDelay() const {
        return retryDelay_;
    }

    void setRetryDelay(std::chrono::milliseconds retryDelay) {
        retryDelay_ = retryDelay;
    }

private:
    void fillCurlHeaders(std::string_view contentType, enpointHeaders_t const &headers) {
        if (!contentType.empty()) {
            std::string cType = "Content-Type: "s;
            cType.append(contentType);
            curlHeaders_ = curl_slist_append(curlHeaders_, cType.c_str());
        }

        for (auto const &hdr : headers) {
            std::string header;
            header.append(hdr.first);
            header.append(": "sv);
            header.append(hdr.second);
            curlHeaders_ = curl_slist_append(curlHeaders_, header.c_str());
        }
    }

    std::string endpoint_;
    std::size_t maxRetries_ = 1;
    std::chrono::milliseconds retryDelay_ = 0ms;
    connectionId_t connectionId_;
    struct curl_slist *curlHeaders_ = nullptr;
    HttpEndpointSSLOptions sslOptions_;
};

} // namespace opentelemetry::php::transport