FROM php:8.2-apache

# Instala as extensões PDO do PostgreSQL que o seu db.php precisa
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql

# Copia os arquivos do seu site para a pasta do servidor
COPY . /var/www/html/

# Ativa o módulo de reescrita de URL do Apache
RUN a2enmod rewrite

EXPOSE 80
