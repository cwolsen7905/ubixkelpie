# Runtime image for one app of this project. Build with
#   docker build --build-arg APP_NAME=HelloApi -t myproject-hello-api .
# uBixCore is installed from composer.lock at build time; it is never committed.
# syntax=docker/dockerfile:1
# The uBixCore PHP runtime (nginx + PHP-FPM + memcache(d), Alpine, port 8080).
# Public by default; override BASE_IMAGE at build time to use a private mirror.
ARG BASE_IMAGE=ghcr.io/ubixsys/ubixcore-php:8.5
FROM ${BASE_IMAGE}
ARG APP_NAME
ENV APP_NAME=${APP_NAME}

USER root
RUN apk add --no-cache git && mkdir -p /web && chown -R www:www /web && chmod -R 0755 /web
# nginx site for /web/public (the base image's default config serves nothing useful)
COPY config/devops/nginx.conf /etc/nginx/nginx.conf
USER www

COPY --chown=www composer.json composer.lock /web/
COPY --chown=www public/    /web/public/
COPY --chown=www php/       /web/php/
COPY --chown=www templates/ /web/templates/
COPY --chown=www app/       /web/app/
COPY --chown=www bin/       /web/bin/
COPY --chown=www sql/       /web/sql/

RUN composer install --working-dir=/web/ --no-dev --no-interaction --prefer-dist --no-progress \
 && mkdir -p /web/var/cache/latte /web/log && chmod -R 777 /web/var /web/log

EXPOSE 8080
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
