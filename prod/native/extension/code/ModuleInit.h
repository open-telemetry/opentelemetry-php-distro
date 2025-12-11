#pragma once

namespace opentelemetry::php {
    void moduleInit( int moduleType, int moduleNumber );
    void moduleShutdown( int moduleType, int moduleNumber );
} // namespace opentelemetry::php