<?php

/**
 * Тесты для OCR сервиса (требует Tesseract)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ReceiptRecognition\OcrService;

echo "=== Тестирование OCR сервиса ===\n\n";

// Загружаем конфиг (если есть) чтобы взять путь к tesseract/tessdata
$config = [];
$configPath = __DIR__ . '/../config.php';
if (file_exists($configPath)) {
    $config = require $configPath;
}

// Определяем команду для tesseract
$tesseractCmd = $config['tesseract_path'] ?? 'tesseract';
$tesseractDir = 'C:\Program Files\Tesseract-OCR';
// Добавляем стандартный путь Tesseract в PATH, чтобы команды работали из PHP
if (is_dir($tesseractDir)) {
    $currentPath = getenv('PATH');
    if (stripos($currentPath, $tesseractDir) === false) {
        putenv('PATH=' . $tesseractDir . ';' . $currentPath);
    }
}
$tessdataDir = $config['tessdata_dir'] ?? null;

// Проверка наличия Tesseract
echo "Проверка наличия Tesseract OCR...\n";
$tesseractCheck = shell_exec('"' . $tesseractCmd . '" --version 2>&1');
if (strpos($tesseractCheck, 'tesseract') !== false) {
    echo "✓ Tesseract найден:\n$tesseractCheck\n\n";
} else {
    echo "✗ Tesseract не найден. Установите Tesseract OCR для работы системы.\n";
    echo "Windows: https://github.com/UB-Mannheim/tesseract/wiki\n";
    echo "Linux: sudo apt-get install tesseract-ocr tesseract-ocr-rus tesseract-ocr-eng\n";
    echo "macOS: brew install tesseract tesseract-lang\n\n";
    exit(1);
}

// Проверка языковых пакетов
echo "Проверка языковых пакетов...\n";
$listLangsCmd = '"' . $tesseractCmd . '" --list-langs';
if ($tessdataDir) {
    $listLangsCmd .= ' --tessdata-dir "' . $tessdataDir . '"';
}
$langsCheck = shell_exec($listLangsCmd . ' 2>&1');
if (strpos($langsCheck, 'rus') !== false && strpos($langsCheck, 'eng') !== false) {
    echo "✓ Языковые пакеты (rus, eng) установлены\n\n";
} else {
    echo "⚠ Языковые пакеты могут быть не установлены полностью\n";
    echo "Доступные языки:\n$langsCheck\n\n";
}

// Тест инициализации сервиса
echo "Тест 1: Инициализация OCR сервиса\n";
try {
    $ocrService = new OcrService($config['tesseract_path'] ?? null, null, $tessdataDir);
    echo "✓ OCR сервис инициализирован успешно\n\n";
} catch (Exception $e) {
    echo "✗ Ошибка инициализации: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Тест с тестовым изображением (если есть)
$testImagePath = __DIR__ . '/fixtures/receipt_example.png';
if (file_exists($testImagePath)) {
    echo "Тест 2: Распознавание тестового изображения\n";
    echo "Путь к изображению: $testImagePath\n";
    try {
        $text = $ocrService->recognize($testImagePath, $config['ocr_languages'] ?? ['rus', 'eng']);
        echo "✓ Распознавание выполнено успешно\n";
        echo "Распознанный текст (первые 200 символов):\n" . substr($text, 0, 200) . "...\n\n";
    } catch (Exception $e) {
        echo "✗ Ошибка распознавания: " . $e->getMessage() . "\n\n";
    }
} else {
    echo "Тест 2: Тестовое изображение не найдено\n";
    echo "Путь: $testImagePath\n";
    echo "Пропуск теста распознавания\n\n";
}

// Тест 3: recognizeFromData
echo "Тест 3: Распознавание из бинарных данных\n";
if (file_exists($testImagePath)) {
    try {
        $imageData = file_get_contents($testImagePath);
        $text = $ocrService->recognizeFromData($imageData, 'png', $config['ocr_languages'] ?? ['rus', 'eng']);
        echo "✓ Распознавание из бинарных данных выполнено успешно\n";
        echo "Распознанный текст (первые 200 символов):\n" . substr($text, 0, 200) . "...\n\n";
    } catch (Exception $e) {
        echo "✗ Ошибка распознавания из бинарных данных: " . $e->getMessage() . "\n\n";
    }
} else {
    echo "Пропуск теста (нет тестового изображения)\n\n";
}

echo "=== Тестирование OCR завершено ===\n";






