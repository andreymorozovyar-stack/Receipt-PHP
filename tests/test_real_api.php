<?php

/**
 * Реальный тест API с использованием тестового файла
 */

echo "========================================\n";
echo "  Реальный тест API\n";
echo "========================================\n\n";

$testFile = __DIR__ . '/fixtures/receipt_example.png';

if (!file_exists($testFile)) {
    echo "✗ Тестовый файл не найден: $testFile\n";
    exit(1);
}

echo "Тестовый файл: $testFile\n";
echo "Размер: " . number_format(filesize($testFile)) . " байт\n\n";

// Тест через curl
$ch = curl_init('http://localhost:8080/api/recognize');
$cfile = new CURLFile($testFile, 'image/png', 'receipt_example.png');
$postData = ['file' => $cfile];

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

echo "Отправка запроса к API...\n";
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "✗ Ошибка curl: $error\n";
    exit(1);
}

echo "HTTP код: $httpCode\n";
echo "Длина ответа: " . strlen($response) . " байт\n\n";

$data = json_decode($response, true);

if ($data && isset($data['success'])) {
    if ($data['success']) {
        echo "✓ API вернул успешный ответ\n\n";
        echo "Извлеченные данные:\n";
        echo "  - Номер чека: " . ($data['data']['receipt_number'] ?? 'не найден') . "\n";
        echo "  - Дата: " . ($data['data']['date'] ?? 'не найдена') . "\n";
        echo "  - Время: " . ($data['data']['time'] ?? 'не найдено') . "\n";
        echo "  - Продавец: " . ($data['data']['seller_name'] ?? 'не найден') . "\n";
        echo "  - ИНН продавца: " . ($data['data']['seller_inn'] ?? 'не найден') . "\n";
        echo "  - Услуг: " . (isset($data['data']['services']) ? count($data['data']['services']) : 0) . "\n";
        echo "  - Итоговая сумма: " . ($data['data']['total_amount'] ?? 'не найдена') . "\n";
        echo "  - Режим налогообложения: " . ($data['data']['tax_mode'] ?? 'не найден') . "\n";
        
        if (isset($data['data']['raw_text'])) {
            $textLength = strlen($data['data']['raw_text']);
            echo "  - Распознанный текст: " . number_format($textLength) . " символов\n";
        }
        
        echo "\n✓ Тест пройден успешно!\n";
    } else {
        echo "✗ API вернул ошибку: " . ($data['error'] ?? 'неизвестная ошибка') . "\n";
        echo "Ответ: " . substr($response, 0, 500) . "\n";
        exit(1);
    }
} else {
    echo "✗ Неожиданный формат ответа\n";
    echo "Ответ: " . substr($response, 0, 500) . "\n";
    exit(1);
}

echo "\n========================================\n";





