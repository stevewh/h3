FROM php:8.2.29-apache

RUN apt-get update -y --fix-missing \
    && apt-get upgrade -y \
    && apt-get install -y --fix-missing --no-install-recommends \
               git \
               nano \
               unzip \
               zip \
               libmemcached-dev \
               libssl-dev \
               libzip-dev \
               zlib1g-dev -y \
               libxslt1-dev \
               libmagick++-dev \
               libmagickwand-dev \
               libfreetype6-dev \
               libjpeg62-turbo-dev \
               libpng-dev \
               libedit-dev && \
    printf "" | pecl install -D 'with-libmemcached-dir="/usr"' memcached && \
    docker-php-ext-enable memcached && \
    docker-php-ext-install xsl && \
    rm -rf /var/lib/apt/lists/*

  # Install php extension need for READ 
RUN docker-php-ext-configure gd \
    --with-jpeg --with-freetype \
  && docker-php-ext-install gd \
  && docker-php-ext-configure zip \
  && docker-php-ext-install zip

RUN docker-php-ext-install opcache \
  && docker-php-ext-install mysqli \
  && docker-php-ext-install exif


# compose will create a shared folder to postgresql tools like pg_dump and psql
# so they can be used in this container
# add the postgresql tools directory to the PATH environment variable
ENV PATH $PATH:/usr/bin

# Note to document that this container "read" is exposed to the host on port 8081
# this will not actually do the mapping which is done in the ports: sections of the
# docker-compose.yml
EXPOSE 80

WORKDIR /usr/local/etc/php
# create a php.ini file from the development version change to copy php.ini-production => php.ini for production
RUN cp php.ini-development php.ini
# Update Php Settings
RUN sed -E -i -e 's/max_execution_time = 30/max_execution_time = 300/' php.ini \
 && sed -E -i -e 's/memory_limit = 128M/memory_limit = 5120M/' php.ini \
 && sed -E -i -e 's/post_max_size = 8M/post_max_size = 32000000/' php.ini \
 && sed -E -i -e 's/upload_max_filesize = 2M/upload_max_filesize = 32000000/' php.ini

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

WORKDIR /var/www/html
