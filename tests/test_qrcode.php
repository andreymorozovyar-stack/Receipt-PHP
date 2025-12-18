<?php

/**
 * Тесты для QrCodeService
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ReceiptRecognition\QrCodeService;

echo "=== Тестирование QrCodeService ===\n\n";

// Инициализация сервиса
echo "Тест 1: Инициализация QrCodeService\n";
try {
    $qrService = new QrCodeService();
    echo "✓ QrCodeService инициализирован успешно\n\n";
} catch (Exception $e) {
    echo "✗ Ошибка инициализации: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Тест с тестовым изображением
$testImagePath = __DIR__ . '/fixtures/receipt_example.png';
if (file_exists($testImagePath)) {
    echo "Тест 2: Извлечение QR-кода из тестового изображения\n";
    echo "Путь к изображению: $testImagePath\n";
    try {
        $qrCode = $qrService->extractQrCode($testImagePath);
        if ($qrCode) {
            echo "✓ QR-код найден: " . substr($qrCode, 0, 100) . "...\n";
            echo "Длина: " . strlen($qrCode) . " символов\n\n";
        } else {
            echo "⚠ QR-код не найден (возможно, zbarimg не установлен)\n\n";
        }
    } catch (Exception $e) {
        echo "✗ Ошибка извлечения QR-кода: " . $e->getMessage() . "\n\n";
    }
} else {
    echo "Тест 2: Тестовое изображение не найдено\n";
    echo "Путь: $testImagePath\n";
    echo "Пропуск теста\n\n";
}

// Тест extractQrCodeFromData
echo "Тест 3: Извлечение QR-кода из бинарных данных\n";
if (file_exists($testImagePath)) {
    try {
        $imageData = file_get_contents($testImagePath);
        $qrCode = $qrService->extractQrCodeFromData($imageData, 'png');
        if ($qrCode) {
            echo "✓ QR-код извлечен из бинарных данных: " . substr($qrCode, 0, 100) . "...\n\n";
        } else {
            echo "⚠ QR-код не найден в бинарных данных\n\n";
        }
    } catch (Exception $e) {
        echo "✗ Ошибка: " . $e->getMessage() . "\n\n";
    }
} else {
    echo "Пропуск теста (нет тестового изображения)\n\n";
}

echo "=== Тестирование QrCodeService завершено ===\n";





