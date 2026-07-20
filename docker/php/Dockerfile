FROM serversideup/php:8.4-fpm-nginx

USER root

RUN install-php-extensions krakjoe/pcov@v1.0.12

COPY --chown=root:root docker/php/pcov.ini /usr/local/etc/php/conf.d/zzz-pcov.ini

USER www-data
