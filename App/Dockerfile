FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg \
        p7zip-full \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY . /app
COPY php.ini /usr/local/etc/php/conf.d/mystash.ini

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app/public"]
