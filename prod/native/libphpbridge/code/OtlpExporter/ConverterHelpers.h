#pragma once

#include "AutoZval.h"
#include "CiCharTraits.h"

#include <string>
#include <string_view>

namespace opentelemetry::php {

class ConverterHelpers {
public:
    static std::string php_serialize_zval(zval *zv) {
        zval retval, fname;
        ZVAL_STRING(&fname, "serialize");
        if (call_user_function(EG(function_table), nullptr, &fname, &retval, 1, zv) != SUCCESS || Z_TYPE(retval) != IS_STRING) {
            zval_ptr_dtor(&fname);
            return {};
        }
        std::string result(Z_STRVAL(retval), Z_STRLEN(retval));
        zval_ptr_dtor(&fname);
        zval_ptr_dtor(&retval);
        return result;
    }

    static std::string getResourceId(AutoZval const &resourceInfo) {
        using namespace std::string_view_literals;
        auto schemaUrl = resourceInfo.callMethod("getSchemaUrl"sv);
        auto attributes = resourceInfo.callMethod("getAttributes"sv);
        auto dropped = attributes.callMethod("getDroppedAttributesCount"sv);
        auto attributesArray = attributes.callMethod("toArray"sv);
        // auto schemaUrl = resourceInfo.readProperty("schemaUrl"sv);
        // auto attributes = resourceInfo.readProperty("attributes"sv);
        // auto attributesArray = attributes.readProperty("attributes"sv);
        // auto dropped = attributes.readProperty("droppedAttributesCount"sv);

        AutoZval toSerialize;
        toSerialize.arrayInit();
        toSerialize.arrayAddNextWithRef(schemaUrl);
        toSerialize.arrayAddNextWithRef(attributesArray);
        toSerialize.arrayAddNextWithRef(dropped);

        return php_serialize_zval(toSerialize.get());
    }

    static std::string getScopeId(AutoZval const &scopeInfo) {
        using namespace std::string_view_literals;
        auto name = scopeInfo.callMethod("getName"sv);
        auto version = scopeInfo.callMethod("getVersion"sv);
        auto schemaUrl = scopeInfo.callMethod("getSchemaUrl"sv);
        auto attributes = scopeInfo.callMethod("getAttributes"sv);
        auto dropped = attributes.callMethod("getDroppedAttributesCount"sv);
        auto attributesArray = attributes.callMethod("toArray"sv);
        // auto name = scopeInfo.readProperty("name");
        // auto version = scopeInfo.readProperty("version");
        // auto schemaUrl = scopeInfo.readProperty("schemaUrl");
        // auto attributes = scopeInfo.readProperty("attributes");
        // auto attributesArray = attributes.readProperty("attributes"sv);
        // auto dropped = attributes.readProperty("droppedAttributesCount"sv);

        AutoZval toSerialize;
        toSerialize.arrayInit();
        toSerialize.arrayAddNextWithRef(name);
        toSerialize.arrayAddNextWithRef(version);
        toSerialize.arrayAddNextWithRef(schemaUrl);
        toSerialize.arrayAddNextWithRef(attributesArray);
        toSerialize.arrayAddNextWithRef(dropped);

        return php_serialize_zval(toSerialize.get());
    }
};

} // namespace opentelemetry::php
