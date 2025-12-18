<?php

/**
 * Тесты для API endpoint (Slim Framework)
 */

echo "=== Тестирование API ===\n\n";

$baseUrl = 'http://localhost:8080';

// Тест 1: Health check (новый формат)
echo "Тест 1: Health Check (/api/health)\n";
$ch = curl_init($baseUrl . '/api/health');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP код: $httpCode\n";
if ($error) {
    echo "Ошибка curl: $error\n";
    echo "⚠ Сервер не запущен. Запустите: php artisan serve\n\n";
} else {
    echo "Ответ: $response\n";
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if ($data && isset($data['status']) && $data['status'] === 'ok') {
            echo "✓ Health check прошел успешно\n\n";
        } else {
            echo "✗ Health check вернул неверный формат\n\n";
        }
    } else {
        echo "✗ Health check вернул ошибку\n\n";
    }
}

// Тест 2: Проверка обработки ошибок (без файла)
echo "Тест 2: Обработка ошибок (POST без файла)\n";
$ch = curl_init($baseUrl . '/api/recognize');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP код: $httpCode\n";
echo "Ответ: " . substr($response, 0, 200) . "\n";

if ($httpCode === 400 || $httpCode === 500) {
    $data = json_decode($response, true);
    if ($data && isset($data['error'])) {
        echo "✓ Обработка ошибок работает корректно: " . $data['error'] . "\n\n";
    } else {
        echo "⚠ Обработка ошибок работает, но формат ответа неожиданный\n\n";
    }
} else {
    echo "⚠ Неожиданный HTTP код\n\n";
}

echo "=== Тестирование API завершено ===\n";
echo "\nПримечание: Для полного тестирования API с файлом используйте:\n";
echo "  - Веб-интерфейс: http://localhost:8080/\n";
echo "  - curl: curl -X POST \"$baseUrl/api/recognize\" -F \"file=@tests/fixtures/receipt_example.png\"\n";





