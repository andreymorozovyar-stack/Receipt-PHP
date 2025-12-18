@echo off
chcp 65001 >nul
echo ========================================
echo Запуск сервера распознавания чеков (PHP)
echo ========================================
echo.

cd /d "%~dp0"

echo Проверка PHP...
php --version >nul 2>&1
if errorlevel 1 (
    echo ОШИБКА: PHP не найден
    echo Убедитесь, что PHP установлен и добавлен в PATH
    pause
    exit /b 1
)

echo Проверка Tesseract...
if not exist "C:\Program Files\Tesseract-OCR\tesseract.exe" (
    echo ПРЕДУПРЕЖДЕНИЕ: Tesseract не найден в стандартном пути
    echo Убедитесь, что Tesseract установлен и путь указан в config.php
    echo.
)

echo.
echo Сервер запускается на http://localhost:8080
echo Веб-интерфейс: http://localhost:8080/index.html
echo API Health: http://localhost:8080/api/health
echo API Recognize: http://localhost:8080/api/recognize
echo.
echo Нажмите Ctrl+C для остановки
echo.

php -S localhost:8080 router.php

pause





