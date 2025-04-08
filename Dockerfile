# Use official PHP Apache image
FROM php:8.1-apache

# Install dependencies
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
  && docker-php-ext-install zip

# Enable Apache rewrite module
RUN a2enmod rewrite

# Copy application files
COPY . /var/www/html/

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && touch /var/www/html/users.json \
    && touch /var/www/html/error.log \
    && chmod 664 /var/www/html/users.json \
    && chmod 664 /var/www/html/error.log

# Expose port 80
EXPOSE 80