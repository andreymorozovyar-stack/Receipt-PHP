<?php
/**
 * Точка входа для Slim Framework
 * Используется для запуска через веб-сервер (Apache/Nginx)
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

// Загружаем autoloader
require __DIR__ . '/../vendor/autoload.php';

// Простой autoloader для наших классов
spl_autoload_register(function ($class) {
    $prefix = 'ReceiptRecognition\\';
    $base_dir = __DIR__ . '/../src/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// Загружаем основной файл API
require __DIR__ . '/../api.php';






