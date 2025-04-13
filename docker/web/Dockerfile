FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y zlib1g-dev libzip-dev libicu-dev libpng-dev unzip gnupg2 openssl \
 && docker-php-ext-configure intl \
 && docker-php-ext-install intl \
 && docker-php-ext-install zip \
 && docker-php-ext-install pdo_mysql \
 && a2enmod rewrite ssl headers \
 && mv /var/www/html /var/www/public \
 && curl -sS https://getcomposer.org/installer \
  | php -- --install-dir=/usr/local/bin --filename=composer --version=2.1.9

RUN mkdir /etc/apache2/ssl \
 && openssl req \
              -newkey rsa:4096 \
              -days 365 \
              -nodes \
              -x509 \
              -subj "/C=PL/ST=Małopolska/L=Kraków/O=Dis/CN=cronitorex.local" \
              -keyout /etc/apache2/ssl/apache.key \
              -out /etc/apache2/ssl/apache.crt

RUN curl -sS https://dl.yarnpkg.com/debian/pubkey.gpg | apt-key add - \
    && echo "deb https://dl.yarnpkg.com/debian/ stable main" | tee /etc/apt/sources.list.d/yarn.list \
    && apt-get update \
    && apt-get install -y yarn \
    && yarn install

RUN echo "ServerName cronitorex.local" >> /etc/apache2/apache2.conf

RUN service apache2 restart

WORKDIR /var/www
