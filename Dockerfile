FROM php:8.2-apache

# Install PHP MySQL extension
RUN docker-php-ext-install pdo pdo_mysql

# Enable mod_rewrite for .htaccess routing
RUN a2enmod rewrite

# Copy all app files to web root
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Allow .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Railway uses a dynamic PORT — update Apache to listen on it at startup
CMD bash -c "sed -i \"s/Listen 80/Listen \${PORT:-80}/\" /etc/apache2/ports.conf && \
    sed -i \"s/:80>/:\${PORT:-80}>/\" /etc/apache2/sites-enabled/000-default.conf && \
    apache2-foreground"
