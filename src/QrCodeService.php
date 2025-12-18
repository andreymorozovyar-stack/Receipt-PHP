<?php

namespace ReceiptRecognition;

use Exception;

/**
 * Сервис для извлечения QR-кодов из изображений
 * Использует zbarimg через exec или библиотеку khanamiryan/qrcode-detector-decoder
 */
class QrCodeService
{
    private $zbarPath;
    private $useLibrary;

    public function __construct($zbarPath = 'zbarimg')
    {
        $this->zbarPath = $zbarPath;
        // Проверяем наличие библиотеки для QR-кодов
        $this->useLibrary = class_exists('Zxing\QrReader');
    }

    /**
     * Извлекает QR-код из изображения
     * 
     * @param string $imagePath Путь к изображению
     * @return string|null URL из QR-кода или null, если не найден
     */
    public function extractQrCode($imagePath)
    {
        try {
            if (!file_exists($imagePath)) {
                return null;
            }

            // Пробуем использовать библиотеку, если доступна
            if ($this->useLibrary) {
                try {
                    $qrcode = new \Zxing\QrReader($imagePath);
                    $text = $qrcode->text();
                    if ($text && strlen(trim($text)) > 0) {
                        $decoded = trim($text);
                        // Проверяем, что это похоже на URL (начинается с http:// или https://)
                        if (stripos($decoded, 'http://') === 0 || stripos($decoded, 'https://') === 0) {
                            return $decoded;
                        }
                        // Или это может быть просто текст QR-кода (например, для ФНС чека)
                        // ФНС чеки могут содержать данные в формате t=...&s=...&fn=...
                        if (preg_match('/^[t=].*[&]s=.*[&]fn=/', $decoded) || 
                            stripos($decoded, 'ofd.ru') !== false ||
                            stripos($decoded, 'nalog.ru') !== false) {
                            // Формируем ссылку на ФНС
                            if (stripos($decoded, 'http') === false) {
                                // Если это данные чека без URL, формируем ссылку
                                $decoded = 'https://www.nalog.gov.ru/rn77/program/596129272/' . urlencode($decoded);
                            }
                            return $decoded;
                        }
                        // Или это может быть просто текст QR-кода
                        return $decoded;
                    }
                } catch (Exception $e) {
                    // Библиотека не смогла распознать, пробуем zbarimg
                    error_log("QR library error: " . $e->getMessage());
                }
            }

            // Используем zbarimg через exec (fallback)
            // Проверяем, доступен ли zbarimg
            $zbarCheck = shell_exec('where zbarimg 2>&1');
            if (stripos($zbarCheck, 'not found') !== false && stripos($zbarCheck, 'not recognized') !== false) {
                // zbarimg не установлен, пробуем найти альтернативу или возвращаем null
                return null;
            }
            
            $command = escapeshellcmd($this->zbarPath) . ' -q --raw ' . escapeshellarg($imagePath) . ' 2>&1';
            $output = shell_exec($command);
            
            // Проверяем, что это не ошибка команды
            if ($output && strlen(trim($output)) > 0 && 
                stripos($output, 'not recognized') === false &&
                stripos($output, 'command not found') === false &&
                stripos($output, 'error') === false &&
                stripos($output, 'failed') === false) {
                $decoded = trim($output);
                // Если это данные чека ФНС (начинается с t= или содержит параметры чека)
                if (preg_match('/^t=/', $decoded) || 
                    (stripos($decoded, 'ofd.ru') === false && stripos($decoded, 'http') === false && strlen($decoded) > 20)) {
                    // Формируем ссылку на ФНС
                    $decoded = 'https://www.nalog.gov.ru/rn77/program/596129272/?data=' . urlencode($decoded);
                }
                return $decoded;
            }
            
            return null;
        } catch (Exception $e) {
            // QR-код не найден или ошибка чтения
            return null;
        }
    }

    /**
     * Извлекает QR-код из бинарных данных изображения
     * 
     * @param string $imageData Бинарные данные изображения
     * @param string $extension Расширение файла
     * @return string|null URL из QR-кода или null
     */
    public function extractQrCodeFromData($imageData, $extension = 'png')
    {
        $tempFile = sys_get_temp_dir() . '/' . uniqid('qr_') . '.' . $extension;
        
        try {
            file_put_contents($tempFile, $imageData);
            return $this->extractQrCode($tempFile);
        } finally {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }
}





