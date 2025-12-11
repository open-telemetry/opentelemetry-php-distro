#pragma once

#include <google/protobuf/repeated_ptr_field.h>
#include <string>

namespace opentelemetry::php::common {

template <typename KeyValue, typename ValueType>
void addKeyValue(google::protobuf::RepeatedPtrField<KeyValue> *map, std::string key, ValueType const &value) {
    auto kv = map->Add();
    kv->set_key(std::move(key));
    auto val = kv->mutable_value();
    if constexpr (std::is_same_v<decltype(value), bool>) {
        val->set_bool_value(value);
    } else if constexpr (std::is_floating_point_v<std::remove_reference_t<decltype(value)>>) {
        val->set_double_value(value);
    } else if constexpr (!std::is_null_pointer_v<std::remove_reference_t<decltype(value)>> && std::is_convertible_v<decltype(value), std::string_view>) {
        val->set_string_value(value);
    } else {
        val->set_int_value(value);
    }
}

} // namespace opentelemetry::php::common