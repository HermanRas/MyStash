FROM php:8.3-fpm

# nginx lives in this image too. It is a second process rather than a second
# container because the two are not independently useful: nginx here serves
# exactly one document root, from the same files php-fpm executes, and neither
# is ever scaled or restarted without the other. Splitting them bought a
# FastCGI hop over a Docker network and a bind mount of the code into two
# places; joining them means one build, one log stream and one thing to stop.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg \
        p7zip-full \
        procps \
        nginx-light \
    && rm -rf /var/lib/apt/lists/*

# The Debian package ships a site on port 80 serving its own welcome page.
# Nothing includes it once this is gone; the app's server block arrives below
# in conf.d, which nginx.conf also includes.
RUN rm -f /etc/nginx/sites-enabled/default

WORKDIR /app

COPY . /app
COPY php.ini /usr/local/etc/php/conf.d/mystash.ini
COPY php-fpm.conf /usr/local/etc/php-fpm.d/zz-mystash.conf
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

# nginx listens; php-fpm is reached over the loopback inside this container and
# is not published anywhere.
EXPOSE 8080

CMD ["/usr/local/bin/docker-entrypoint.sh"]
