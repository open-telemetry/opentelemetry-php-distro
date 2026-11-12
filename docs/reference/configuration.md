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

### HTTP capture

These options are read by the root span (transaction span). They are standard OpenTelemetry-style settings and can be set as environment variables or in `php.ini`.

| Option | Default | Accepted values | Description |
| --- | --- | --- | --- |
| `OTEL_INSTRUMENTATION_HTTP_SERVER_CAPTURE_REQUEST_HEADERS` | (empty) | Comma-separated header names | Request headers recorded as `http.request.header.<name>` |
| `OTEL_INSTRUMENTATION_HTTP_SERVER_CAPTURE_RESPONSE_HEADERS` | (empty) | Comma-separated header names | Response headers recorded as `http.response.header.<name>` |
| `OTEL_PHP_INSTRUMENTATION_HTTP_SERVER_CAPTURE_REQUEST_BODY` | `false` | `true` or `false` | Records the request body as `http.request.body.content`. Only textual bodies are recorded (by `Content-Type`); binary and compressed bodies are skipped. The body may contain sensitive data. |
| `OTEL_PHP_INSTRUMENTATION_HTTP_SERVER_CAPTURE_REQUEST_BODY_MAX_SIZE` | `1024` | Number of bytes | Maximum recorded body size, cut on a UTF-8 character boundary |

### Attribute-based instrumentation

| Option | Default | Accepted values | Description |
| --- | --- | --- | --- |
| `OTEL_PHP_ATTR_HOOKS_ENABLED` | `false` | `true` or `false` | Enables `#[WithSpan]` / `#[SpanAttribute]` attribute-based span creation. See [Attribute-based instrumentation](attribute-instrumentation.md). |

### Scoped dependencies bridge

| Option | Default | Accepted values | Description |
| --- | --- | --- | --- |
| `OTEL_PHP_SCOPED_DEPS_BRIDGE_ENABLED` | `false` | `true` or `false` | Lets the application's own OpenTelemetry usage (instrumentation it writes itself, or officially published auto-instrumentation packages it installs on its own) share the distro's runtime (tracer provider, context) so its spans join the distro's traces. See [the note below](#scoped-dependencies-bridge-interop). |

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
| `OTEL_PHP_OPAMP_HEARTBEAT_INTERVAL` | 30s | Duration (`ms`, `s`, `m`) | The interval between heartbeat messages sent to the OpAMP server.|
| `OTEL_PHP_OPAMP_POLLING_INTERVAL`   | 30s | Duration (`ms`, `s`, `m`) | The interval at which the agent polls the OpAMP server for updated configuration. Independent from the heartbeat interval. |
| `OTEL_PHP_OPAMP_SEND_TIMEOUT` | `10s` | Duration (`ms`, `s`, `m`) | OpAMP send timeout |
| `OTEL_PHP_OPAMP_SEND_MAX_RETRIES` | `3` | Integer >= 0 | Retry count |
| `OTEL_PHP_OPAMP_SEND_RETRY_DELAY` | `10s` | Duration (`ms`, `s`, `m`) | Retry delay |
| `OTEL_PHP_OPAMP_INSECURE` | `false` | `true` or `false` | Disable TLS verification (testing only) |
| `OTEL_PHP_OPAMP_CERTIFICATE` | (empty) | Filesystem path (PEM) | CA certificate path for OpAMP TLS |
| `OTEL_PHP_OPAMP_CLIENT_CERTIFICATE` | (empty) | Filesystem path (PEM) | Client certificate path for OpAMP mTLS |
| `OTEL_PHP_OPAMP_CLIENT_KEY` | (empty) | Filesystem path (PEM) | Client key path for OpAMP mTLS |
| `OTEL_PHP_OPAMP_CLIENT_KEYPASS` | (empty) | String | Passphrase for encrypted client key |

### Supportability

| Option                         | Default | Accepted values  | Description                                                                                                                                    |
|--------------------------------|---------|------------------|------------------------------------------------------------------------------------------------------------------------------------------------|
| `OTEL_PHP_SCOPED_DEPS_ENABLED` | `true` | `true` or `false` | Control whether the scoped or not scoped (i.e., the original) dependencies should be used by the distro. For more details see the notes below. |

## Notes

- Background transfer works with OTLP HTTP/protobuf mode.
- `OTEL_PHP_AUTOLOAD_ENABLED` is enforced as enabled by the distro runtime.
- The distro package includes multiple dependencies such as OpenTelemetry SDK, various auto-instrumentation, their transitive dependencies, etc. It's possible that the monitored application includes dependencies that might clash with the ones in the distro package. This in turn might cause the application to malfunction. In order to prevent that the distro uses **scoped** dependencies by default. The scoping of dependencies' code achieved by adding a unique prefix to their namespaces. Since PHP runtime has runtime reflection changing namespaces theoretically might not be compatible with some corner cases code. In order to allow falling back to the original (i.e., not scoped) dependencies configuration option `scoped_deps_enabled` (`OTEL_PHP_SCOPED_DEPS_ENABLED` environment variable) can be set to `false`.

### Scoped dependencies bridge interop

By default the distro's OpenTelemetry runtime is **scoped** (see the note above): its classes live under a unique namespace prefix, separate from the standard `OpenTelemetry\*` classes an application would install via Composer. As a result, the application's own OpenTelemetry usage — instrumentation it writes itself, or officially published auto-instrumentation packages it installs on its own (for example `open-telemetry/opentelemetry-auto-pdo`), using the public `OpenTelemetry\API\*` / `OpenTelemetry\Context\*` / `OpenTelemetry\SDK\*` API and the `OpenTelemetry\Instrumentation\hook()` function — runs against a **separate** runtime with an empty context, so its spans are never connected to the distro's traces. If the application does not install `open-telemetry/sdk`, its spans use a no-op tracer provider and are not exported. If it does install the SDK, that SDK configures itself from the standard `OTEL_*` variables (the distro sets `OTEL_PHP_AUTOLOAD_ENABLED=true`), so its spans are exported, but as separate traces.

Setting `OTEL_PHP_SCOPED_DEPS_BRIDGE_ENABLED=true` bridges the two: before the application's Composer autoloader runs, the distro registers class aliases mapping the unscoped `OpenTelemetry\*` API onto its scoped implementation. The application's own OpenTelemetry usage then transparently uses the distro's tracer provider and context, so its spans are exported and correctly parented within the distro's traces.

Because the distro ships specific versions of the OpenTelemetry packages, when this option is enabled the distro checks at shutdown whether the application has installed different versions of `open-telemetry/api`, `open-telemetry/context`, or `open-telemetry/sdk`, and logs a warning for each mismatch (the distro's bundled version is the one actually used at runtime). If the versions differ significantly the shared runtime may behave unexpectedly; align the application's versions with the distro's to avoid this.

This option has no effect when scoping is disabled (`OTEL_PHP_SCOPED_DEPS_ENABLED=false`): without scoping the distro already uses the unscoped `OpenTelemetry\*` classes that the application uses, so its OpenTelemetry usage shares the runtime without any bridging.

**Known limitation: the application's own `open-telemetry/sdk` 1.16 or newer.** Since version 1.16 of `open-telemetry/sdk`, setting `OTEL_PHP_TRANSACTION_SPAN_ENABLED` (or `OTEL_PHP_TRANSACTION_SPAN_ENABLED_CLI`) to `true` makes every copy of the SDK in the process start a root span while Composer is still loading its files, which is before `open-telemetry/exporter-otlp` registers the `otlp` exporter. With the bridge disabled, the application's own SDK is then left without an exporter and its spans are lost, silently. The distro does not trigger this by itself: it enables the root span by default without exporting the variable to the process environment. Only a variable that you set yourself (in the environment or in `php.ini`) reaches the application's SDK. To avoid the problem, do not set these variables to `true` explicitly (`true` is already the default), enable the bridge (`OTEL_PHP_SCOPED_DEPS_BRIDGE_ENABLED=true`), or use an application SDK older than 1.16. The cause is the load order of the exporter registration in the application's own Composer autoloader, which the distro cannot change.

## File-based configuration (declarative)

As an alternative to environment variables, you can configure the SDK using a YAML configuration file by setting the `OTEL_CONFIG_FILE` environment variable:

```bash
export OTEL_CONFIG_FILE=/path/to/otel-config.yaml
```

When `OTEL_CONFIG_FILE` is set:

- The SDK reads all configuration from the YAML file instead of individual `OTEL_*` environment variables
- Environment variable substitution (`${MY_VAR:-default}`) is supported within the YAML file
- Central configuration (OpAMP) is automatically disabled — file-based and remote configuration are mutually exclusive
- Distro-specific options (`OTEL_PHP_*`) continue to work as they are native extension options, independent of the SDK
- The file must declare `file_format: "1.0"`. The `1.0-rc.*` and `0.x` formats are rejected
- `OTEL_CONFIG_FILE` names exactly one file (a comma-separated list is no longer split)

### Distro resource detector

The distro provides a `distro` resource detector that adds `telemetry.distro.name` and `telemetry.distro.version` resource attributes. To activate it in file-based configuration, add it to the `resource.detection/development.detectors` section:

```yaml
file_format: "1.0"

resource:
  attributes:
    - name: service.name
      value: my-service
  detection/development:
    detectors:
      - distro: {}

propagator:
  composite:
    - tracecontext:
    - baggage:

tracer_provider:
  processors:
    - batch:
        exporter:
          otlp_http:
            endpoint: http://localhost:4318/v1/traces

meter_provider:
  readers:
    - periodic:
        exporter:
          otlp_http:
            endpoint: http://localhost:4318/v1/metrics

logger_provider:
  processors:
    - batch:
        exporter:
          otlp_http:
            endpoint: http://localhost:4318/v1/logs
```

For the full YAML schema, see the [OpenTelemetry Configuration Schema](https://github.com/open-telemetry/opentelemetry-configuration/blob/main/schema-docs.md).

### Limitations

- Central configuration (OpAMP) is not available when file-based configuration is active.
- Resource detectors registered via `Registry::registerResourceDetector()` (e.g., cloud provider detectors from `opentelemetry-php-contrib`) are not automatically active. They must provide a `ComponentProvider` and be explicitly listed in the YAML `resource.detection/development.detectors` section.
