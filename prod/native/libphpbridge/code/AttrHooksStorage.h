#pragma once

#include "WithSpanAttributes.h"

#include <Zend/zend_types.h>
#include <functional>
#include <memory>
#include <unordered_map>

namespace opentelemetry::php {

// Per-process singleton mapping a function to the WithSpanMetadata of its #[WithSpan] attribute (attribute-based
// instrumentation).
//
// The key is the opcodes array of the function together with its class scope, not the name of the class and function
// and not the address of the zend_function:
//   - names are not unique: before PHP 8.4 every closure is called {closure} and has no class, so all of them would
//     share one entry;
//   - the address is not stable: $obj->method(...), Closure::fromCallable() and function(...) call a copy of the
//     zend_function that shares the run-time cache of the original, so the observer handlers are chosen once for the
//     original and the copies must find the same entry. All copies share the opcodes of their declaration;
//   - the scope tells apart the copies of one trait method in different classes, which share the opcodes as well but
//     can have different attributes (inherited from different interfaces) and resolve constants in their own class;
//   - the name tells apart the aliases of one trait method in the same class (use T { foo as bar; }), which share both
//     the opcodes and the scope. It is the hash of the name, not the string itself: an entry of a freed function
//     stays in the map until the end of the request and must not point at memory that is gone. The copies of one
//     function share the name, so the callable copies above still find the entry.
//
// Lifecycle: an entry is stored by registerObserverHandlers(), which runs on the first call of a function within each
// request (zend_activate() resets the run_time_cache, including the observer install slot, at the start of every
// request), and it is looked up by the observer handlers of that same function. A zend_function can be freed and its
// address reused by another function, a closure object for example, so:
//   - registerObserverHandlers() must erase() the entry of a function that has no #[WithSpan], and
//   - the storage is cleared at the end of every request, no entry outlives the functions of the request.
// WithSpanMetadata holds no PHP-owned memory (every field is a deep copy into std::string/std::vector, see
// WithSpanAttributes.cpp), so clearing is just freeing C++ memory.
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

    // Returns nullptr if not found. The caller keeps the metadata alive while it uses it, even if the entry is
    // replaced or erased meanwhile (evaluating attribute arguments can run userland code that calls other functions).
    std::shared_ptr<const WithSpanMetadata> find(const zend_function *func) const {
        auto it = storage_.find(keyOf(func));
        if (it == storage_.end()) {
            return nullptr;
        }
        return it->second;
    }

    // For the callers that only need to know, it does not touch the reference count.
    bool contains(const zend_function *func) const {
        return storage_.find(keyOf(func)) != storage_.end();
    }

    // Always overwrites any existing entry for this function, it can only be a stale one.
    void store(const zend_function *func, WithSpanMetadata meta) {
        storage_[keyOf(func)] = std::make_shared<const WithSpanMetadata>(std::move(meta));
    }

    void erase(const zend_function *func) {
        storage_.erase(keyOf(func));
    }

    void clear() {
        storage_.clear();
    }

private:
    AttrHooksStorage() = default;

    struct Key {
        const void *opcodes;
        const void *scope;
        zend_ulong nameHash;

        bool operator==(Key const &) const = default;
    };

    struct KeyHash {
        size_t operator()(Key const &key) const {
            size_t h = std::hash<const void *>{}(key.opcodes);
            h = h * 31 + std::hash<const void *>{}(key.scope);
            return h * 31 + std::hash<zend_ulong>{}(key.nameHash);
        }
    };

    // user functions only, which are the only ones that carry #[WithSpan]
    static Key keyOf(const zend_function *func) {
        if (func->type != ZEND_USER_FUNCTION) {
            return {nullptr, nullptr, 0};
        }
        zend_string *name = func->common.function_name;
        return {func->op_array.opcodes, func->common.scope, name ? ZSTR_HASH(name) : 0};
    }

    std::unordered_map<Key, std::shared_ptr<const WithSpanMetadata>, KeyHash> storage_;
};

} // namespace opentelemetry::php
