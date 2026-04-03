# Configuration

OpenTelemetry PHP Distro supports standard OpenTelemetry SDK configuration and distro-specific options.

## Configuration method

Configure via environment variables available to PHP processes:

- `OTEL_*` for OpenTelemetry standard options
- `OTEL_PHP_*` for distro-specific options

Example:

```bash
export OTEL_EXPORTER_OTLP_ENDPOINT="https://your-endpoint:443/"
export OTEL_EXPORTER_OTLP_HEADERS="Authorization=Bearer <token>"
export OTEL_PHP_LOG_LEVEL_STDERR="INFO"
```

## OpenTelemetry options

The distro supports standard OpenTelemetry PHP SDK options.

| Option | Default | Accepted values | Description |
| --- | --- | --- | --- |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | `http://localhost:4318` | URL | OTLP endpoint URL |
| `OTEL_EXPORTER_OTLP_HEADERS` | (empty) | `key=value,key2=value2` | OTLP request headers |
| `OTEL_EXPORTER_OTLP_INSECURE` | `false` | `true` or `false` | Disable TLS verification (testing only) |
| `OTEL_EXPORTER_OTLP_CERTIFICATE` | (empty) | Filesystem path (PEM) | CA certificate path for OTLP TLS |
| `OTEL_EXPORTER_OTLP_CLIENT_CERTIFICATE` | (empty) | Filesystem path (PEM) | Client certificate for OTLP mTLS |
| `OTEL_EXPORTER_OTLP_CLIENT_KEY` | (empty) | Filesystem path (PEM) | Client key for OTLP mTLS |
| `OTEL_EXPORTER_OTLP_CLIENT_KEYPASS` | (empty) | String | Passphrase for encrypted OTLP client key |
| `OTEL_SERVICE_NAME` | `unknown_service` | String | Value of `service.name` resource attribute |
| `OTEL_RESOURCE_ATTRIBUTES` | (empty) | `key=value,key2=value2` | Resource attributes |
| `OTEL_TRACES_SAMPLER` | `parentbased_always_on` | Sampler name | Trace sampler |
| `OTEL_TRACES_SAMPLER_ARG` | (empty) | String/number | Sampler argument |
| `OTEL_LOG_LEVEL` | `info` | `error`, `warn`, `info`, `debug` | SDK internal log level |

## Distro-specific options (`OTEL_PHP_*`)

All `OTEL_PHP_*` options can be set as environment variables or in `php.ini`.

For `php.ini`, use the `opentelemetry_distro.` prefix and lowercase option names.

Example:

```bash
export OTEL_PHP_ENABLED=true
```

```ini
opentelemetry_distro.enabled=true
```

### General configuration

| Option | Default | Accepted values | Description |
| --- | --- | --- | --- |
| `OTEL_PHP_ENABLED` | `true` | `true` or `false` | Enables automatic bootstrap |
| `OTEL_PHP_OPENTELEMETRY_EXTENSION_EMULATION_ENABLED` | `true` | `true` or `false` | Enables registration of an emulated `opentelemetry` extension, allowing auto-instrumentations to work without `opentelemetry.so` |
| `OTEL_PHP_NATIVE_OTLP_SERIALIZER_ENABLED` | `true` | `true` or `false` | Enables native OTLP protobuf serializer |

### Asynchronous data sending

| Option | Default | Accepted values | Description |
| --- | --- | --- | --- |
| `OTEL_PHP_ASYNC_TRANSPORT` | `true` | `true` or `false` | Enables background transfer of telemetry |
| `OTEL_PHP_ASYNC_TRANSPORT_SHUTDOWN_TIMEOUT` | `30s` | Duration (`ms`, `s`, `m`) | Flush timeout at shutdown |
| `OTEL_PHP_MAX_SEND_QUEUE_SIZE` | `2MB` | Integer with optional `B`, `MB`, `GB` | Max async buffer size per worker |

### Logging

| Option | Default | Accepted values | Description |
| --- | --- | --- | --- |
| `OTEL_PHP_LOG_FILE` | (empty) | Filesystem path | Log output file path |
| `OTEL_PHP_LOG_LEVEL_FILE` | `OFF` | `OFF`, `CRITICAL`, `ERROR`, `WARNING`, `INFO`, `DEBUG`, `TRACE` | File sink log level |
| `OTEL_PHP_LOG_LEVEL_STDERR` | `OFF` | `OFF`, `CRITICAL`, `ERROR`, `WARNING`, `INFO`, `DEBUG`, `TRACE` | Stderr sink log level |
| `OTEL_PHP_LOG_LEVEL_SYSLOG` | `OFF` | `OFF`, `CRITICAL`, `ERROR`, `WARNING`, `INFO`, `DEBUG`, `TRACE` | Syslog sink log level |
| `OTEL_PHP_LOG_FEATURES` | (empty) | `FEATURE=LEVEL,...` | Per-feature log levels |

### Transaction span

| Option | Default | Accepted values | Description |
| --- | --- | --- | --- |
| `OTEL_PHP_TRANSACTION_SPAN_ENABLED` | `true` | `true` or `false` | Auto root span for web SAPI |
| `OTEL_PHP_TRANSACTION_SPAN_ENABLED_CLI` | `true` | `true` or `false` | Auto root span for CLI |
| `OTEL_PHP_TRANSACTION_URL_GROUPS` | (empty) | Comma-separated wildcards | URL grouping patterns |

### Inferred spans

| Option | Default | Accepted values | Description |
| --- | --- | --- | --- |
| `OTEL_PHP_INFERRED_SPANS_ENABLED` | `false` | `true` or `false` | Enables inferred spans |
| `OTEL_PHP_INFERRED_SPANS_REDUCTION_ENABLED` | `true` | `true` or `false` | Reduces consecutive duplicate frames |
| `OTEL_PHP_INFERRED_SPANS_STACKTRACE_ENABLED` | `true` | `true` or `false` | Attaches stacktrace to inferred spans |
| `OTEL_PHP_INFERRED_SPANS_SAMPLING_INTERVAL` | `50ms` | Duration (`ms`, `s`, `m`) | Stacktrace sampling interval |
| `OTEL_PHP_INFERRED_SPANS_MIN_DURATION` | `0` | Duration (`ms`, `s`, `m`) | Minimum inferred span duration |

### Central configuration (OpAMP)

| Option | Default | Accepted values | Description |
| --- | --- | --- | --- |
| `OTEL_PHP_OPAMP_ENDPOINT` | (empty) | HTTP/HTTPS URL ending with `/v1/opamp` | OpAMP endpoint |
| `OTEL_PHP_OPAMP_HEADERS` | (empty) | `key=value,key2=value2` | OpAMP request headers |
| `OTEL_PHP_OPAMP_HEARTBEAT_INTERVAL` | `30s` | Duration (`ms`, `s`, `m`) | OpAMP heartbeat interval |
| `OTEL_PHP_OPAMP_SEND_TIMEOUT` | `10s` | Duration (`ms`, `s`, `m`) | OpAMP send timeout |
| `OTEL_PHP_OPAMP_SEND_MAX_RETRIES` | `3` | Integer >= 0 | Retry count |
| `OTEL_PHP_OPAMP_SEND_RETRY_DELAY` | `10s` | Duration (`ms`, `s`, `m`) | Retry delay |
| `OTEL_PHP_OPAMP_INSECURE` | `false` | `true` or `false` | Disable TLS verification (testing only) |
| `OTEL_PHP_OPAMP_CERTIFICATE` | (empty) | Filesystem path (PEM) | CA certificate path for OpAMP TLS |
| `OTEL_PHP_OPAMP_CLIENT_CERTIFICATE` | (empty) | Filesystem path (PEM) | Client certificate path for OpAMP mTLS |
| `OTEL_PHP_OPAMP_CLIENT_KEY` | (empty) | Filesystem path (PEM) | Client key path for OpAMP mTLS |
| `OTEL_PHP_OPAMP_CLIENT_KEYPASS` | (empty) | String | Passphrase for encrypted client key |

## Notes

- Background transfer works with OTLP HTTP/protobuf mode.
- `OTEL_PHP_AUTOLOAD_ENABLED` is enforced as enabled by the distro runtime.
