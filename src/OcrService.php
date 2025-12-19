<?php

namespace ReceiptRecognition;

use Exception;

/**
 * Сервис для распознавания текста с изображений с помощью Tesseract OCR
 */
class OcrService
{
    private $tesseractPath;
    private $tempDir;
    private $tessdataDir;

    public function __construct($tesseractPath = null, $tempDir = null, $tessdataDir = null)
    {
        $this->tesseractPath = $tesseractPath;
        $this->tempDir = $tempDir ?: sys_get_temp_dir();
        $this->tessdataDir = $tessdataDir;
    }

    /**
     * Распознает текст с изображения
     * 
     * @param string $imagePath Путь к изображению
     * @param array $languages Языки для распознавания (по умолчанию ['rus', 'eng'])
     * @return string Распознанный текст
     * @throws Exception
     */
    public function recognize($imagePath, $languages = ['rus', 'eng'])
    {
        try {
            if (!file_exists($imagePath)) {
                throw new Exception("Файл изображения не найден: {$imagePath}");
            }

            // Проверяем наличие библиотеки TesseractOCR
            // Проверяем не только основной класс, но и зависимые классы
            $useLibrary = class_exists('\thiagoalessio\TesseractOCR\TesseractOCR') && 
                         class_exists('\thiagoalessio\TesseractOCR\Command');
            
            // В Docker контейнерах всегда используем exec(), так как библиотека может искать Windows пути
            // Проверяем, находимся ли мы в Docker (проверка наличия /.dockerenv)
            $isDocker = file_exists('/.dockerenv');
            
            // Также проверяем, не указан ли Windows путь к tesseract (не работает в Docker)
            $hasWindowsPath = $this->tesseractPath && (
                strpos($this->tesseractPath, 'C:\\') === 0 || 
                strpos($this->tesseractPath, 'D:\\') === 0 ||
                strpos($this->tesseractPath, '.exe') !== false
            );
            
            if ($useLibrary && !$isDocker && !$hasWindowsPath) {
                try {
                    return $this->recognizeWithLibrary($imagePath, $languages);
                } catch (Exception $e) {
                    // Если библиотека не работает, используем exec() как fallback
                    // Логируем ошибку, но продолжаем работу
                    error_log("TesseractOCR library error, using exec fallback: " . $e->getMessage());
                    return $this->recognizeWithExec($imagePath, $languages);
                }
            } else {
                // Используем exec() если библиотека не установлена, в Docker, или указан Windows путь
                return $this->recognizeWithExec($imagePath, $languages);
            }
        } catch (Exception $e) {
            throw new Exception("Ошибка OCR распознавания: " . $e->getMessage());
        }
    }

    /**
     * Распознавание с использованием библиотеки thiagoalessio/tesseract_ocr
     */
    private function recognizeWithLibrary($imagePath, $languages)
    {
        try {
            $ocr = new \thiagoalessio\TesseractOCR\TesseractOCR($imagePath);
            
            $langString = implode('+', $languages);
            $ocr->lang($langString);
            
            if ($this->tesseractPath) {
                $ocr->executable($this->tesseractPath);
            }
            
            // Добавляем путь к локальной директории tessdata, если указан
            if ($this->tessdataDir && is_dir($this->tessdataDir)) {
                $ocr->tessdataDir($this->tessdataDir);
            }
            
            $ocr->psm(6);
            $ocr->oem(3);
            
            return $ocr->run();
        } catch (\Exception $e) {
            // Если библиотека не работает, пробуем через exec()
            throw new Exception("Library error, fallback to exec: " . $e->getMessage());
        }
    }

    /**
     * Распознавание через exec() (fallback)
     */
    private function recognizeWithExec($imagePath, $languages)
    {
        $langString = implode('+', $languages);
        $tempOutput = $this->tempDir . DIRECTORY_SEPARATOR . uniqid('tesseract_') . '.txt';
        
        $isWindows = stripos(PHP_OS_FAMILY, 'Windows') !== false;
        $isDocker = file_exists('/.dockerenv');

        // Определяем исполняемый файл
        if (!empty($this->tesseractPath)) {
            $tesseractCmd = $this->tesseractPath;
        } else {
            $tesseractCmd = $isWindows ? 'tesseract.exe' : 'tesseract';
        }

        // Упрощенный кавычатель: на Windows используем двойные кавычки, на *nix — escapeshellarg
        $q = function ($v) use ($isWindows) {
            return $isWindows ? '"' . $v . '"' : escapeshellarg($v);
        };

        $command = $q($tesseractCmd) . ' ' .
                   $q($imagePath) . ' ' .
                   $q($tempOutput) . ' -l ' .
                   $q($langString);

        // Добавляем tessdata, если указано
        if ($this->tessdataDir && is_dir($this->tessdataDir)) {
            $command .= ' --tessdata-dir ' . $q($this->tessdataDir);
        }

        $command .= ' 2>&1';
        
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0 && file_exists($tempOutput . '.txt')) {
            $text = file_get_contents($tempOutput . '.txt');
            @unlink($tempOutput . '.txt');
            return trim($text);
        }
        
        throw new Exception("Tesseract error: " . implode("\n", $output));
    }

    /**
     * Распознает текст из бинарных данных изображения
     * 
     * @param string $imageData Бинарные данные изображения
     * @param string $extension Расширение файла (png, jpg, jpeg)
     * @param array $languages Языки для распознавания
     * @return string Распознанный текст
     * @throws Exception
     */
    public function recognizeFromData($imageData, $extension = 'png', $languages = ['rus', 'eng'])
    {
        $tempFile = $this->tempDir . '/' . uniqid('ocr_') . '.' . $extension;
        
        try {
            file_put_contents($tempFile, $imageData);
            $text = $this->recognize($tempFile, $languages);
            return $text;
        } finally {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }
}






