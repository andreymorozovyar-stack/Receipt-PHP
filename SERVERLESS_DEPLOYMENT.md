# Деплой в Yandex Cloud Serverless Containers

## Обзор

Данный документ описывает все нюансы, проблемы и решения при деплое PHP-приложения в Yandex Cloud Serverless Containers.

## Ключевые отличия Serverless Containers от обычных контейнеров

### 1. Порт и веб-сервер

**Проблема:** Yandex Cloud Serverless Containers резервирует порт 80 для своей платформы. Apache/Nginx не могут привязаться к этому порту.

**Решение:** Использовать встроенный PHP development server (`php -S`) вместо Apache/Nginx.

**Важно:**
- Платформа автоматически устанавливает переменную окружения `PORT`
- Сервер должен слушать на `0.0.0.0:${PORT}`
- Не использовать фиксированный порт (80, 8080 и т.д.)

**Пример Dockerfile:**
```dockerfile
FROM php:8.1-cli  # НЕ apache!

# Создаем скрипт запуска
RUN echo '#!/bin/bash' > /usr/local/bin/start-server.sh && \
    echo 'set -e' >> /usr/local/bin/start-server.sh && \
    echo 'PORT=${PORT:-8080}' >> /usr/local/bin/start-server.sh && \
    echo 'echo "Starting PHP built-in server on port $PORT"' >> /usr/local/bin/start-server.sh && \
    echo 'cd /var/www/html' >> /usr/local/bin/start-server.sh && \
    echo 'exec php -S 0.0.0.0:${PORT} router.php' >> /usr/local/bin/start-server.sh && \
    chmod +x /usr/local/bin/start-server.sh

CMD ["/usr/local/bin/start-server.sh"]
```

### 2. Router для встроенного PHP сервера

**Проблема:** Встроенный PHP сервер не поддерживает `.htaccess` и URL rewriting. Нужен router-скрипт.

**Решение:** Создать `router.php`, который обрабатывает все запросы.

**Важно:**
- Router должен быть в корне проекта
- Router должен обрабатывать статические файлы (CSS, JS, изображения)
- Router должен перенаправлять API-запросы на `api.php`

**Пример router.php:**
```php
<?php
// Обработка статических файлов
$requestUri = $_SERVER['REQUEST_URI'];
$filePath = __DIR__ . parse_url($requestUri, PHP_URL_PATH);

if (file_exists($filePath) && is_file($filePath)) {
    return false; // Отдаем файл как есть
}

// API запросы
if (strpos($requestUri, '/api/') === 0) {
    $_SERVER['SCRIPT_NAME'] = '/api.php';
    $_SERVER['PATH_INFO'] = substr($requestUri, 4); // Убираем /api
    require __DIR__ . '/api.php';
    return true;
}

// Остальные запросы - отдаем index.html
if (file_exists(__DIR__ . '/index.html')) {
    readfile(__DIR__ . '/index.html');
    return true;
}

http_response_code(404);
echo 'Not Found';
```

### 3. Файлы в Docker образе

**Проблема:** Файлы могут не попасть в образ из-за `.dockerignore`.

**Решение:**
1. Проверить `.dockerignore` - не должен исключать критичные файлы
2. Явно копировать критичные файлы в Dockerfile
3. Добавить проверку наличия файлов в скрипте запуска

**Важно:**
- `router.php` должен быть в образе
- `api.php` должен быть в образе
- `index.html` должен быть в образе
- Проверять наличие файлов перед запуском сервера

**Пример проверки в start-server.sh:**
```bash
if [ ! -f router.php ]; then 
    echo "ERROR: router.php not found"; 
    exit 1; 
fi
```

### 4. .dockerignore

**Проблема:** Если `router.php` в `.dockerignore`, он не попадет в образ даже при `COPY . .`

**Решение:** Убрать критичные файлы из `.dockerignore` или явно копировать их.

**Пример .dockerignore:**
```
vendor/
node_modules/
.git/
tests/
*.md
# НЕ включать router.php, api.php, index.html!
```

### 5. Создание Serverless Container

**Команда создания:**
```bash
yc serverless container create \
    --name receipt-php \
    --folder-id <FOLDER_ID> \
    --description "Receipt recognition service"
```

**Важно:**
- Нужен `folder-id` (не container-id!)
- Получить folder-id: `yc resource-manager folder list`

### 6. Деплой ревизии

**Команда деплоя:**
```bash
yc serverless container revision deploy \
    --container-name receipt-php \
    --image cr.yandex/<REGISTRY_ID>/receipt-php:latest \
    --memory 1GB \
    --cores 1 \
    --execution-timeout 60s \
    --service-account-id <SERVICE_ACCOUNT_ID>
```

**Важные параметры:**
- `--image`: полный путь к образу в Container Registry
- `--memory`: минимум 1GB для Tesseract OCR
- `--cores`: количество CPU ядер
- `--execution-timeout`: таймаут выполнения запроса
- `--service-account-id`: обязательный параметр!

**Проблема:** `service-account-id` обязателен, но не указан в документации явно.

**Решение:** Получить service account:
```bash
yc iam service-account list
```

### 7. Права доступа к Container Registry

**Проблема:** Service account не имеет прав на чтение образов из Container Registry.

**Ошибка:** `PermissionDenied desc = Not enough permissions to use image`

**Решение:** Выдать права `container-registry.images.puller`:
```bash
yc container registry add-access-binding \
    <REGISTRY_ID> \
    --service-account-id <SERVICE_ACCOUNT_ID> \
    --role container-registry.images.puller
```

**Важно:**
- Нужны права на чтение (`puller`), не на запись
- Права выдаются на registry, а не на образ

### 8. Использование digest vs tag

**Проблема:** Yandex Cloud может не принять формат `image@sha256:digest`.

**Ошибка:** `invalid docker image url: cr.yandex/.../receipt-php@sha256:...`

**Решение:** Использовать тег `latest`:
```bash
# Правильно
yc serverless container revision deploy \
    --image cr.yandex/<REGISTRY_ID>/receipt-php:latest

# Неправильно
yc serverless container revision deploy \
    --image cr.yandex/<REGISTRY_ID>/receipt-php@sha256:...
```

**Важно:**
- Всегда использовать тег `latest` при деплое
- Digest используется только для идентификации образа

### 9. Управление образами в реестре

**Проблема:** В реестре накапливаются старые образы и слои.

**Решение:** Удалять старые образы перед загрузкой нового:
```bash
# Удалить старый latest
yc container image list --registry-id <REGISTRY_ID>
yc container image delete <OLD_IMAGE_ID>

# Загрузить новый
docker tag receipt-php-serverless:latest cr.yandex/<REGISTRY_ID>/receipt-php:latest
docker push cr.yandex/<REGISTRY_ID>/receipt-php:latest
```

**Важно:**
- Удалять только старые версии, не текущий `latest`
- Слои (layers) могут быть использованы несколькими образами - их удалять не нужно
- Проверять, не используется ли образ в активной ревизии

### 10. Локальные образы

**Проблема:** Локально накапливаются старые образы и контейнеры.

**Решение:** Регулярно очищать:
```bash
# Остановить и удалить контейнеры
docker-compose down

# Удалить неиспользуемые образы
docker image prune -f

# Удалить старые образы receipt-php
docker images | grep receipt-php
docker rmi <OLD_IMAGE_ID>
```

### 11. Проверка работоспособности

**Health check:**
```bash
curl -k https://<CONTAINER_URL>/api/health
```

**Ожидаемый ответ:**
```json
{
    "status": "ok",
    "service": "receipt-recognition-php"
}
```

**Тест распознавания:**
```bash
curl -k -X POST https://<CONTAINER_URL>/api/recognize \
    -F "file=@receipt_example.png"
```

### 12. Логирование

**Просмотр логов:**
```bash
yc logging read \
    --folder-id <FOLDER_ID> \
    --resource-ids <CONTAINER_ID> \
    --limit 20
```

**Важно:**
- Логи могут быть с задержкой
- Использовать `--limit` для ограничения количества записей
- Искать ошибки по ключевым словам: "ERROR", "Fatal", "router.php not found"

### 13. Типичные ошибки и решения

#### Ошибка: "router.php not found"
**Причина:** Файл не скопирован в образ или находится не в нужной директории.

**Решение:**
1. Проверить `.dockerignore`
2. Добавить явный `COPY router.php` в Dockerfile
3. Добавить проверку в `start-server.sh`

#### Ошибка: "Address already in use"
**Причина:** Попытка использовать порт 80 или фиксированный порт.

**Решение:** Использовать переменную `PORT` и слушать на `0.0.0.0:${PORT}`.

#### Ошибка: "PermissionDenied"
**Причина:** Service account не имеет прав на чтение образа.

**Решение:** Выдать права `container-registry.images.puller`.

#### Ошибка: "Field service_account_id is required"
**Причина:** Не указан service account при деплое.

**Решение:** Добавить `--service-account-id` в команду деплоя.

#### Ошибка: "Invalid image reference"
**Причина:** Использован неправильный формат URL образа (с digest).

**Решение:** Использовать тег `latest` вместо digest.

### 14. Полный процесс деплоя

```bash
# 1. Удалить старый образ из реестра (если есть)
yc container image list --registry-id <REGISTRY_ID>
yc container image delete <OLD_IMAGE_ID>

# 2. Удалить старый локальный образ
docker rmi cr.yandex/<REGISTRY_ID>/receipt-php:latest

# 3. Собрать новый образ
docker build -f Dockerfile.serverless -t receipt-php-serverless:latest .

# 4. Тегировать для реестра
docker tag receipt-php-serverless:latest cr.yandex/<REGISTRY_ID>/receipt-php:latest

# 5. Загрузить в реестр
docker push cr.yandex/<REGISTRY_ID>/receipt-php:latest

# 6. Деплой новой ревизии
yc serverless container revision deploy \
    --container-name receipt-php \
    --image cr.yandex/<REGISTRY_ID>/receipt-php:latest \
    --memory 1GB \
    --cores 1 \
    --execution-timeout 60s \
    --service-account-id <SERVICE_ACCOUNT_ID>

# 7. Проверить работоспособность
sleep 20
curl -k https://<CONTAINER_URL>/api/health
```

### 15. Dockerfile.serverless - полный пример

```dockerfile
FROM php:8.1-cli

# Установка системных зависимостей
RUN apt-get update && apt-get install -y \
    tesseract-ocr \
    tesseract-ocr-rus \
    tesseract-ocr-eng \
    zbar-tools \
    libzip-dev \
    unzip \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/*

# Установка расширений PHP
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd zip

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Рабочая директория
WORKDIR /var/www/html

# Копирование composer файлов
COPY composer.json composer.lock* ./

# Установка зависимостей PHP
RUN composer install --no-dev --optimize-autoloader

# Копирование остальных файлов проекта
COPY . .

# Проверка наличия критичных файлов
RUN test -f /var/www/html/router.php || (echo "ERROR: router.php not found" && exit 1)

# Настройка прав доступа
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Создание скрипта запуска
RUN echo '#!/bin/bash' > /usr/local/bin/start-server.sh && \
    echo 'set -e' >> /usr/local/bin/start-server.sh && \
    echo 'PORT=${PORT:-8080}' >> /usr/local/bin/start-server.sh && \
    echo 'echo "Starting PHP built-in server on port $PORT"' >> /usr/local/bin/start-server.sh && \
    echo 'cd /var/www/html' >> /usr/local/bin/start-server.sh && \
    echo 'if [ ! -f router.php ]; then echo "ERROR: router.php not found"; exit 1; fi' >> /usr/local/bin/start-server.sh && \
    echo 'exec php -S 0.0.0.0:${PORT} router.php' >> /usr/local/bin/start-server.sh && \
    chmod +x /usr/local/bin/start-server.sh

EXPOSE 8080

CMD ["/usr/local/bin/start-server.sh"]
```

### 16. Чеклист перед деплоем

- [ ] Dockerfile использует `php:8.1-cli` (не apache)
- [ ] `router.php` присутствует в проекте
- [ ] `router.php` не в `.dockerignore`
- [ ] Скрипт запуска использует переменную `PORT`
- [ ] Скрипт запуска проверяет наличие `router.php`
- [ ] Service account создан и имеет права на Container Registry
- [ ] Старые образы удалены из реестра
- [ ] Образ собран и протестирован локально
- [ ] Образ загружен в Container Registry с тегом `latest`
- [ ] Команда деплоя содержит все обязательные параметры

### 17. Полезные команды

```bash
# Получить folder-id
yc resource-manager folder list

# Получить service-account-id
yc iam service-account list

# Получить registry-id
yc container registry list

# Получить container-id и URL
yc serverless container get receipt-php

# Список ревизий
yc serverless container revision list --container-name receipt-php

# Просмотр логов
yc logging read --folder-id <FOLDER_ID> --resource-ids <CONTAINER_ID> --limit 20

# Список образов в реестре
yc container image list --registry-id <REGISTRY_ID>

# Удаление образа из реестра
yc container image delete <IMAGE_ID>
```

## Заключение

Основные проблемы при деплое в Yandex Cloud Serverless Containers:

1. **Порт 80 занят** → использовать `php -S` с переменной `PORT`
2. **Нет .htaccess** → создать `router.php`
3. **Файлы не попадают в образ** → проверить `.dockerignore`
4. **Нет прав на образ** → выдать права service account
5. **Обязательный service-account-id** → всегда указывать при деплое
6. **Неправильный формат образа** → использовать тег `latest`

Следуя этим рекомендациям, можно избежать большинства проблем при деплое.





