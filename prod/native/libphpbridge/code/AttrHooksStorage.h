#pragma once

#include "WithSpanAttributes.h"

#include <Zend/zend_types.h>
#include <unordered_map>

namespace opentelemetry::php {

// Per-process singleton mapping function hash → WithSpanMetadata for
// attribute-based (#[WithSpan]) instrumentation.
//
// Lifecycle: populated from registerObserverHandlers(), which re-runs on the first call of a
// given function within each request (zend_activate() resets the map-pointer-backed run_time_cache,
// including the observer install slot, at the start of every request - it is NOT a once-per-process
// thing). Unlike hooksStorage_, this is never cleared at request shutdown: WithSpanMetadata holds
// no PHP-owned memory (every field is a deep copy into std::string/std::vector, see
// WithSpanAttributes.cpp) so nothing here depends on PHP's per-request memory pool - it's safe to
// keep across requests purely as a cache to avoid re-parsing attributes every time.
//
// store() unconditionally overwrites rather than no-op'ing on an existing key: the hash depends
// only on the (lowercased) class+function name, not on which compiled version of the code defined
// it. Live code deploys under a warm worker (opcache re-detects the changed file, recompiles a new
// op_array, and registerObserverHandlers() re-reads the now-current #[WithSpan] attribute) must be
// able to replace a stale cached value, not have it permanently rejected by an emplace() no-op -
// otherwise a changed span_name/attributes would stay stuck until the worker process recycles.
//
// TODO: ZTS — concurrent store() and find() are not thread-safe.
//       Add std::shared_mutex when ZTS support is needed (same caveat as
//       InternalFunctionInstrumentationStorage).
class AttrHooksStorage {
public:
    static AttrHooksStorage &getInstance() {
        static AttrHooksStorage instance_;
        return instance_;
    }

    // Returns nullptr if not found.
    const WithSpanMetadata *find(zend_ulong hash) const {
        auto it = storage_.find(hash);
        if (it == storage_.end()) {
            return nullptr;
        }
        return &it->second;
    }

    // Always overwrites any existing entry for this hash - see class comment above for why a
    // no-op-if-present (emplace) semantics would be wrong here.
    void store(zend_ulong hash, WithSpanMetadata meta) {
        storage_[hash] = std::move(meta);
    }

private:
    AttrHooksStorage() = default;
    std::unordered_map<zend_ulong, WithSpanMetadata> storage_;
};

} // namespace opentelemetry::php
