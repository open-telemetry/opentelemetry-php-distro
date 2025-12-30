#pragma once

#include "ForkableInterface.h"

#include <memory>
#include <vector>

namespace opentelemetry::php {

class ForkableRegistry {
public:
    void registerForkable(std::shared_ptr<ForkableInterface> forkable) {
        forkables_.emplace_back(std::move(forkable));
    }

    void preFork() {
        for (const auto &forkable : forkables_) {
            forkable->prefork();
        }
    }

    void postFork(bool isChild) {
        for (const auto &forkable : forkables_) {
            forkable->postfork(isChild);
        }
    }

    std::vector<std::shared_ptr<ForkableInterface>> forkables_;
};

}
