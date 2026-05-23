#!/bin/bash
# Railway provides a dynamic PORT env variable — Apache needs to listen on it
PORT="${PORT:-80}"
sed -i "s/Listen 80/Listen $PORT/" /etc/apache2/ports.conf
sed -i "s/:80>/:$PORT>/" /etc/apache2/sites-enabled/000-default.conf
apache2-foreground
