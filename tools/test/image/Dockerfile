# Installs the distro into a stock PHP image the way the setup docs tell users to:
# two COPY --from lines and nothing else. Keep the two in sync.
ARG DISTRO_IMAGE
ARG PHP_IMAGE

# COPY --from does not expand build args, so the distro image needs a named stage.
FROM ${DISTRO_IMAGE} AS distro

FROM ${PHP_IMAGE}

COPY --from=distro /opt/opentelemetry /opt/opentelemetry
COPY --from=distro /opt/opentelemetry/php/distro/etc/opentelemetry-distro.ini /usr/local/etc/php/conf.d/98-opentelemetry-distro.ini
