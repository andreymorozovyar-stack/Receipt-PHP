#!/bin/bash
echo "========================================"
echo "Запуск сервера распознавания чеков (PHP)"
echo "========================================"
echo ""

cd "$(dirname "$0")"

# Проверка PHP
if ! command -v php &> /dev/null; then
    echo "ОШИБКА: PHP не найден"
    echo "Убедитесь, что PHP установлен и добавлен в PATH"
    exit 1
fi

# Проверка Tesseract
if ! command -v tesseract &> /dev/null; then
    echo "ПРЕДУПРЕЖДЕНИЕ: Tesseract не найден в PATH"
    echo "Убедитесь, что Tesseract установлен и путь указан в config.php"
    echo ""
fi

echo ""
echo "Сервер запускается на http://localhost:8080"
echo "Веб-интерфейс: http://localhost:8080/index.html"
echo "API: http://localhost:8080/api.php"
echo ""
echo "Нажмите Ctrl+C для остановки"
echo ""

php -S localhost:8080 router.php





