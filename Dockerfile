FROM php:8.2-apache

# Install required system packages and PHP extensions
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    ssl-cert \
    && docker-php-ext-install curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite for friendly URLs if needed
RUN a2enmod rewrite

# Copy the application source code to the Apache document root
COPY . /var/www/html/

# Adjust permissions so Apache can read and serve the files
RUN chown -R www-data:www-data /var/www/html

# Apache listens on port 80 by default
EXPOSE 80
