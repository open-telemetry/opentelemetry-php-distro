ARG PHP_BASE_IMAGE
FROM ${PHP_BASE_IMAGE}

# pcntl is needed by the tests of exit() called by a signal handler. It is part of PHP, but the official images do not build
# it in. PHPIZE_DEPS (the tools docker-php-ext-install needs) is defined by the official PHP images, apt-get is for the
# Debian ones, apk for the Alpine ones.
RUN set -eu; \
    if command -v apt-get > /dev/null 2>&1; then \
        apt-get update -qq; \
        apt-get install -y -qq --no-install-recommends ${PHPIZE_DEPS}; \
        rm -rf /var/lib/apt/lists/*; \
    else \
        apk add --no-cache ${PHPIZE_DEPS}; \
    fi; \
    docker-php-ext-install pcntl; \
    php -m | grep -qx pcntl
