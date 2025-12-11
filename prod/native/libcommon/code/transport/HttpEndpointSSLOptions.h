#pragma once

#include <string>

namespace opentelemetry::php::transport {

struct HttpEndpointSSLOptions {
    bool insecureSkipVerify = false;
    std::string caInfo;
    std::string cert;
    std::string certKey;
    std::string certKeyPassword;
};

}