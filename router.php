<?php

/**
 * Роутер для встроенного PHP сервера
 * Позволяет использовать красивые URL и обрабатывать статические файлы
 */

$requestUri = $_SERVER['REQUEST_URI'];
$requestPath = parse_url($requestUri, PHP_URL_PATH);

// Убираем начальный слэш
$requestPath = ltrim($requestPath, '/');

// Для Slim нужно сохранить оригинальный REQUEST_URI
$originalRequestUri = $requestUri;

// Если запрос к корню или index.html
if ($requestPath === '' || $requestPath === 'index.html' || $requestPath === 'index.php') {
    // Отдаем HTML файл
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    return;
}

// Если запрос к API
if ($requestPath === 'api' || strpos($requestPath, 'api/') === 0) {
    // Очищаем буфер вывода перед загрузкой API (если есть)
    if (ob_get_level() > 0) {
        ob_clean();
    }
    // Для Slim нужно преобразовать путь:
    // api/health -> /health
    // api/recognize -> /recognize
    $slimPath = str_replace('api/', '', $requestPath);
    $slimPath = '/' . ltrim($slimPath, '/');
    if ($slimPath === '/') {
        $slimPath = '/health'; // По умолчанию health check
    }
    // Устанавливаем путь для Slim
    $_SERVER['REQUEST_URI'] = $slimPath;
    // Сохраняем оригинальный путь для Slim
    $_SERVER['SCRIPT_NAME'] = '/api';
    require __DIR__ . '/api.php';
    return;
}

// Если запрос к существующему файлу
$filePath = __DIR__ . '/' . $requestPath;
if (file_exists($filePath) && is_file($filePath)) {
    // Определяем MIME тип
    $mimeTypes = [
        'html' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
    ];
    
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';
    
    header('Content-Type: ' . $mimeType);
    readfile($filePath);
    return;
}

// 404 - файл не найден
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><title>404 - Не найдено</title></head><body>';
echo '<h1>404 - Страница не найдена</h1>';
echo '<p>Запрошенный путь: ' . htmlspecialchars($requestPath) . '</p>';
echo '<p><a href="/">Вернуться на главную</a></p>';
echo '</body></html>';






