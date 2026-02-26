# Set up OpenTelemetry PHP Distro

Learn how to instrument your PHP application with OpenTelemetry PHP Distro and send telemetry data to an OTLP-compatible backend.

## Prerequisites

- Have a destination for telemetry data (OTLP endpoint).
- Use a supported Linux and PHP version.
- Do not run another PHP APM or OpenTelemetry agent in the same process.

For supported operating systems and PHP versions, see [Supported technologies](../reference/supported-technologies.md).

## Limitations

Known runtime and compatibility limitations are described in [Limitations](limitations.md).

## Download and install packages

Download a package for your platform from the project releases and install it.

### RPM (RHEL/CentOS/Fedora)

```bash
sudo rpm -ivh <package-file>.rpm
```

### DEB (Debian/Ubuntu)

```bash
sudo dpkg -i <package-file>.deb
```

### APK (Alpine)

```bash
sudo apk add --allow-untrusted <package-file>.apk
```

## Configure exporter

At a minimum, set:

- `OTEL_EXPORTER_OTLP_ENDPOINT`
- `OTEL_EXPORTER_OTLP_HEADERS`

Example:

```bash
export OTEL_EXPORTER_OTLP_ENDPOINT="https://your-otlp-endpoint:443/"
export OTEL_EXPORTER_OTLP_HEADERS="Authorization=Bearer <token>"
```

## Restart PHP processes

After installation and configuration, restart PHP processes (for example `php-fpm`, Apache workers, or long-running CLI workers) so the extension loads.

## Confirm telemetry

1. Open your observability backend.
2. Find your service in traces.
3. Generate traffic if no traces are visible yet.

## Troubleshooting

- Verify configuration options in [Configuration](../reference/configuration.md).
- Check known constraints in [Limitations](limitations.md).
