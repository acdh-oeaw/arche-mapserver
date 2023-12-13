FROM php:8.1-apache
# VARIANT must be "production" or "development"
ARG VARIANT
RUN cp "$PHP_INI_DIR/php.ini-$VARIANT" "$PHP_INI_DIR/php.ini"
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions &&\
    install-php-extensions mbstring pdo_sqlite yaml @composer &&\
    apt update && apt install -y cgi-mapserver gdal-bin && apt clean &&\
    a2enmod rewrite &&\
    a2enmod cgi &&\
    sed -i -e '/DocumentRoot/a SetEnv MAPSERVER_CONFIG_FILE /var/www/html/mapserver.conf' /etc/apache2/sites-enabled/* &&\
    mkdir /data &&\
    chown www-data:www-data /data
COPY docroot /var/www/html
COPY mapserver.conf /var/www/html/mapserver.conf
RUN cd /var/www/html &&\
    composer update -o --no-dev &&\
    chown -R www-data:www-data /var/www/html
EXPOSE 80

