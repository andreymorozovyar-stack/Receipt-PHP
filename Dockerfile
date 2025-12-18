FROM php:8.1-apache

# Установка системных зависимостей
RUN apt-get update && apt-get install -y \
    tesseract-ocr \
    tesseract-ocr-rus \
    tesseract-ocr-eng \
    zbar-tools \
    libzip-dev \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Установка системных библиотек для GD
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/*

# Установка расширений PHP
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd zip

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Настройка Apache
RUN a2enmod rewrite
RUN sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Рабочая директория
WORKDIR /var/www/html

# Копирование composer файлов
COPY composer.json composer.lock* ./

# Установка зависимостей PHP
RUN composer install --no-dev --optimize-autoloader

# Копирование остальных файлов проекта
COPY . .

# Если есть локальные языковые пакеты, используем их
# Иначе используем системные пакеты Tesseract
RUN if [ -d "tessdata" ] && [ "$(ls -A tessdata)" ]; then \
        echo "Используются локальные языковые пакеты"; \
    else \
        echo "Используются системные языковые пакеты Tesseract"; \
    fi

# Настройка прав доступа
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Настройка Apache для работы с проектом
ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Порт
EXPOSE 80

# Запуск Apache
CMD ["apache2-foreground"]





