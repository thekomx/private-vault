FROM php:8.2-apache

# Copy the vault files to the Apache document root
COPY . /var/www/html/

# Set correct ownership for the api/ folder so PHP can write vault.data.php
RUN chown -R www-data:www-data /var/www/html/api

# Expose port 80
EXPOSE 80

# TheKom™ // was here.
