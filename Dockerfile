# Use pre-built dist/ — no Vite build step needed
FROM php:8.3-fpm-alpine

# Install nginx, curl, sqlite, and cron for PHP
RUN apk add --no-cache nginx curl curl-dev sqlite-dev dcron && \
    docker-php-ext-install curl && \
    docker-php-ext-install pdo_sqlite

# Create directories
RUN mkdir -p /var/www/html/dist /var/www/html/api /var/www/html/data /var/www/html/logs /run/nginx && \
    chown -R www-data:www-data /var/www/html/logs /var/www/html/data

# Copy nginx config
COPY docker/nginx.conf /etc/nginx/http.d/default.conf

# Copy pre-built assets from local dist/
COPY dist/ /var/www/html/dist/

# Copy PHP files
COPY index.php /var/www/html/
COPY api/ /var/www/html/api/

# Set permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html && \
    chmod 775 /var/www/html/logs && \
    chmod 775 /var/www/html/data

# Weekly cron — Monday 7am Pacific: fetch data, AI-compose newsletter, email preview for approval
RUN echo '0 7 * * 1 php /var/www/html/api/newsletter-auto-compose.php --cron >> /var/www/html/logs/auto-compose-cron.log 2>&1' > /etc/crontabs/root

# Copy startup script
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 8080

CMD ["/start.sh"]
