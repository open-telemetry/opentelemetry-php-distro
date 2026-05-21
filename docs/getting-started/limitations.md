# Limitations

This page describes known limitations and constraints of OpenTelemetry PHP Distro.

## Running with another PHP telemetry agent

Do not run OpenTelemetry PHP Distro together with another PHP APM or OpenTelemetry agent in the same process. Running both can cause conflicts, duplicate instrumentation, and unstable behavior.

## `open_basedir`

If `open_basedir` is enabled in `php.ini`, the distro installation path must be included in allowed paths, otherwise the agent may fail to load.

## `xdebug`

Running with `xdebug` is not recommended in production and may cause stability or memory issues in instrumented processes.

## File-based configuration (`OTEL_CONFIG_FILE`)

When using file-based (declarative) configuration:

- Central configuration (OpAMP) is not available — file-based and remote configuration are mutually exclusive.
- Resource detectors registered via `Registry::registerResourceDetector()` (e.g., cloud provider detectors from `opentelemetry-php-contrib`) are not automatically active. They must provide a `ComponentProvider` and be explicitly listed in the YAML `resource.detection/development.detectors` section.
- The distro ships a built-in `distro` detector for `telemetry.distro.name` and `telemetry.distro.version` attributes. See [Configuration](../reference/configuration.md#distro-resource-detector) for usage.
