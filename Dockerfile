FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y \
    php8.1 \
    php8.1-mysql \
    php8.1-cli \
    php8.1-fpm \
    nginx \
    && rm -rf /var/lib/apt/lists/*

# Remove default nginx site
RUN rm -f /etc/nginx/sites-enabled/default

# Copy all app files to web root
COPY . /var/www/html/

# Fix permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

# Startup: build nginx config from $PORT then launch
CMD bash -c "\
    PORT=\${PORT:-8080} && \
    echo \"server { \
        listen \$PORT; \
        root /var/www/html; \
        index index.html index.php; \
        location / { try_files \\\$uri \\\$uri/ =404; } \
        location ~ ^/api/ { rewrite ^/api/(.*)$ /api.php last; } \
        location ~ \\.php\$ { \
            include snippets/fastcgi-php.conf; \
            fastcgi_pass unix:/run/php/php8.1-fpm.sock; \
        } \
    }\" > /etc/nginx/sites-enabled/skillvia && \
    nginx -t && \
    service php8.1-fpm start && \
    nginx -g 'daemon off;'"
