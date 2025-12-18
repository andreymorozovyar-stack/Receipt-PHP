<?php

/**
 * Тест API для проверки обработки ошибок
 */

echo "Тест обработки ошибок API\n";
echo "========================\n\n";

// Тест 1: Health check
echo "1. Health check...\n";
$ch = curl_init('http://localhost:8080/api/health');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($httpCode === 200) {
    echo "✓ Health check OK\n";
    echo "Ответ: $response\n\n";
} else {
    echo "✗ Health check failed\n";
    echo "HTTP код: $httpCode\n";
    echo "Ошибка: $error\n";
    echo "Ответ: $response\n\n";
}

// Тест 2: POST без файла (должна быть ошибка)
echo "2. POST без файла (ожидается ошибка)...\n";
$ch = curl_init('http://localhost:8080/api/recognize');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP код: $httpCode\n";
if ($error) {
    echo "Ошибка curl: $error\n";
}
echo "Ответ: $response\n";

$data = json_decode($response, true);
if ($data && isset($data['error'])) {
    echo "✓ Ошибка обработана корректно: " . $data['error'] . "\n";
} else {
    echo "⚠ Неожиданный формат ответа\n";
}

echo "\n========================\n";





