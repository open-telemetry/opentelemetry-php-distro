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

## Install in a container image

The distro is also built as an image that holds only its files under `/opt/opentelemetry`. Add it to your own PHP image with `COPY --from`. No package manager or install script is involved.

Each image works with every supported PHP version. Pick the tag by the C library of your base image:

- Debian or Ubuntu based images (for example `php:8.4-fpm`): the plain version tag.
- Alpine based images (for example `php:8.4-fpm-alpine`): the version tag with a `-musl` suffix.

The image is not published yet. To build it locally from built packages in `_BUILT/packages`:

```bash
./tools/build/build_image.sh --libc glibc --tag opentelemetry-php-distro:local
./tools/build/build_image.sh --libc musl --tag opentelemetry-php-distro:local-musl
```

Then copy the files and the ini that loads the extension into your image:

```dockerfile
FROM php:8.4-fpm
COPY --from=opentelemetry-php-distro:local /opt/opentelemetry /opt/opentelemetry
COPY --from=opentelemetry-php-distro:local /opt/opentelemetry/php/distro/etc/opentelemetry-distro.ini /usr/local/etc/php/conf.d/98-opentelemetry-distro.ini
```

`/usr/local/etc/php/conf.d` is the scan directory in the official PHP images. For other images, `php --ini` shows it.

To change settings, add another ini file that sorts after `98-opentelemetry-distro.ini`, or set environment variables. See [Configuration](../reference/configuration.md).

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
- If using Laravel Octane (Swoole or RoadRunner), see [Long-running PHP servers](../reference/long-running-server.md).
