# Non-thread-safe (NTS) PHP build on top of an official PHP image.
#
# The official php:8.6 images are ZTS-only (docker-library/php#1686) and the distro does not support ZTS.
# This recompiles the PHP version shipped in the base image from the source tarball the image already contains,
# with exactly the configure options reported by the base image's `php -i`, except --enable-zts / --disable-zend-signals,
# and with --enable-mbstring (not enabled in 8.6 images, docker-library/php#1720).
#
# Temporary: remove when official NTS images are back (docker-library/php#1721, #1722).
#
# Bump this to invalidate CI cache without changing anything else: cache epoch 1

ARG BASE_IMAGE=php:8.6-cli
FROM ${BASE_IMAGE} AS build

RUN set -eux; \
    if command -v apk >/dev/null 2>&1; then \
        apk add --no-cache $PHPIZE_DEPS argon2-dev curl-dev gnu-libiconv-dev libsodium-dev libxml2-dev linux-headers oniguruma-dev openssl-dev readline-dev sqlite-dev zlib-dev; \
        rm -f /usr/include/iconv.h; \
    else \
        apt-get -qq update; \
        apt-get -qq -y --no-install-recommends install $PHPIZE_DEPS libargon2-dev libcurl4-openssl-dev libonig-dev libreadline-dev libsodium-dev libsqlite3-dev libssl-dev libxml2-dev zlib1g-dev; \
    fi

RUN set -eux; \
    export CFLAGS="${PHP_CFLAGS:-}" CPPFLAGS="${PHP_CPPFLAGS:-}" LDFLAGS="${PHP_LDFLAGS:-}" PHP_BUILD_PROVIDER='https://github.com/docker-library/php' PHP_UNAME='Linux - Docker'; \
    docker-php-source extract; \
    cd /usr/src/php; \
    configure_args="$(php -i | sed -n 's/^Configure Command => *//p')"; \
    configure_args="$(printf '%s' "$configure_args" | sed -e "s/'--enable-zts'/'--disable-zts'/" -e "s/'--disable-zend-signals'//" -e "s#^'./configure'#'./configure' '--enable-mbstring'#")"; \
    echo "configure: ${configure_args}"; \
    eval "./configure ${configure_args#\'./configure\'}"; \
    make -j"$(nproc)"; \
    make install INSTALL_ROOT=/out; \
    find /out/usr/local -type f -executable -exec strip --strip-all {} + 2>/dev/null || true

FROM ${BASE_IMAGE}

RUN set -eux; \
    if command -v apk >/dev/null 2>&1; then \
        apk add --no-cache oniguruma; \
    else \
        apt-get -qq update; apt-get -qq -y --no-install-recommends install libonig5; rm -rf /var/lib/apt/lists/*; \
    fi; \
    rm -rf /usr/local/lib/php/extensions/no-debug-zts-*

COPY --from=build /out/usr/local/ /usr/local/

RUN php -r 'if (PHP_ZTS) { fwrite(STDERR, "expected NTS PHP\n"); exit(1); } if (!extension_loaded("mbstring")) { fwrite(STDERR, "mbstring missing\n"); exit(1); }' && php -v
