#pragma once

#include "ForkableInterface.h"

#include <memory>
#include <vector>

namespace opentelemetry::php {

/**
 * @class ForkableRegistry
 * @brief A registry for managing fork-aware objects in a PHP extension.
 *
 * This class is NOT thread-safe. Registration of forkable objects should only be performed
 * during extension initialization or within the Zend Engine's main execution thread (the primary PHP thread).
 * Attempting to register forkables from other threads may lead to unpredictable behavior and race conditions.
 *
 * The deliberate absence of mutex protection is intentional: adding synchronization primitives could
 * introduce deadlock scenarios during process forking. Specifically, if another thread holds a mutex
 * lock during the pre-fork phase, the child process would inherit a locked mutex that can never be
 * unlocked (since the thread that locked it doesn't exist in the child process), resulting in a deadlock.
 *
 * @note Thread Safety: This class is not thread-safe by design. All operations must be performed
 *       from the main PHP thread.
 * @note Fork Safety: The class provides pre-fork and post-fork hooks to ensure proper state
 *       management across process boundaries.
 */
class ForkableRegistry {
public:
    ForkableRegistry() = default;
    ~ForkableRegistry() = default;

    ForkableRegistry(const ForkableRegistry &) = delete;
    ForkableRegistry &operator=(const ForkableRegistry &) = delete;
    ForkableRegistry(ForkableRegistry &&) = delete;
    ForkableRegistry &operator=(ForkableRegistry &&) = delete;

    void registerForkable(std::shared_ptr<ForkableInterface> forkable) {
        forkables_.emplace_back(std::move(forkable));
    }

    void preFork() {
        for (const auto &forkable : forkables_) {
            if (forkable) {
                forkable->prefork();
            }
        }
    }

    void postFork(bool isChild) {
        for (const auto &forkable : forkables_) {
            if (forkable) {
                forkable->postfork(isChild);
            }
        }
    }

    void clear() {
        forkables_.clear();
    }

private:
    std::vector<std::shared_ptr<ForkableInterface>> forkables_;
};

}
