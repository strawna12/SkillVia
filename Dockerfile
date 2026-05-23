FROM php:8.2-fpm-alpine

# Install nginx and required PHP extensions
RUN apk add --no-cache nginx && \
    docker-php-ext-install pdo pdo_mysql

# Write nginx config
RUN mkdir -p /run/nginx && \
    cat > /etc/nginx/http.d/default.conf << 'NGINX'
server {
    listen ${PORT:-80};
    root /var/www/html;
    index index.html index.php;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ ^/api/ {
        rewrite ^/api/(.*)$ /api.php last;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
NGINX

# Copy app files
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

# Startup: substitute PORT, start php-fpm and nginx
CMD sh -c "sed -i \"s/\${PORT:-80}/${PORT:-80}/g\" /etc/nginx/http.d/default.conf && \
    php-fpm -D && \
    nginx -g 'daemon off;'"
