# Limitations

This page describes known limitations and constraints of OpenTelemetry PHP Distro.

## Running with another PHP telemetry agent

Do not run OpenTelemetry PHP Distro together with another PHP APM or OpenTelemetry agent in the same process. Running both can cause conflicts, duplicate instrumentation, and unstable behavior.

## `open_basedir`

If `open_basedir` is enabled in `php.ini`, the distro installation path must be included in allowed paths, otherwise the agent may fail to load.

## `xdebug`

Running with `xdebug` is not recommended in production and may cause stability or memory issues in instrumented processes.
