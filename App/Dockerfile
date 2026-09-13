FROM php:8.3-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg \
        p7zip-full \
        procps \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY . /app
COPY php.ini /usr/local/etc/php/conf.d/mystash.ini
COPY php-fpm.conf /usr/local/etc/php-fpm.d/zz-mystash.conf

# php-fpm's own default. Stated so it is obvious that nginx talks to this and
# nothing outside the compose network can.
EXPOSE 9000
