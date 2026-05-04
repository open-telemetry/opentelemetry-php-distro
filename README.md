# OpenTelemetry PHP Distro

Production-ready, zero-code OpenTelemetry instrumentation for PHP, delivered as native Linux packages.

## Why this project exists

Many PHP environments are hard to instrument with a Composer-only workflow (locked-down hosts, limited build tooling, or strict deployment pipelines). OpenTelemetry PHP Distro focuses on those production realities:

- install via OS package (`deb`, `rpm`, `apk`)
- restart PHP process
- start sending telemetry

No app code changes are required for common setups.

## Project status

The donation proposal to OpenTelemetry community was accepted and this repository is developed as `opentelemetry-php-distro`.
The project is actively developed in collaboration with OpenTelemetry maintainers.

- Donation proposal: [open-telemetry/community#2846](https://github.com/open-telemetry/community/issues/2846)

## What is this project?

OpenTelemetry PHP Distro is a production-focused distribution for instrumenting PHP applications with OpenTelemetry.

The distro combines:

- native PHP extension and loader (`.so` artifacts)
- PHP runtime/bootstrap logic
- auto-instrumentation dependencies for popular libraries/frameworks
- packaging scripts for Linux distributions

The goal is to provide a consistent, packaged, and testable way to enable telemetry (traces/metrics/log-related signals as supported by the underlying components) in PHP workloads.

## Key features

- Native OS packages for `deb`, `rpm`, and `apk` workflows
- Automatic bootstrap and auto-instrumentation after installation
- Background telemetry sending (non-blocking)
- Inferred spans and automatic root span creation
- URL grouping for transaction/root spans
- Native OTLP protobuf serialization (no separate `ext-protobuf` requirement)
- Support for PHP `8.1` to `8.5`

## Quick start (60s)

1. Install the distro package for your platform (`deb`, `rpm`, or `apk`).
2. Set `OTEL_EXPORTER_OTLP_ENDPOINT` and `OTEL_EXPORTER_OTLP_HEADERS`.
3. Restart your PHP process and verify traces in your backend.

Full setup guide: [docs/getting-started/setup.md](docs/getting-started/setup.md)

## Why use it?

- Provides one integrated distribution instead of assembling native + PHP parts manually.
- Supports multiple PHP versions and Linux package formats.
- Uses OpenTelemetry ecosystem components (SDK/exporters/instrumentations).
- After package installation, the agent is loaded automatically after restarting PHP processes.

## Relationship to other OTel PHP projects

OpenTelemetry PHP Distro is complementary to `opentelemetry-php` and `opentelemetry-php-instrumentation`.

- Choose the distro when you want package-managed, production-first, zero-code onboarding.
- Choose Composer-centric instrumentation when you need maximum manual control or platform flexibility.

## Supported matrix

- PHP: `8.1`, `8.2`, `8.3`, `8.4`, `8.5`
- Package types: `deb`, `rpm`, `apk`
- Build architectures used in tooling: `linux-x86-64`, `linuxmusl-x86-64`, `linux-arm64`, `linuxmusl-arm64`

## Development

For contributor workflows, local build/test commands, and advanced development options, see [DEVELOPMENT.md](DEVELOPMENT.md).

## Repository structure (high level)

- `prod/native` — native extension, loader, C/C++ code, Conan/CMake build logic
- `prod/php` — PHP runtime/bootstrap and distro PHP code
- `packaging` — package metadata and install/uninstall scripts
- `tools/build` — build/test/package scripts used locally and in CI
- `tests` — unit/component and integration-oriented tests

## Documentation

- Documentation index: [docs/README.md](docs/README.md)
- Setup and installation: [docs/getting-started/setup.md](docs/getting-started/setup.md)
- Limitations: [docs/getting-started/limitations.md](docs/getting-started/limitations.md)
- Configuration reference: [docs/reference/configuration.md](docs/reference/configuration.md)
- Supported technologies: [docs/reference/supported-technologies.md](docs/reference/supported-technologies.md)
- Performance overhead: [docs/reference/performance-overhead.md](docs/reference/performance-overhead.md)
- Development guide: [DEVELOPMENT.md](DEVELOPMENT.md)
- Dockerized build images: [prod/native/building/dockerized/README.md](prod/native/building/dockerized/README.md)

## Maintainers

- [@intuibase](https://github.com/intuibase)
- [@SergeyKleyman](https://github.com/SergeyKleyman)

For more information about the maintainer role, see the [community repository](https://github.com/open-telemetry/community/blob/main/guides/contributor/membership.md#maintainer).

## Approvers

- [@intuibase](https://github.com/intuibase)
- [@SergeyKleyman](https://github.com/SergeyKleyman)

For more information about the approver role, see the [community repository](https://github.com/open-telemetry/community/blob/main/guides/contributor/membership.md#approver).

