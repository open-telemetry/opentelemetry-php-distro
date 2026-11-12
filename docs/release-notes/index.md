## Unreleased

### This release is based on the following OpenTelemetry PHP packages:

- [open-telemetry/api 1.11.0](https://packagist.org/packages/open-telemetry/api#1.11.0)
- [open-telemetry/sdk 1.16.1](https://packagist.org/packages/open-telemetry/sdk#1.16.1)
- [open-telemetry/context 1.6.0](https://packagist.org/packages/open-telemetry/context#1.6.0)

### Breaking changes

- **PHP 8.1 is no longer supported.** Supported PHP versions are now 8.2 to 8.5. `open-telemetry/sdk` 1.16, `api` 1.11, `context` 1.6, `exporter-otlp` 1.5 and `sdk-configuration` 0.10 require PHP 8.2. Users on PHP 8.1 should stay on the 0.7.x release.
- **Declarative configuration (`OTEL_CONFIG_FILE`)** follows `sdk-configuration` 0.10:
  - the file must declare `file_format: "1.0"`; the `1.0-rc.*` and `0.x` formats are rejected (update files that use `1.0-rc.2`),
  - `OTEL_CONFIG_FILE` names exactly one file,
  - the schema changes of configuration 1.0 apply, for example `disabled` became `enabled` in the tracer, meter and logger configurators, OTLP TLS settings moved under `tls`, `lowmemory` became `low_memory`, the `zipkin` exporter was removed, and an omitted `tracer_provider`, `meter_provider` or `logger_provider` is now a noop provider.
  - See the `sdk-configuration` 0.10.0 release notes for the full list.
- **HTTP export retries** in the SDK transport now only cover 429, 502, 503 and 504 (it used to retry 408, 429 and every 5xx). The distro's native OTLP transport keeps its own retry policy.
- `open-telemetry/exporter-otlp` 1.5.0 requires `gen-otlp-protobuf` 1.11.1 (OTLP proto v1.11.1, up from v1.8.0) and `google/protobuf` 4.31. If `ext-protobuf` is installed it must be 4.31 or newer.
- Code that extends or replaces the OTLP exporters must follow `exporter-otlp` 1.5.0: `ProtobufSerializer` no longer defines the `SUPPORTED_CONTENT_TYPES` type alias (use `ContentTypes::*`), and `MetricExporter` accepts an `AggregationTemporalitySelectorInterface` as its temporality argument.

### What's changed

- chore: update open-telemetry packages (SDK 1.16.1, API 1.11.0, exporter-otlp 1.5.0, sdk-configuration 0.10.0, sem-conv 1.45.0 and the auto-instrumentation packages)
- fix: the distro's OTLP exporters (`Contrib/Otlp`) are compatible with `exporter-otlp` 1.5.0; without this the OTLP metric exporter could not be created
- feat: native OTLP exporters aligned with `exporter-otlp` 1.5.0 (histogram `min`/`max` are not exported when they were not recorded), native semantic conventions 1.45.0 and OTLP proto v1.11.1

### Upstream package highlights

- **opentelemetry-sdk 1.16.1** ([sdk 1.15.0→1.16.1](https://github.com/opentelemetry-php/sdk/compare/1.15.0...1.16.1)):
  - fix (1.16.1): the host resource detector no longer runs `ioreg`, `kenv` and `powershell.exe` through a shell (they are called by absolute path, without a shell)
  - feat: `AutoRootSpan::start()`, a root span that names spans `METHOD path` (grouped by `OTEL_PHP_TRANSACTION_URL_GROUPS`), captures the headers named in `OTEL_INSTRUMENTATION_HTTP_SERVER_CAPTURE_REQUEST_HEADERS` and `..._RESPONSE_HEADERS`, sets `http.response.status_code` and supports CLI scripts (`OTEL_PHP_TRANSACTION_SPAN_ENABLED`, `OTEL_PHP_TRANSACTION_SPAN_ENABLED_CLI`)
  - feat: opt-in request body capturing as `http.request.body.content` (`OTEL_PHP_INSTRUMENTATION_HTTP_SERVER_CAPTURE_REQUEST_BODY`, `..._MAX_SIZE`, 1024 bytes by default); only textual bodies are recorded and they are not redacted
  - feat: `OTEL_PHP_EXCLUDED_REQUEST_HEADERS` disables the SDK for web requests with a matching header
  - feat: composable samplers (experimental), `OTEL_METRICS_EXEMPLAR_FILTER` accepts `always_on`, `always_off` and `trace_based` (default), per-instrument-kind temporality selection, new view options (`DropAggregation`, attribute key filters)
  - fix: `OTEL_ATTRIBUTE_COUNT_LIMIT` and `OTEL_ATTRIBUTE_VALUE_LENGTH_LIMIT` were ignored; the wildcard matcher behind `OTEL_PHP_TRANSACTION_URL_GROUPS` matched texts it should not; every export failed when the HTTP client decoded a gzip response itself
  - an unparseable `OTEL_CONFIG_FILE` now logs a warning and leaves the SDK as noop instead of throwing
- **opentelemetry-api 1.11.0** ([api 1.10.0→1.11.0](https://github.com/opentelemetry-php/api/compare/1.10.0...1.11.0)), **opentelemetry-context 1.6.0** ([context 1.5.0→1.6.0](https://github.com/opentelemetry-php/context/compare/1.5.0...1.6.0)):
  - feat: `Logging::setLogLevel()`, `Severity::fromName()`, `Context::withLogger()`, tracecontext and baggage propagator providers for declarative configuration
  - fix: baggage propagator no longer drops entries with percent-encoded values (1.10.1); `exception.escaped` is no longer set by `trace()` and `WithSpanHandler`
  - change: the `Context` default logger writes to the internal logger (filtered by `OTEL_LOG_LEVEL`) instead of being a `NullLogger`
  - context: `EnvironmentGetterSetter` normalizes keys to environment variable names
- **opentelemetry-exporter-otlp 1.5.0** ([exporter-otlp 1.4.0→1.5.0](https://github.com/opentelemetry-php/exporter-otlp/compare/1.4.0...1.5.0)):
  - feat: `OTEL_EXPORTER_OTLP_[SIGNAL_]CERTIFICATE`, `..._CLIENT_CERTIFICATE`, `..._CLIENT_KEY` and `..._INSECURE`
  - change: the `User-Agent` header is `OTel-OTLP-Exporter-PHP/<version>`; histogram data points without min and max are exported without them
  - fix: gRPC default endpoint and per-signal gRPC endpoints, `OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE` is applied per instrument kind
- **sdk-configuration 0.10.0** ([config-sdk 0.9.0→0.10.0](https://github.com/opentelemetry-php/config-sdk/compare/0.9.0...0.10.0)):
  - feat: configuration file format 1.0, composable samplers, `log_level`, `compression: none`, view aggregations and attribute key patterns
  - fix: view `selector.unit`, `stream.aggregation` and `attribute_keys.excluded` were ignored; the OTLP client key was read from `client_certificate_file`
  - see the breaking changes above
- **sem-conv 1.45.0** ([sem-conv 1.38.0→1.45.0](https://github.com/opentelemetry-php/sem-conv/compare/1.38.0...1.45.0)): generated from semantic conventions 1.45.0
- **opentelemetry-auto-laravel 1.9.1** ([laravel 1.9.0→1.9.1](https://github.com/opentelemetry-php/contrib-auto-laravel/compare/1.9.0...1.9.1)):
  - fix: `db.query.text` is not set on the `getModels` span (it made `toSql()` throw for MongoDB); the query string is no longer mixed into `url.path`
  - `opentelemetry-auto-laravel` 1.10.0 is not included yet: it moves to the class-based `Instrumentation` API (the `LaravelInstrumentation::NAME` constant is gone, the instrumentation is configured through `LaravelConfiguration`), which needs a separate change in the distro
- **opentelemetry-auto-mysqli 0.5.0**, **opentelemetry-auto-pdo 0.6.0** (package changelogs: [mysqli 0.4.0→0.5.0](https://github.com/opentelemetry-php/contrib-auto-mysqli/compare/0.4.0...0.5.0), [pdo 0.5.0→0.6.0](https://github.com/opentelemetry-php/contrib-auto-pdo/compare/0.5.0...0.6.0)):
  - feat: SQLcommenter 0.3 support, deprecated `TraceAttributes` replaced by the namespaced SemConv classes
  - fix: binary SQL literals are preserved when SQLcommenter is installed
- **opentelemetry-auto-postgresql 0.4.1**, **opentelemetry-auto-slim 1.5.1** (package changelogs: [postgresql 0.4.0→0.4.1](https://github.com/opentelemetry-php/contrib-auto-postgresql/compare/0.4.0...0.4.1), [slim 1.5.0→1.5.1](https://github.com/opentelemetry-php/contrib-auto-slim/compare/1.5.0...1.5.1)):
  - deprecated `TraceAttributes` replaced by the namespaced SemConv classes
- **opentelemetry-metrics-runtime 0.3.0** ([metrics-runtime 0.2.0→0.3.0](https://github.com/opentelemetry-php/contrib-metrics-runtime/compare/0.2.0...0.3.0)):
  - feat: `process.paging.faults` and `process.uptime` runtime metrics

## 0.7.0

### This release is based on the following OpenTelemetry PHP packages:

- [open-telemetry/api 1.10.0](https://packagist.org/packages/open-telemetry/api#1.10.0)
- [open-telemetry/sdk 1.15.0](https://packagist.org/packages/open-telemetry/sdk#1.15.0)
- [open-telemetry/context 1.5.0](https://packagist.org/packages/open-telemetry/context#1.5.0)

### What's changed

- feat: update instrumentation packages and add header capture tests (PR [#155](https://github.com/open-telemetry/opentelemetry-php-distro/pull/155))
- feat: capture HTTP request/response headers as span attributes (PR [#151](https://github.com/open-telemetry/opentelemetry-php-distro/pull/151))
- ci: add shared security scanning workflow (PR [#145](https://github.com/open-telemetry/opentelemetry-php-distro/pull/145))
- chore: Increase renovate rules to reduce volume (PR [#147](https://github.com/open-telemetry/opentelemetry-php-distro/pull/147))
- chore: Docker images housekeeping (PR [#134](https://github.com/open-telemetry/opentelemetry-php-distro/pull/134))
- feat: add composer audit for PHP production dependencies (PR [#142](https://github.com/open-telemetry/opentelemetry-php-distro/pull/142))

### Upstream package highlights

- **opentelemetry-auto-laravel 1.9.0** (from 1.7.0):
  - fix: set `STATUS_ERROR` for non-zero exit codes in console `Command::execute`
  - fix: prevent span leak in Worker processes by ending spans on empty receive
  - fix: correct span kind to `KIND_SERVER` for Artisan `Kernel::handle`
  - fix: URL path construction in `httpTarget()` and queue name parameter index in `hookLater()`
  - feat: opt-in context flattening for improved faceted log search
- **opentelemetry-auto-curl 0.4.0** (from 0.2.0):
  - fix: deduplicate propagation headers on outgoing curl requests
  - feat: standardized header capture config option names (aligned with SDK-standard keys)
- **opentelemetry-auto-http-async 1.4.0** (from 1.2.0):
  - feat: standardized header capture config option names
- **opentelemetry-auto-psr18 1.4.0** (from 1.2.0):
  - feat: standardized header capture config option names; add PHP 8.5 support
- **opentelemetry-auto-postgresql 0.4.0** (stabilized from dev-main):
  - feat: SQLcommenter support for PostgreSQL (`pdo_pgsql`)
- **sdk-configuration 0.9.0** (from 0.8.0):
  - feat: OTel SDK self-observability metrics
  - feat: span suppression strategy now configurable via file-based config

## 0.6.1

### This release is based on the following OpenTelemetry PHP packages:

- [open-telemetry/api 1.10.0](https://packagist.org/packages/open-telemetry/api#1.10.0)
- [open-telemetry/sdk 1.15.0](https://packagist.org/packages/open-telemetry/sdk#1.15.0)
- [open-telemetry/context 1.5.0](https://packagist.org/packages/open-telemetry/context#1.5.0)

### What's changed

- fix: bump guzzlehttp/guzzle to fix security vulnerability (PR [#140](https://github.com/open-telemetry/opentelemetry-php-distro/pull/140))
- fix: reenabled Inferred Spans component test (PR [#132](https://github.com/open-telemetry/opentelemetry-php-distro/pull/132))
- docs: long-running PHP server support ([#137](https://github.com/open-telemetry/opentelemetry-php-distro/issues/137)) (PR [#138](https://github.com/open-telemetry/opentelemetry-php-distro/pull/138))

## 0.6.0

### This release is based on the following OpenTelemetry PHP packages:

- [open-telemetry/api 1.10.0](https://packagist.org/packages/open-telemetry/api#1.10.0)
- [open-telemetry/sdk 1.15.0](https://packagist.org/packages/open-telemetry/sdk#1.15.0)
- [open-telemetry/context 1.5.0](https://packagist.org/packages/open-telemetry/context#1.5.0)

### What's changed

- feat: add opentelemetry/opentelemetry-metrics-runtime to the distro ([#131](https://github.com/open-telemetry/opentelemetry-php-distro/issues/131))
- feat: bridge app-owned OpenTelemetry usage into the distro's scoped runtime ([#126](https://github.com/open-telemetry/opentelemetry-php-distro/issues/126))
- fix: hook() no longer requires the target class/function to already be declared ([#127](https://github.com/open-telemetry/opentelemetry-php-distro/issues/127))
- update open-telemetry/sdk package to 1.15.0 ([#133](https://github.com/open-telemetry/opentelemetry-php-distro/issues/133))
- feat: add Slim and Laravel auto-instrumentation component tests ([#128](https://github.com/open-telemetry/opentelemetry-php-distro/issues/128))
- feat: support local Composer package overrides for development ([#125](https://github.com/open-telemetry/opentelemetry-php-distro/issues/125))
- feat: improve changelog draft generator ([#135](https://github.com/open-telemetry/opentelemetry-php-distro/issues/135))

## 0.5.1

- fix: bump guzzlehttp/guzzle to fix security vulnerability (PR [#123](https://github.com/open-telemetry/opentelemetry-php-distro/pull/123))

## 0.5.0

- feat: add earlySetup to register OTLP transport before SDK initialization ([#121](https://github.com/open-telemetry/opentelemetry-php-distro/pull/121))
- feat: PSR18 auto instrumentation ([#111](https://github.com/open-telemetry/opentelemetry-php-distro/pull/111))
- feat: support file-based declarative configuration (OTEL_CONFIG_FILE) ([#106](https://github.com/open-telemetry/opentelemetry-php-distro/pull/106))
- Add #[WithSpan] / #[SpanAttribute] native attribute-based instrumentation ([#117](https://github.com/open-telemetry/opentelemetry-php-distro/issues/117)) ([#118](https://github.com/open-telemetry/opentelemetry-php-distro/pull/118))
- Support disabling scoped dependencies via configuration ([#95](https://github.com/open-telemetry/opentelemetry-php-distro/pull/95))
- fix: NOTICE file generation moved out of source ([#108](https://github.com/open-telemetry/opentelemetry-php-distro/pull/108))
- fix: prevent mock object leak in OpAmp tests ([#103](https://github.com/open-telemetry/opentelemetry-php-distro/pull/103))
- Fixed passing production options to component tests app code ([#107](https://github.com/open-telemetry/opentelemetry-php-distro/pull/107))
- Fixed ResourceAttributes deprecated ([#96](https://github.com/open-telemetry/opentelemetry-php-distro/pull/96))
- Fixed TraceAttributes deprecated ([#90](https://github.com/open-telemetry/opentelemetry-php-distro/pull/90))
- Fixed tests for debug_scoper_enabled=true ([#85](https://github.com/open-telemetry/opentelemetry-php-distro/pull/85))
- Run build cleanup and PHP tool scripts inside Docker ([#120](https://github.com/open-telemetry/opentelemetry-php-distro/pull/120))
- Improved unit tests to catch mismatches between options names, metadata and config snapshot ([#110](https://github.com/open-telemetry/opentelemetry-php-distro/pull/110))
- Refactored logging to use the same sink for tests and tools ([#102](https://github.com/open-telemetry/opentelemetry-php-distro/pull/102))
- Refactored simple component tests ([#101](https://github.com/open-telemetry/opentelemetry-php-distro/pull/101))
- Renamed ResourcesClient to ResourcesCleanerClient ([#100](https://github.com/open-telemetry/opentelemetry-php-distro/pull/100))
- Renamed appCodeArgs to appCodeRequestArgs ([#99](https://github.com/open-telemetry/opentelemetry-php-distro/pull/99))
- Added to BootstrapStageLoggingClassTrait a way to customize prod-log-… ([#98](https://github.com/open-telemetry/opentelemetry-php-distro/pull/98))
- Use get_config_option_by_name('enabled') instead of reading environment variable in PhpPartFacade ([#97](https://github.com/open-telemetry/opentelemetry-php-distro/pull/97))
- Adapted packages' PHP code layout to scoping ([#94](https://github.com/open-telemetry/opentelemetry-php-distro/pull/94))
- Renamed repo root directory for generated files from `build` to `_BUILT` ([#93](https://github.com/open-telemetry/opentelemetry-php-distro/pull/93))
- Add a fast development shortcut that only re-scopes distro code ([#92](https://github.com/open-telemetry/opentelemetry-php-distro/pull/92))
- Updated OpAmp configuration reference ([#89](https://github.com/open-telemetry/opentelemetry-php-distro/pull/89))
- PostgreSQL smoke component test ([#87](https://github.com/open-telemetry/opentelemetry-php-distro/issues/87)) ([#88](https://github.com/open-telemetry/opentelemetry-php-distro/pull/88))
- Removed retryDelayedHooks from InstrumentationBridge ([#86](https://github.com/open-telemetry/opentelemetry-php-distro/pull/86))
- OpAmp config improvements ([#84](https://github.com/open-telemetry/opentelemetry-php-distro/pull/84))
- Upgraded dev PHP dependencies ([#104](https://github.com/open-telemetry/opentelemetry-php-distro/pull/104))
- Update docker/setup-qemu-action action to v3.7.0 ([#112](https://github.com/open-telemetry/opentelemetry-php-distro/pull/112))
- Update docker/setup-buildx-action action to v3.12.0 ([#81](https://github.com/open-telemetry/opentelemetry-php-distro/pull/81))
- Update github/codeql-action action to v4.36.0 ([#113](https://github.com/open-telemetry/opentelemetry-php-distro/pull/113))

## 0.4.0

- Enabled support for PHP 8.5 ([#58](https://github.com/open-telemetry/opentelemetry-php-distro/issues/58)) (PR [#77](https://github.com/open-telemetry/opentelemetry-php-distro/pull/77))
- Added user bootstrap config option (PR [#76](https://github.com/open-telemetry/opentelemetry-php-distro/pull/76))
- Automate C++ semconv header generation during CMake configure ([#63](https://github.com/open-telemetry/opentelemetry-php-distro/issues/63)) (PR [#75](https://github.com/open-telemetry/opentelemetry-php-distro/pull/75))
- Update Laravel instrumentation to 1.7.0 (PR [#65](https://github.com/open-telemetry/opentelemetry-php-distro/pull/65))
- Update SDK and instrumentation modules (PR [#62](https://github.com/open-telemetry/opentelemetry-php-distro/pull/62))
- fix: selection of PHP version in component test docker image (PR [#61](https://github.com/open-telemetry/opentelemetry-php-distro/pull/61))

## 0.3.0

- fix: internal inferred spans filtering for scoped namespace (PR [#56](https://github.com/open-telemetry/opentelemetry-php-distro/pull/56))
- fix: use system_clock for Boost.Interprocess timed_receive to prevent futex spin loop causing high CPU ([#52](https://github.com/open-telemetry/opentelemetry-php-distro/issues/52)) (PR [#55](https://github.com/open-telemetry/opentelemetry-php-distro/pull/55))
- Enable vendor customizations and custom OpAMP consumers in PHP side ([#53](https://github.com/open-telemetry/opentelemetry-php-distro/issues/53)) ([#54](https://github.com/open-telemetry/opentelemetry-php-distro/issues/54)) (PR [#54](https://github.com/open-telemetry/opentelemetry-php-distro/pull/54

## 0.2.0

- Exposing artificial hook function if scoping is enabled (PR [#49](https://github.com/open-telemetry/opentelemetry-php-distro/pull/49))
- Dependency shadowing/scoping (PR [#47](https://github.com/open-telemetry/opentelemetry-php-distro/pull/47))

## 0.1.0

Initial technical preview release.

This is not an alpha or beta stability commitment.
The distro may not work correctly in all environments and may affect the behavior of the monitored application.
