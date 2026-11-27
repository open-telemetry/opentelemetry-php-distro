ARG PHP_VERSION=8.2
# Override for PHP versions without an official NTS default image, see get_nts_php_docker_image() in tools/shared.sh
ARG PHP_BASE_IMAGE=php:${PHP_VERSION}-cli
FROM ${PHP_BASE_IMAGE}

USER root

RUN apt-get update && apt-get install -y \
        jq git unzip tar libpq-dev

ARG COMPOSER_VERSION

ADD https://getcomposer.org/installer composer-setup.php

RUN php composer-setup.php --quiet --install-dir="/usr/local/bin" --filename="composer" --version="${COMPOSER_VERSION:?}" \
        && chmod +x /usr/local/bin/composer \
        && rm composer-setup.php

RUN docker-php-ext-configure mysqli \
        && docker-php-ext-install -j$(nproc) mysqli pgsql

# mbstring (required by PHPUnit) is not enabled in the official PHP 8.6 images
RUN if ! php -m | grep -qx mbstring; then apt-get install -y libonig-dev && docker-php-ext-install -j$(nproc) mbstring; fi

USER php
