ARG PHP_VERSION=8.1
FROM php:${PHP_VERSION}-cli

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

USER php
