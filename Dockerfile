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

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

# Startup script: reads Railway's $PORT at runtime and writes nginx config on the fly
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
    service php8.1-fpm start && \
    nginx -g 'daemon off;'"
