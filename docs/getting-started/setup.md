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

The distro is also published as an image that holds only its files under `/opt/opentelemetry`. Add it to your own PHP image with `COPY --from`. No package manager or install script is involved.

### Pick an image tag

Each image works with every supported PHP version, on `linux/amd64` and `linux/arm64`. Pick the tag by the C library of your base image:

| Base image | Example | Tags |
|------------|---------|------|
| Debian or Ubuntu (glibc) | `php:8.4-fpm` | `0.7.1`, `0.7`, `latest` |
| Alpine (musl) | `php:8.4-fpm-alpine` | `0.7.1-musl`, `0.7-musl`, `musl` |

The images are at `ghcr.io/open-telemetry/opentelemetry-php-distro`. Pin a full version in production. Before 1.0 there is no major-only tag, because a minor release can include breaking changes. Pre-releases get only their own version tag.

### Copy the distro into your image

Copy the files and the ini that loads the extension:

```dockerfile
FROM php:8.4-fpm
COPY --from=ghcr.io/open-telemetry/opentelemetry-php-distro:0.7.1 /opt/opentelemetry /opt/opentelemetry
COPY --from=ghcr.io/open-telemetry/opentelemetry-php-distro:0.7.1 \
     /opt/opentelemetry/php/distro/etc/opentelemetry-distro.ini \
     /usr/local/etc/php/conf.d/98-opentelemetry-distro.ini
```

For Alpine, use the `-musl` tag:

```dockerfile
FROM php:8.4-fpm-alpine
COPY --from=ghcr.io/open-telemetry/opentelemetry-php-distro:0.7.1-musl /opt/opentelemetry /opt/opentelemetry
COPY --from=ghcr.io/open-telemetry/opentelemetry-php-distro:0.7.1-musl \
     /opt/opentelemetry/php/distro/etc/opentelemetry-distro.ini \
     /usr/local/etc/php/conf.d/98-opentelemetry-distro.ini
```

`/usr/local/etc/php/conf.d` is the scan directory in the official PHP images. For other images, `php --ini` shows it.

To change settings, add another ini file that sorts after `98-opentelemetry-distro.ini`, or set environment variables. See [Configuration](../reference/configuration.md).

### Image requirements

- glibc images need glibc 2.28 or later, the same as the `deb` and `rpm` packages. `ldd --version` shows the version.
- Thread-safe (ZTS) PHP builds are not supported. The extension logs an error and does nothing. The official `-zts` images are ZTS builds.

### Verify the image signature

Release images are signed with [cosign](https://docs.sigstore.dev/cosign/) keyless signing, from the release workflow. To verify a tag:

```bash
cosign verify ghcr.io/open-telemetry/opentelemetry-php-distro:0.7.1 \
  --certificate-identity-regexp '^https://github\.com/open-telemetry/opentelemetry-php-distro/\.github/workflows/release\.yml@refs/tags/v' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com
```

Each image also carries build provenance and an SBOM. `docker buildx imagetools inspect <image> --format '{{json .Provenance}}'` shows the provenance, and `--format '{{json .SBOM}}'` the SBOM.

### Build the image locally

To build the image from built packages in `_BUILT/packages`:

```bash
./tools/build/build_image.sh --libc glibc --tag opentelemetry-php-distro:local
./tools/build/build_image.sh --libc musl --tag opentelemetry-php-distro:local-musl
```

Then use `opentelemetry-php-distro:local` or `opentelemetry-php-distro:local-musl` in the `COPY --from` lines.

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
