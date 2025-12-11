#pragma once

#include <functional>
#include <memory>
#include <set>

namespace opentelemetry::php {

class LoggerInterface;
class PhpBridgeInterface;

class DependencyAutoLoaderGuard {
public:
    DependencyAutoLoaderGuard(std::shared_ptr<PhpBridgeInterface> bridge, std::shared_ptr<LoggerInterface> logger) : bridge_(std::move(bridge)), logger_(std::move(logger)) {
    }

    void setBootstrapPath(std::string_view bootstrapFilePath);

    void onRequestInit() {
        clear();
    }

    void onRequestShutdown() {
        clear();
    }

    bool shouldDiscardFileCompilation(std::string_view fileName);

private:
    bool wasDeliveredByDistro(std::string_view fileName) const;

    void clear() {
        lastClass_ = 0;
        lastFunction_ = 0;
        compiledFiles_.clear();
    }

private:
    std::shared_ptr<PhpBridgeInterface> bridge_;
    std::shared_ptr<LoggerInterface> logger_;
    std::set<std::string_view> compiledFiles_; // string_view is safe because we're removing data on request end, they're request scope safe

    std::size_t lastClass_ = 0;
    std::size_t lastFunction_ = 0;

    std::string vendorPath_;
};
} // namespace opentelemetry::php