<?php

/**
 * Запуск всех тестов
 */

echo "========================================\n";
echo "  Тестирование системы распознавания чеков\n";
echo "========================================\n\n";

// Тест парсера
echo "1. Тест парсера:\n";
echo "----------------------------------------\n";
include __DIR__ . '/test_parser_simple.php';

echo "\n\n";

// Тест OCR
echo "2. Тест OCR:\n";
echo "----------------------------------------\n";
if (file_exists(__DIR__ . '/test_ocr.php')) {
    include __DIR__ . '/test_ocr.php';
} else {
    echo "⚠ test_ocr.php не найден\n";
}

echo "\n\n";

// Тест QR-кода
echo "3. Тест QR-кода:\n";
echo "----------------------------------------\n";
if (file_exists(__DIR__ . '/test_qrcode.php')) {
    include __DIR__ . '/test_qrcode.php';
} else {
    echo "⚠ test_qrcode.php не найден\n";
}

echo "\n\n";

echo "\n\n";

// Проверка синтаксиса всех PHP файлов
echo "4. Проверка синтаксиса PHP файлов:\n";
echo "----------------------------------------\n";
$files = [
    'src/OcrService.php',
    'src/Parser.php',
    'src/QrCodeService.php',
    'api.php',
];

$allOk = true;
foreach ($files as $file) {
    $fullPath = __DIR__ . '/../' . $file;
    if (file_exists($fullPath)) {
        $output = [];
        $return = 0;
        exec("php -l \"$fullPath\" 2>&1", $output, $return);
        if ($return === 0) {
            echo "✓ $file\n";
        } else {
            echo "✗ $file\n";
            echo "  " . implode("\n  ", $output) . "\n";
            $allOk = false;
        }
    } else {
        echo "✗ $file (не найден)\n";
        $allOk = false;
    }
}

echo "\n";

// Проверка структуры проекта
echo "5. Проверка структуры проекта:\n";
echo "----------------------------------------\n";
$requiredFiles = [
    'composer.json',
    'api.php',
    'index.html',
    'README.md',
    'src/OcrService.php',
    'src/Parser.php',
    'src/QrCodeService.php',
];

$baseDir = __DIR__ . '/../';
foreach ($requiredFiles as $file) {
    $fullPath = $baseDir . $file;
    if (file_exists($fullPath)) {
        echo "✓ $file\n";
    } else {
        echo "✗ $file (не найден)\n";
        $allOk = false;
    }
}

echo "\n";

// Итог
echo "========================================\n";
if ($allOk) {
    echo "✓ Все проверки пройдены успешно!\n";
} else {
    echo "⚠ Обнаружены проблемы\n";
}
echo "========================================\n";





