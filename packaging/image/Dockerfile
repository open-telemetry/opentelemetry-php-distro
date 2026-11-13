# Distro files only, for adding to a PHP image with COPY --from.
# The files come from the built .deb and .apk, passed in as the "packages" build context,
# so the image carries the same files as the packages and nfpm.yaml stays the one list of
# contents. tools/build/build_image.sh sets this up.
#
# LIBC=glibc takes the files from the .deb, LIBC=musl from the .apk.

ARG LIBC

# Extraction runs on the build platform, so a multi-platform build needs no emulation.
FROM --platform=${BUILDPLATFORM} debian:trixie-slim AS extract
ARG LIBC
ARG TARGETARCH

COPY opentelemetry-distro.ini /ini/

RUN --mount=type=bind,from=packages,target=/packages <<'EOF'
set -e -u -o pipefail
case "${LIBC}" in
    glibc)
        package_file=$(ls /packages/opentelemetry-php-distro_*_"${TARGETARCH}".deb)
        dpkg-deb --extract "${package_file}" /out
        ;;
    musl)
        case "${TARGETARCH}" in
            amd64) apk_arch=x86_64 ;;
            arm64) apk_arch=aarch64 ;;
            *) echo "Unsupported TARGETARCH: ${TARGETARCH}"; exit 1 ;;
        esac
        package_file=$(ls /packages/opentelemetry-php-distro_*_"${apk_arch}".apk)
        mkdir /out
        # An .apk is several gzipped tar segments end to end. Only opt/ is payload.
        tar --extract --gzip --ignore-zeros --file "${package_file}" --directory /out opt
        ;;
    *)
        echo "LIBC must be glibc or musl, got: '${LIBC}'"
        exit 1
        ;;
esac
cp /ini/opentelemetry-distro.ini /out/opt/opentelemetry/php/distro/etc/
EOF

FROM scratch
ARG VERSION
LABEL org.opencontainers.image.title="OpenTelemetry PHP Distro" \
      org.opencontainers.image.description="OpenTelemetry PHP Distro files, for use with COPY --from" \
      org.opencontainers.image.source="https://github.com/open-telemetry/opentelemetry-php-distro" \
      org.opencontainers.image.licenses="Apache-2.0" \
      org.opencontainers.image.version="${VERSION}"
COPY --from=extract /out/opt /opt
