<?php

/**
 * API endpoint для распознавания чеков на Slim Framework
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpMethodNotAllowedException;

// Включаем отображение ошибок для отладки (в продакшене убрать)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Загрузка autoloader
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Простой autoloader для наших классов (всегда регистрируем)
spl_autoload_register(function ($class) {
    $prefix = 'ReceiptRecognition\\';
    $base_dir = __DIR__ . '/src/';
    
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

use ReceiptRecognition\OcrService;
use ReceiptRecognition\QrCodeService;
use ReceiptRecognition\Parser;

// Создаем Slim приложение
$app = AppFactory::create();

// Исправляем REQUEST_URI для работы через .htaccess
// .htaccess передает /api/health в api.php, но REQUEST_URI остается /api/health
// Нужно преобразовать его в /health для Slim маршрутов
if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/api/') === 0) {
    $_SERVER['REQUEST_URI'] = str_replace('/api', '', $_SERVER['REQUEST_URI']);
    if ($_SERVER['REQUEST_URI'] === '') {
        $_SERVER['REQUEST_URI'] = '/';
    }
}

// Middleware для CORS
$app->add(function (Request $request, $handler): Response {
    $response = $handler->handle($request);
    
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type');
});

// Обработка OPTIONS запросов
$app->options('/{routes:.+}', function (Request $request, Response $response): Response {
    return $response;
});

// Health check endpoint
$app->get('/health', function (Request $request, Response $response): Response {
    $data = [
        'status' => 'ok',
        'service' => 'receipt-recognition-php'
    ];
    
    $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
});

// Основной endpoint для распознавания
$app->post('/recognize', function (Request $request, Response $response): Response {
    try {
        $uploadedFiles = $request->getUploadedFiles();
        
        // Проверяем наличие файла
        if (!isset($uploadedFiles['file'])) {
            throw new Exception('Файл не был загружен. Проверьте, что форма отправляет файл с именем "file".');
        }
        
        $uploadedFile = $uploadedFiles['file'];
        
        // Проверяем ошибки загрузки
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'Файл превышает максимальный размер, разрешенный в php.ini',
                UPLOAD_ERR_FORM_SIZE => 'Файл превышает максимальный размер, указанный в форме',
                UPLOAD_ERR_PARTIAL => 'Файл был загружен только частично',
                UPLOAD_ERR_NO_FILE => 'Файл не был загружен',
                UPLOAD_ERR_NO_TMP_DIR => 'Отсутствует временная папка',
                UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл на диск',
                UPLOAD_ERR_EXTENSION => 'Загрузка файла была остановлена расширением PHP'
            ];
            $errorCode = $uploadedFile->getError();
            $errorMsg = $errorMessages[$errorCode] ?? 'Неизвестная ошибка загрузки (код: ' . $errorCode . ')';
            throw new Exception('Ошибка загрузки файла: ' . $errorMsg);
        }
        
        // Валидация файла
        $allowedTypes = ['image/png', 'image/jpeg', 'image/jpg'];
        $allowedExtensions = ['png', 'jpg', 'jpeg'];
        
        $clientFilename = $uploadedFile->getClientFilename();
        $fileExtension = strtolower(pathinfo($clientFilename, PATHINFO_EXTENSION));
        $fileMimeType = $uploadedFile->getClientMediaType();
        
        if (!in_array($fileExtension, $allowedExtensions) || !in_array($fileMimeType, $allowedTypes)) {
            throw new Exception('Неподдерживаемый формат файла. Используйте PNG, JPG или JPEG.');
        }
        
        $fileSize = $uploadedFile->getSize();
        if ($fileSize === 0) {
            throw new Exception('Получен пустой файл');
        }
        
        if ($fileSize > 10 * 1024 * 1024) { // 10MB
            throw new Exception('Файл слишком большой. Максимальный размер: 10MB');
        }
        
        // Читаем файл
        // Перемещаем файл во временную директорию для чтения
        $tempFile = sys_get_temp_dir() . '/' . uniqid('upload_') . '.' . $fileExtension;
        $uploadedFile->moveTo($tempFile);
        
        $imageData = file_get_contents($tempFile);
        if ($imageData === false || empty($imageData)) {
            @unlink($tempFile);
            throw new Exception('Не удалось прочитать файл');
        }
        
        // Удаляем временный файл после чтения
        @unlink($tempFile);
        
        // Загружаем конфигурацию, если есть
        $config = [];
        if (file_exists(__DIR__ . '/config.php')) {
            $config = require __DIR__ . '/config.php';
        }
        
        // Инициализируем сервисы
        // В Docker игнорируем Windows пути из config.php
        $isDocker = file_exists('/.dockerenv');
        $tesseractPath = null;
        $tessdataDir = null;
        
        if (!$isDocker) {
            // Вне Docker используем настройки из config.php
            $tesseractPath = $config['tesseract_path'] ?? null;
            $tessdataDir = $config['tessdata_dir'] ?? null;
        } else {
            // В Docker используем системный tesseract и проверяем локальный tessdata
            $localTessdata = __DIR__ . '/tessdata';
            if (is_dir($localTessdata) && count(glob($localTessdata . '/*.traineddata')) > 0) {
                $tessdataDir = $localTessdata;
            }
            // tesseractPath остается null - будет использован системный из PATH
        }
        
        $ocrService = new OcrService($tesseractPath, null, $tessdataDir);
        
        $zbarPath = $config['zbar_path'] ?? 'zbarimg';
        $qrService = new QrCodeService($zbarPath);
        
        $parser = new Parser();
        
        // Распознавание текста
        $languages = $config['ocr_languages'] ?? ['rus', 'eng'];
        $recognizedText = $ocrService->recognizeFromData($imageData, $fileExtension, $languages);
        
        if (empty($recognizedText)) {
            throw new Exception('Не удалось распознать текст с изображения');
        }
        
        // Извлечение QR-кода
        $qrUrl = $qrService->extractQrCodeFromData($imageData, $fileExtension);
        
        // Парсинг текста
        $receiptData = $parser->parseReceiptText($recognizedText);
        
        // Добавляем QR-код и исходный текст
        if ($qrUrl && (stripos($qrUrl, 'http://') === 0 || stripos($qrUrl, 'https://') === 0)) {
            $receiptData['fns_url'] = $qrUrl;
            
            // Извлекаем номер чека из QR-кода, если он не найден в тексте или найден неправильно
            // URL ФНС имеет формат: https://lknpd.nalog.ru/api/v1/receipt/{ИНН}/{НОМЕР_ЧЕКА}/print
            if (preg_match('/\/receipt\/\d+\/([A-Za-z0-9]+)\//', $qrUrl, $qrMatches)) {
                $qrReceiptNumber = $qrMatches[1];
                // Используем номер из QR-кода, если:
                // 1. Номер не найден в тексте, ИЛИ
                // 2. Найденный номер слишком короткий (< 8 символов), ИЛИ
                // 3. Найденный номер выглядит как число (только цифры, < 10 символов) - это ошибка OCR
                $currentNumber = $receiptData['receipt_number'] ?? '';
                if (empty($currentNumber) || 
                    strlen($currentNumber) < 8 ||
                    (ctype_digit($currentNumber) && strlen($currentNumber) < 10)) {
                    $receiptData['receipt_number'] = $qrReceiptNumber;
                }
            }
        } else {
            $receiptData['fns_url'] = null;
        }
        $receiptData['raw_text'] = $recognizedText;
        
        // Возвращаем результат
        $result = [
            'success' => true,
            'data' => $receiptData
        ];
        
        $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
        
    } catch (Throwable $e) {
        // Логируем ошибку
        error_log("API Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        $errorMessage = $e->getMessage();
        
        // Добавляем дополнительную информацию для отладки
        $queryParams = $request->getQueryParams();
        if (ini_get('display_errors') || isset($queryParams['debug'])) {
            $errorMessage .= " (File: " . basename($e->getFile()) . ", Line: " . $e->getLine() . ")";
        }
        
        $errorResponse = [
            'success' => false,
            'error' => $errorMessage,
            'error_type' => get_class($e)
        ];
        
        $response->getBody()->write(json_encode($errorResponse, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withStatus(400);
    }
});

// Обработка 404
$app->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/{routes:.+}', function (Request $request, Response $response): Response {
    throw new HttpNotFoundException($request);
});

// Error handler
$errorMiddleware = $app->addErrorMiddleware(true, true, true);
$errorMiddleware->setDefaultErrorHandler(function (
    Request $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
) use ($app): Response {
    $response = $app->getResponseFactory()->createResponse();
    
    $errorData = [
        'success' => false,
        'error' => $exception->getMessage(),
        'error_type' => get_class($exception)
    ];
    
    if ($displayErrorDetails) {
        $errorData['file'] = $exception->getFile();
        $errorData['line'] = $exception->getLine();
        $errorData['trace'] = $exception->getTraceAsString();
    }
    
    $statusCode = 500;
    if ($exception instanceof HttpNotFoundException) {
        $statusCode = 404;
    } elseif ($exception instanceof HttpMethodNotAllowedException) {
        $statusCode = 405;
    }
    
    $response->getBody()->write(json_encode($errorData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $response
        ->withHeader('Content-Type', 'application/json; charset=UTF-8')
        ->withStatus($statusCode);
});

// Запускаем приложение
$app->run();




