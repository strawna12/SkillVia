FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    libpdo-mysql-dev \
    && docker-php-ext-install pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

COPY . /app
WORKDIR /app

EXPOSE 8080

CMD php -S 0.0.0.0:${PORT:-8080} -t /app
