FROM php:8.2-apache

# Install system deps and PHP extensions commonly required by the project
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
       libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libonig-dev libxml2-dev zlib1g-dev git unzip curl \
    && docker-php-ext-install pdo pdo_mysql mysqli mbstring zip exif bcmath sockets pcntl \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install gd \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Copy application
WORKDIR /var/www/html
COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

EXPOSE 80

CMD ["apache2-foreground"]
