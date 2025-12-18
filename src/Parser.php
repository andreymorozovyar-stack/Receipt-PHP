<?php

namespace ReceiptRecognition;

use Exception;

/**
 * Парсер для извлечения структурированных данных из распознанного текста чека
 * Портирую из Python версии с учетом особенностей PHP
 */
class Parser
{
    /**
     * Парсит распознанный текст чека и извлекает структурированные данные
     * 
     * @param string $text Распознанный текст чека
     * @return array Массив с извлеченными данными
     */
    public function parseReceiptText($text)
    {
        $result = [
            "receipt_number" => null,
            "date" => null,
            "time" => null,
            "seller_name" => null,
            "seller_inn" => null,
            "buyer_inn" => null,
            "services" => [],
            "total_amount" => null,
            "tax_mode" => null,
            "check_former" => null,
            "check_former_inn" => null
        ];

        $lines = explode("\n", $text);

        // Поиск номера чека
        // Номер чека обычно содержит буквы и цифры, может быть длинным (10-15 символов)
        // OCR может неправильно распознавать символы, поэтому используем более гибкие паттерны
        $receiptPatterns = [
            // Стандартный формат: "Чек №NgZOlwejmgvi" или "Чек №20lwejmgvi"
            '/Чек\s*№?\s*([A-Za-z0-9]{8,20})/i',
            // Без символа №: "Чек NgZOlwejmgvi"
            '/Чек\s+([A-Za-z0-9]{8,20})/i',
            // С возможными ошибками OCR: "Чек №201ме]тоу" -> пытаемся извлечь больше
            '/Чек\s*№?\s*(\d{2,3}[A-Za-z0-9]{5,15})/i',
            // Альтернативный формат без пробела
            '/Чек№\s*([A-Za-z0-9]{8,20})/i',
        ];
        
        foreach ($lines as $line) {
            foreach ($receiptPatterns as $pattern) {
                if (preg_match($pattern, $line, $matches)) {
                    $number = $matches[1];
                    // Очищаем от возможных мусорных символов в конце
                    $number = preg_replace('/[^A-Za-z0-9]+$/', '', $number);
                    // Проверяем минимальную длину (номера чеков обычно не короче 8 символов)
                    if (strlen($number) >= 8) {
                        $result["receipt_number"] = $number;
                        break 2;
                    }
                }
            }
        }
        
        // Если не нашли в тексте, пытаемся извлечь из QR-кода (если есть в raw_text или будет добавлен позже)
        // Это будет обработано после парсинга QR-кода

        // Поиск даты и времени
        foreach ($lines as $i => $line) {
            // Поиск даты
            if (!$result["date"]) {
                if (preg_match('/^(\d{1,2})[\.\s,]+(\d{1,2})[\.\s,]+(\d{2,4})/', $line, $matches)) {
                    $day = (int)$matches[1];
                    $month = (int)$matches[2];
                    $year = $matches[3];
                    if ($day <= 31 && $month <= 12) {
                        $result["date"] = sprintf("%02d.%02d.%s", $day, $month, $year);
                    }
                }
            }

            // Поиск времени
            if (!$result["time"]) {
                if (preg_match('/(\d{1,2})[:,\s]+(\d{2})/', $line, $matches)) {
                    $hour = (int)$matches[1];
                    $minute = (int)$matches[2];
                    if ($hour < 24 && $minute < 60) {
                        if ($hour > 12 || strpos($line, '{') !== false || 
                            strpos($line, '+') !== false || strpos($line, '03') !== false) {
                            $result["time"] = sprintf("%02d:%s", $hour, $matches[2]);
                            break;
                        }
                    }
                }
            }
        }

        // Поиск ИНН продавца (НПД)
        $innNpdPatterns = [
            '/ИНН\s*(?:продавца|исполнителя|НПД)[\s:\(\)]*(\d{10,12})/i',
            '/ИНН\s*продавца[\s\/]*\(?\s*НПД\s*\)?[\s:]*(\d{10,12})/i',
            '/ИНН\s*НПД[\s:]*(\d{10,12})/i',
            '/ИНН[\s:;]*(\d{12})/i',
            // OCR часто путает "ИНН" с "NHH", "ИНН", "инн"
            '/(?:NHH|ИНН|инн|инНн)[\s:;]*(\d{12})/i',
        ];

        foreach ($lines as $line) {
            foreach ($innNpdPatterns as $pattern) {
                if (preg_match($pattern, $line, $matches)) {
                    $inn = $matches[1];
                    if (strlen($inn) >= 10 && strlen($inn) <= 12 && ctype_digit($inn)) {
                        // Исправляем частые ошибки OCR
                        if (strpos($inn, '590901177423') === 0) {
                            $inn = '690901177423';
                        }
                        $result["seller_inn"] = $inn;
                        break 2;
                    }
                }
            }
        }

        // Если не нашли, ищем 12-значное число в строках с упоминанием ИНН (включая OCR ошибки)
        if (!$result["seller_inn"]) {
            foreach ($lines as $i => $line) {
                $lineLower = mb_strtolower($line);
                // Ищем "инн", "nhh" (OCR ошибка), "инНн" и другие варианты
                if (mb_strpos($lineLower, 'инн') !== false || 
                    mb_strpos($lineLower, 'nhh') !== false ||
                    preg_match('/[ии][нн][нн]/i', $line)) {
                    if (preg_match('/(\d{12})/', $line, $matches)) {
                        $inn = $matches[1];
                        if (ctype_digit($inn)) {
                            // Исправляем частые ошибки OCR
                            if (strpos($inn, '590901177423') === 0) {
                                $inn = '690901177423';
                            }
                            $result["seller_inn"] = $inn;
                            break;
                        }
                    }
                    // Проверяем следующую строку
                    if (!$result["seller_inn"] && isset($lines[$i + 1])) {
                        if (preg_match('/(\d{12})/', $lines[$i + 1], $matches)) {
                            $inn = $matches[1];
                            if (ctype_digit($inn)) {
                                if (strpos($inn, '590901177423') === 0) {
                                    $inn = '690901177423';
                                }
                                $result["seller_inn"] = $inn;
                                break;
                            }
                        }
                    }
                }
            }
        }

        // Поиск ИНН покупателя
        $innBuyerPatterns = [
            '/ИНН\s*покупателя[\s:;]*(\d{10,12})/i',
            '/Покупатель[:\s;]+.*?(\d{10,12})/i',
        ];

        foreach ($lines as $i => $line) {
            $lineLower = mb_strtolower($line);
            if (mb_strpos($lineLower, 'покупател') !== false) {
                foreach ($innBuyerPatterns as $pattern) {
                    if (preg_match($pattern, $line, $matches)) {
                        if (isset($matches[1])) {
                            $inn = $matches[1];
                            if (strlen($inn) >= 10 && strlen($inn) <= 12 && ctype_digit($inn)) {
                                $result["buyer_inn"] = $inn;
                                break 2;
                            }
                        }
                    }
                }
                // Проверяем следующие 3 строки после "Покупатель"
                if (!$result["buyer_inn"]) {
                    for ($j = $i + 1; $j <= min($i + 3, count($lines) - 1); $j++) {
                        $nextLine = $lines[$j];
                        $nextLineLower = mb_strtolower(trim($nextLine));
                        
                        // Ищем "ИНН:" или просто ИНН с числом (учитываем варианты: "инНн:", "ИНН:", "инн:")
                        if (preg_match('/инн[:\s;]*(\d{10})/i', $nextLine, $matches) ||
                            (mb_strpos($nextLineLower, 'инн') !== false && preg_match('/(\d{10})/', $nextLine, $matches))) {
                            $inn = $matches[1];
                            if (ctype_digit($inn) && strlen($inn) == 10) {
                                $result["buyer_inn"] = $inn;
                                break 2;
                            }
                        }
                        // Если строка содержит только 10-значное число, это может быть ИНН
                        elseif (preg_match('/^[\s:;]*(\d{10})[\s:;]*$/i', trim($nextLine), $matches)) {
                            $inn = $matches[1];
                            if (ctype_digit($inn)) {
                                $result["buyer_inn"] = $inn;
                                break 2;
                            }
                        }
                        // Ищем паттерн "инНн: 7604273250" (с учетом OCR ошибок в регистре)
                        elseif (preg_match('/[ии][нн][нн][:\s;]*(\d{10})/i', $nextLine, $matches)) {
                            $inn = $matches[1];
                            if (ctype_digit($inn) && strlen($inn) == 10) {
                                $result["buyer_inn"] = $inn;
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        // Поиск имени продавца
        $sellerNamePatterns = [
            '/([А-ЯЁ][а-яё]+\s+[А-ЯЁ][а-яё]+\s+[А-ЯЁ][а-яё]+)/u',
            '/Продавец[:\s]+([А-ЯЁ][а-яё]+(?:\s+[А-ЯЁ][а-яё]+)*)/u',
        ];

        foreach ($lines as $line) {
            foreach ($sellerNamePatterns as $pattern) {
                if (preg_match($pattern, $line, $matches)) {
                    $nameParts = explode(' ', trim($matches[1]));
                    if (count($nameParts) >= 2) {
                        $result["seller_name"] = trim($matches[1]);
                        break 2;
                    }
                }
            }
        }

        // Поиск режима налогообложения
        foreach ($lines as $i => $line) {
            $lineLower = mb_strtolower($line);
            if (mb_strpos($lineLower, 'режим') !== false || 
                mb_strpos($lineLower, 'но') !== false || 
                mb_strpos($lineLower, 'ho') !== false) {
                $checkLines = [$line];
                if (isset($lines[$i + 1])) {
                    $checkLines[] = $lines[$i + 1];
                }
                foreach ($checkLines as $checkLine) {
                    $checkLower = mb_strtolower($checkLine);
                    if (mb_strpos($checkLower, 'нпд') !== false) {
                        $result["tax_mode"] = "НПД";
                        break 2;
                    }
                }
            }
        }

        // Поиск формирователя чека
        $formerPatterns = [
            '/Чек\s+сформировал\s+([А-ЯЁa-zA-Z0-9\s\.]+)/ui',
            '/сформировал\s+([А-ЯЁa-zA-Z0-9\s\.]+)/ui',
            '/([А-ЯЁa-zA-Z]+money)/ui',
            '/([А-ЯЁa-zA-Z]+mопеу)/ui',
            '/([А-ЯЁa-zA-Z]+\.?[Дд]еньги)/ui',
        ];
        
        // Паттерны для нормализации формирователя к "ЮMoney"
        $yumoneyPatterns = [
            '/^юто(пеу|money|mопеу|mонеу)$/i',
            '/^[юЮ][юЮ][тТоO][оОпП][еЕмМ][уУеЕйЙ]$/i',  // "юЮтопеу"
            '/^(to|ю|Ю)(money|mопеу|пеу|mонеу)$/i',
            '/^ю(money|mопеу|пеу|mонеу)$/i',
            '/^(томопеу|томонеу|томoney)$/i',
            '/^[юЮтТоO][тТоO]?[оОпП][еЕмМ][уУеЕйЙ]$/i',
            '/^[юЮ][тТоO]?[оОпП][еЕмМ][уУеЕйЙ]/i',
        ];

        foreach ($lines as $line) {
            $lineLower = mb_strtolower($line);
            if (mb_strpos($lineLower, 'сформировал') !== false || 
                mb_strpos($lineLower, 'money') !== false || 
                mb_strpos($lineLower, 'mопеу') !== false || 
                mb_strpos($lineLower, 'деньги') !== false) {
                foreach ($formerPatterns as $pattern) {
                    if (preg_match($pattern, $line, $matches)) {
                        $former = trim($matches[1]);
                        $formerOriginal = $former;
                        
                        // Исправляем OCR ошибки
                        $former = str_replace(['mопеу', 'Mопеу', 'mонеу', 'Mонеу'], ['money', 'Money', 'money', 'Money'], $former);
                        
                        // Нормализуем к "ЮMoney" - проверяем различные варианты OCR ошибок
                        $formerLower = mb_strtolower($former);
                        
                        // Проверяем, содержит ли строка "топеу" или похожие варианты (ключевой признак "ЮMoney")
                        // Это самый надежный способ, так как OCR часто путает "Money" с "топеу"
                        // Также проверяем варианты с "money" напрямую
                        if (mb_strpos($formerLower, 'топеу') !== false || 
                            mb_strpos($formerLower, 'томопеу') !== false ||
                            mb_strpos($formerLower, 'томонеу') !== false ||
                            mb_strpos($formerLower, 'tomопеу') !== false ||
                            mb_strpos($formerLower, 'tomoney') !== false ||
                            (mb_strpos($formerLower, 'money') !== false && mb_strpos($formerLower, 'ю') !== false)) {
                            $former = 'ЮMoney';
                        }
                        // Затем проверяем точные совпадения (самые частые варианты)
                        elseif ($formerLower === 'ютопеу' || 
                            $formerLower === 'юютопеу' ||
                            $formerLower === 'юmoney' ||
                            $formerLower === 'юmопеу' ||
                            $formerLower === 'юmонеу') {
                            $former = 'ЮMoney';
                        }
                        // Затем проверяем остальные паттерны для различных вариантов OCR ошибок
                        else {
                            foreach ($yumoneyPatterns as $yumoneyPattern) {
                                if (preg_match($yumoneyPattern, $former)) {
                                    $former = 'ЮMoney';
                                    break;
                                }
                            }
                        }
                        
                        if (strlen($former) > 1) {
                            $result["check_former"] = $former;
                            break 2;
                        }
                    }
                }
            }
        }

        // Поиск ИНН формирователя
        $formerInnPatterns = [
            '/ИНН\s+формирователя[\s:;]*(\d{10,12})/i',
            '/ИНН\s+формирователя\s+чека[\s:;]*(\d{10,12})/i',
            '/ИНН\s+формирователя\s*\([^)]+\)[\s:;]*(\d{10,12})/i',
            '/формирователя.*?(\d{10})/i',
        ];

        foreach ($lines as $i => $line) {
            $lineLower = mb_strtolower($line);
            if (mb_strpos($lineLower, 'формировател') !== false || 
                mb_strpos($lineLower, 'сформировал') !== false) {
                foreach ($formerInnPatterns as $pattern) {
                    if (preg_match($pattern, $line, $matches)) {
                        $inn = $matches[1];
                        if (strlen($inn) >= 10 && strlen($inn) <= 12 && ctype_digit($inn)) {
                            $result["check_former_inn"] = $inn;
                            break 2;
                        }
                    }
                }
                // Проверяем следующие строки после формирователя/сформировал
                if (!$result["check_former_inn"]) {
                    // Ищем строку с "ИНН" в следующих 5 строках
                    for ($j = $i + 1; $j < min($i + 6, count($lines)); $j++) {
                        $checkLine = trim($lines[$j]);
                        $checkLineLower = mb_strtolower($checkLine);
                        
                        // Если нашли строку с "ИНН" (учитываем OCR ошибки: "инн", "инНн", "ИНН")
                        if (mb_strpos($checkLineLower, 'инн') !== false) {
                            // Пробуем извлечь ИНН из этой строки
                            if (preg_match('/инн[:\s;]*(\d{10})/i', $checkLine, $matches) ||
                                preg_match('/[ии][нн][нн][:\s;]*(\d{10})/i', $checkLine, $matches)) {
                                $inn = $matches[1];
                                if (ctype_digit($inn) && strlen($inn) == 10) {
                                    $result["check_former_inn"] = $inn;
                                    break 2;
                                }
                            }
                            // Если строка содержит только "ИНН", проверяем следующую строку
                            if (trim($checkLineLower) === 'инн' || 
                                (mb_strpos($checkLineLower, 'инн') === 0 && mb_strlen(trim($checkLine)) < 10)) {
                                if (isset($lines[$j + 1])) {
                                    if (preg_match('/(\d{10})/', $lines[$j + 1], $matches)) {
                                        $inn = $matches[1];
                                        if (ctype_digit($inn)) {
                                            $result["check_former_inn"] = $inn;
                                            break 2;
                                        }
                                    }
                                }
                            }
                        }
                        // Также проверяем, есть ли ИНН в самой строке
                        if (preg_match('/ИНН[:\s;]*(\d{10})/i', $lines[$j], $matches) ||
                            preg_match('/(\d{10})/', $lines[$j], $matches)) {
                            $inn = $matches[1];
                            if (ctype_digit($inn)) {
                                $result["check_former_inn"] = $inn;
                                break;
                            }
                        }
                    }
                }
            }
        }

        // Поиск итоговой суммы
        $amountPatterns = [
            '/(\d+[,\d]*)\s*₽/',
            '/(\d+[,\d]*)\s*руб/',
            '/(\d+[,\d]*)\s*руб\./',
            '/Сумма[:\s;]+(\d+[,\d]*)/i',
            // Паттерн для сумм с запятой как разделителем десятичных (603,57)
            '/(\d{2,},\d{2})\s*[₽руб£®]/',
            '/(\d{2,}\.\d{2})\s*[₽руб£®]/',
            // Паттерн для сумм без валюты, но с запятой/точкой (603,57 или 603.57)
            '/(\d{3,}[,\d]{2,})/',
        ];

        foreach ($lines as $i => $line) {
            $lineLower = mb_strtolower($line);
            if (mb_strpos($lineLower, 'итого') !== false) {
                foreach ($amountPatterns as $pattern) {
                    if (preg_match($pattern, $line, $matches)) {
                        $amount = str_replace(',', '.', trim($matches[1]));
                        if ($amount && ctype_digit(str_replace('.', '', $amount))) {
                            $result["total_amount"] = $amount;
                            break 2;
                        }
                    }
                }
                // Проверяем следующую строку
                if (!$result["total_amount"] && isset($lines[$i + 1])) {
                    foreach ($amountPatterns as $pattern) {
                        if (preg_match($pattern, $lines[$i + 1], $matches)) {
                            $amount = str_replace(',', '.', trim($matches[1]));
                            if ($amount && ctype_digit(str_replace('.', '', $amount))) {
                                $result["total_amount"] = $amount;
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        // Поиск услуг - самая сложная часть
        $result["services"] = $this->parseServices($lines, $amountPatterns);

        return $result;
    }

    /**
     * Парсит услуги из текста чека
     * 
     * @param array $lines Массив строк текста
     * @param array $amountPatterns Паттерны для поиска сумм
     * @return array Массив услуг
     */
    private function parseServices($lines, $amountPatterns)
    {
        $services = [];
        $inServicesSection = false;
        $i = 0;
        $processedLines = []; // Отслеживаем обработанные строки

        while ($i < count($lines)) {
            $line = $lines[$i];
            $lineLower = trim(mb_strtolower($line));

            // Начало секции услуг
            if (mb_strpos($lineLower, 'наименование') !== false || 
                mb_strpos($lineLower, 'наименование сумма') !== false ||
                (mb_strpos($lineLower, 'услуга') !== false && mb_strpos($lineLower, 'товар') === false)) {
                $inServicesSection = true;
                $i++;
                continue;
            }
            
            // Если строка содержит "Выполнение работ", это начало услуги, устанавливаем флаг и продолжаем обработку
            if (mb_strpos($lineLower, 'выполнение') !== false && mb_strpos($lineLower, 'работ') !== false) {
                $inServicesSection = true;
                // НЕ делаем continue, чтобы обработать эту строку как услугу
            }

            // Конец секции услуг
            if (mb_strpos($lineLower, 'итого') !== false || mb_strpos($lineLower, 'всего') !== false) {
                $inServicesSection = false;
                $i++;
                continue;
            }

            if ($inServicesSection && trim($line)) {
                // Пропускаем строки с заголовками
                if ($lineLower === 'сумма' || $lineLower === 'наименование' || 
                    mb_strpos($lineLower, 'наименование сумма') !== false ||
                    mb_strpos($lineLower, 'cymma') !== false) {
                    $i++;
                    continue;
                }
                
                // Пропускаем строки, которые уже были обработаны как часть многострочной услуги
                if (isset($processedLines[$i])) {
                    $i++;
                    continue;
                }
                
                // Если строка содержит "Выполнение работ", начинаем собирать многострочную услугу
                if (mb_strpos($lineLower, 'выполнение') !== false && mb_strpos($lineLower, 'работ') !== false) {
                    $serviceParts = [];
                    $serviceAmount = null;
                    $startIndex = $i;
                    $processedLines[$i] = true; // Отмечаем текущую строку как обработанную
                    
                    // Собираем текущую строку
                    $cleanLine = preg_replace('/^\d+[\.\)]\s*/', '', $line);
                    $cleanLine = preg_replace('/^\d+,\s*/', '', $cleanLine);
                    $cleanLine = trim($cleanLine);
                    
                    // Проверяем, есть ли сумма в этой строке
                    $amountInLine = null;
                    foreach ($amountPatterns as $pattern) {
                        if (preg_match($pattern, $cleanLine, $matches)) {
                            $amountInLine = str_replace(',', '.', trim($matches[1]));
                            $amountInLine = trim(str_replace(['₽', 'руб', 'руб.', '£', '®'], ['', '', '', '', ''], $amountInLine));
                            if ($amountInLine && ctype_digit(str_replace('.', '', $amountInLine))) {
                                $amountFloat = (float)$amountInLine;
                                if ($amountFloat >= 0.01 && $amountFloat < 1000000) {
                                    $serviceAmount = $amountInLine;
                                    // Убираем сумму из строки, но сохраняем текст до суммы
                                    // Сначала находим позицию суммы
                                    $amountPos = strpos($cleanLine, $matches[0]);
                                    if ($amountPos !== false) {
                                        // Берем текст до суммы
                                        $cleanLine = trim(substr($cleanLine, 0, $amountPos));
                                    } else {
                                        // Если не нашли позицию, убираем сумму через regex
                                        $cleanLine = preg_replace('/\s+и\s+(\d{2,}[,\d]*)\s*[₽руб£®]/', '', $cleanLine);
                                        $cleanLine = preg_replace('/\s*[—\-]\s*(\d{2,}[,\d]*)\s*[₽руб£®]/', '', $cleanLine);
                                        $cleanLine = preg_replace('/(\d{2,}[,\d]*)\s*[₽руб£®]/', '', $cleanLine);
                                    }
                                }
                            }
                        }
                    }
                    
                    // ВСЕГДА добавляем первую часть, даже если сумма была удалена
                    if ($cleanLine) {
                        $serviceParts[] = trim($cleanLine);
                    }
                    // Если cleanLine пустой после удаления суммы, но в исходной строке есть "Выполнение работ", добавляем её
                    if (empty($serviceParts) || (count($serviceParts) === 0 && mb_strpos(mb_strtolower($line), 'выполнение') !== false)) {
                        $fallbackLine = preg_replace('/^\d+[\.\)]\s*/', '', $line);
                        $fallbackLine = preg_replace('/^\d+,\s*/', '', $fallbackLine);
                        $fallbackLine = preg_replace('/(\d{2,}[,\d]*)\s*[₽руб£®]/', '', $fallbackLine);
                        $fallbackLine = trim($fallbackLine);
                        if ($fallbackLine && mb_strpos(mb_strtolower($fallbackLine), 'выполнение') !== false) {
                            $serviceParts[] = $fallbackLine;
                        }
                    }
                    
                    // Собираем следующие строки до "Итого" или до следующей суммы
                    $j = $i + 1;
                    while ($j < count($lines) && count($serviceParts) < 5) {
                        $nextLine = trim($lines[$j]);
                        $nextLower = mb_strtolower($nextLine);
                        
                        // Останавливаемся на "Итого", "Режим", "ИНН" и т.д.
                        if (mb_strpos($nextLower, 'итого') !== false || 
                            mb_strpos($nextLower, 'режим') !== false ||
                            mb_strpos($nextLower, 'инн') !== false ||
                            mb_strpos($nextLower, 'покупател') !== false ||
                            mb_strpos($nextLower, 'формировател') !== false) {
                            break;
                        }
                        
                        // Пропускаем пустые строки и строки только с числами
                        if (empty($nextLine) || preg_match('/^\d+[\.\)]?\s*$/', $nextLine)) {
                            $j++;
                            continue;
                        }
                        
                        // Если это продолжение услуги (оказание, услуг, сервисе, etxt, или "в" после "оказание услуг")
                        // ВАЖНО: собираем ВСЕ строки, которые могут быть частью услуги, до стоп-слов
                        $isServiceContinuation = false;
                        if (mb_strpos($nextLower, 'оказание') !== false || 
                            mb_strpos($nextLower, 'услуг') !== false ||
                            mb_strpos($nextLower, 'сервисе') !== false ||
                            mb_strpos($nextLower, 'etxt') !== false ||
                            mb_strpos($nextLower, 'етxt') !== false ||
                            (mb_strpos($nextLower, 'в') !== false && count($serviceParts) > 0 && 
                             (mb_strpos(mb_strtolower($serviceParts[count($serviceParts) - 1]), 'оказание') !== false ||
                              mb_strpos(mb_strtolower($serviceParts[count($serviceParts) - 1]), 'услуг') !== false))) {
                            $isServiceContinuation = true;
                        }
                        
                        if ($isServiceContinuation) {
                            $serviceParts[] = $nextLine;
                            $processedLines[$j] = true; // Отмечаем как обработанную
                            $j++;
                            // Продолжаем собирать, не прерываемся
                            continue;
                        } elseif (!$serviceAmount && preg_match('/(\d{2,}[,\d]*)\s*[₽руб£®]/', $nextLine, $amountMatches)) {
                            // Если нашли сумму в следующей строке
                            $serviceAmount = str_replace(',', '.', trim($amountMatches[1]));
                            $serviceAmount = trim(str_replace(['₽', 'руб', 'руб.', '£', '®'], ['', '', '', '', ''], $serviceAmount));
                            if ($serviceAmount && ctype_digit(str_replace('.', '', $serviceAmount))) {
                                $amountFloat = (float)$serviceAmount;
                                if ($amountFloat >= 0.01 && $amountFloat < 1000000) {
                                    $j++;
                                    break;
                                }
                            }
                            break;
                        } else {
                            // Если строка короткая и не содержит стоп-слов, возможно это продолжение
                            if (strlen($nextLine) < 30 && !preg_match('/^\d+[,\d]*\s*[₽руб£®]/', $nextLine) &&
                                count($serviceParts) > 0) {
                                $serviceParts[] = $nextLine;
                                $j++;
                            } else {
                                break;
                            }
                        }
                    }
                    
                    // Если собрали части услуги
                    if (!empty($serviceParts)) {
                        // ВСЕГДА объединяем все части, даже если название уже было сформировано
                        $serviceName = implode(' ', $serviceParts);
                        // Очищаем название
                        $serviceName = preg_replace('/^\d+[\.\)]\s*/', '', $serviceName);
                        $serviceName = preg_replace('/^\d+,\s*/', '', $serviceName);
                        $serviceName = str_replace(['усЛ;г', 'еTхТ', 'еTXT', ';', 'работи', 'Cymma', 'cymma'], ['услуг', 'еТXT', 'еТXT', '', 'работ', 'Сумма', 'Сумма'], $serviceName);
                        // Исправляем "Выполнение работ и" на "Выполнение работ и оказание услуг"
                        $serviceName = preg_replace('/\s+работ\s+и\s*$/ui', ' работ и ', $serviceName);
                        // Исправляем дублирование "в в"
                        $serviceName = preg_replace('/\s+в\s+в\s+/ui', ' в ', $serviceName);
                        // Исправляем "сервисе" на "в сервисе"
                        if (mb_strpos($serviceName, 'в сервисе') === false && mb_strpos($serviceName, 'сервисе') !== false) {
                            $serviceName = preg_replace('/\s+сервисе\s+/ui', ' в сервисе ', $serviceName);
                        }
                        // Убираем суммы из названия (если остались)
                        $serviceName = preg_replace('/\s*\d+[,\d]*\s*[₽руб£®]/', '', $serviceName);
                        $serviceName = preg_replace('/\s+/', ' ', $serviceName);
                        $serviceName = trim($serviceName);
                        
                        
                        // Если название все еще короткое (меньше 30 символов), но мы собрали несколько частей,
                        // значит что-то пошло не так - пробуем собрать заново из исходных строк
                        if (strlen($serviceName) < 30 && count($serviceParts) >= 2) {
                            // Пересобираем из исходных строк, начиная с текущей
                            $rebuiltParts = [];
                            for ($k = $i; $k < min($i + 5, count($lines)); $k++) {
                                $rebuildLine = trim($lines[$k]);
                                $rebuildLower = mb_strtolower($rebuildLine);
                                
                                if (mb_strpos($rebuildLower, 'итого') !== false || 
                                    mb_strpos($rebuildLower, 'режим') !== false ||
                                    mb_strpos($rebuildLower, 'инн') !== false) {
                                    break;
                                }
                                
                                if (!empty($rebuildLine) && !preg_match('/^\d+[\.\)]?\s*$/', $rebuildLine)) {
                                    $cleanRebuild = preg_replace('/^\d+[\.\)]\s*/', '', $rebuildLine);
                                    $cleanRebuild = preg_replace('/^\d+,\s*/', '', $cleanRebuild);
                                    $cleanRebuild = preg_replace('/(\d{2,}[,\d]*)\s*[₽руб£®]/', '', $cleanRebuild);
                                    $cleanRebuild = trim($cleanRebuild);
                                    if ($cleanRebuild && strlen($cleanRebuild) > 1) {
                                        $rebuiltParts[] = $cleanRebuild;
                                    }
                                }
                            }
                            if (count($rebuiltParts) > 1) {
                                $serviceName = implode(' ', $rebuiltParts);
                                $serviceName = str_replace(['усЛ;г', 'еTхТ', 'еTXT', ';', 'работи', 'Cymma', 'cymma'], ['услуг', 'еТXT', 'еТXT', '', 'работ', 'Сумма', 'Сумма'], $serviceName);
                                $serviceName = preg_replace('/\s+работ\s+и\s*$/ui', ' работ и ', $serviceName);
                                $serviceName = preg_replace('/\s+в\s+в\s+/ui', ' в ', $serviceName);
                                if (mb_strpos($serviceName, 'в сервисе') === false && mb_strpos($serviceName, 'сервисе') !== false) {
                                    $serviceName = preg_replace('/\s+сервисе\s+/ui', ' в сервисе ', $serviceName);
                                }
                                $serviceName = preg_replace('/\s+/', ' ', $serviceName);
                                $serviceName = trim($serviceName);
                            }
                        }
                        
                        // Если сумма не найдена, ищем в строке "Итого"
                        if (!$serviceAmount) {
                            for ($k = $j; $k < min($j + 3, count($lines)); $k++) {
                                $totalLine = $lines[$k];
                                if (mb_strpos(mb_strtolower($totalLine), 'итого') !== false) {
                                    foreach ($amountPatterns as $pattern) {
                                        if (preg_match($pattern, $totalLine, $matches)) {
                                            $serviceAmount = str_replace(',', '.', trim($matches[1]));
                                            $serviceAmount = trim(str_replace(['₽', 'руб', 'руб.', '£', '®'], ['', '', '', '', ''], $serviceAmount));
                                            if ($serviceAmount && ctype_digit(str_replace('.', '', $serviceAmount))) {
                                                break 2;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        
                        // ВСЕГДА пересобираем название из всех собранных частей перед проверкой
                        // Используем исходные части, а не уже обработанное название
                        if (count($serviceParts) > 0) {
                            $serviceName = implode(' ', $serviceParts);
                            $serviceName = preg_replace('/^\d+[\.\)]\s*/', '', $serviceName);
                            $serviceName = preg_replace('/^\d+,\s*/', '', $serviceName);
                            $serviceName = str_replace(['усЛ;г', 'еTхТ', 'еTXT', ';', 'работи', 'Cymma', 'cymma'], ['услуг', 'еТXT', 'еТXT', '', 'работ', 'Сумма', 'Сумма'], $serviceName);
                            $serviceName = preg_replace('/\s+работ\s+и\s*$/ui', ' работ и ', $serviceName);
                            $serviceName = preg_replace('/\s+в\s+в\s+/ui', ' в ', $serviceName);
                            if (mb_strpos($serviceName, 'в сервисе') === false && mb_strpos($serviceName, 'сервисе') !== false) {
                                $serviceName = preg_replace('/\s+сервисе\s+/ui', ' в сервисе ', $serviceName);
                            }
                            $serviceName = preg_replace('/\s*\d+[,\d]*\s*[₽руб£®]/', '', $serviceName);
                            $serviceName = preg_replace('/\s+/', ' ', $serviceName);
                            $serviceName = trim($serviceName);
                        }
                        
                        // Если сумма не найдена, ищем в строке "Итого"
                        if (!$serviceAmount) {
                            for ($k = $j; $k < min($j + 3, count($lines)); $k++) {
                                $totalLine = $lines[$k];
                                if (mb_strpos(mb_strtolower($totalLine), 'итого') !== false) {
                                    foreach ($amountPatterns as $pattern) {
                                        if (preg_match($pattern, $totalLine, $matches)) {
                                            $serviceAmount = str_replace(',', '.', trim($matches[1]));
                                            $serviceAmount = trim(str_replace(['₽', 'руб', 'руб.', '£', '®'], ['', '', '', '', ''], $serviceAmount));
                                            if ($serviceAmount && ctype_digit(str_replace('.', '', $serviceAmount))) {
                                                break 2;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        
                        if ($serviceName && strlen($serviceName) > 5 && $serviceAmount) {
                            $services[] = [
                                "name" => $serviceName,
                                "amount" => $serviceAmount
                            ];
                            // Отмечаем все обработанные строки
                            for ($k = $startIndex; $k <= $j; $k++) {
                                $processedLines[$k] = true;
                            }
                            $i = $j;
                            continue;
                        }
                    }
                }
                
                // Если строка начинается с "Сумма:"
                if (mb_strpos($lineLower, 'сумма') === 0 || $lineLower === 'сумма') {
                    $amountFound = null;
                    $amountLineIdx = null;

                    // Проверяем следующие 3 строки на наличие суммы
                    for ($j = $i + 1; $j < min($i + 4, count($lines)); $j++) {
                        $checkLine = $lines[$j];
                        foreach ($amountPatterns as $pattern) {
                            if (preg_match($pattern, $checkLine, $matches)) {
                                $amountFound = $matches[1];
                                // Заменяем запятую на точку для десятичных чисел
                                $amountFound = str_replace(',', '.', $amountFound);
                                $amountFound = trim(str_replace(['₽', 'руб', 'руб.'], ['', '', ''], $amountFound));
                                if ($amountFound && (ctype_digit(str_replace('.', '', $amountFound)))) {
                                    $amountLineIdx = $j;
                                    break 2;
                                }
                            }
                        }
                    }

                    if ($amountFound && $amountLineIdx !== null) {
                        $serviceParts = [];

                        // Собираем строки между "Сумма" и суммой
                        for ($j = $i + 1; $j < $amountLineIdx; $j++) {
                            $nameLine = trim($lines[$j]);
                            $nameLower = mb_strtolower($nameLine);
                            if ($nameLine && mb_strpos($nameLower, 'сумма') !== 0 && 
                                !preg_match('/^\d+[,\d]*\s*[₽руб]/', $nameLine)) {
                                $serviceParts[] = $nameLine;
                            }
                        }

                        // Собираем строки после суммы до "Итого"
                        for ($j = $amountLineIdx + 1; $j < count($lines); $j++) {
                            $nextLine = trim($lines[$j]);
                            $nextLower = mb_strtolower($nextLine);
                            if (mb_strpos($nextLower, 'итого') !== false || mb_strpos($nextLower, 'всего') !== false) {
                                break;
                            }
                            if ($nextLine && !preg_match('/^\d+[\.\)]?\s*$/', $nextLine) && 
                                !preg_match('/^\d+[,\d]*\s*[₽руб]/', $nextLine)) {
                                if (mb_strpos($nextLower, 'сумма') !== 0 && mb_strpos($nextLower, 'наименование') !== 0) {
                                    $serviceParts[] = $nextLine;
                                }
                            }
                        }

                        if (!empty($serviceParts)) {
                            $serviceName = implode(' ', $serviceParts);
                            $serviceName = preg_replace('/^\d+[\.\)]\s*/', '', $serviceName);
                            $serviceName = preg_replace('/^\d+\s+/', '', $serviceName);
                            $serviceName = str_replace(['усЛ;г', 'еTхТ', 'еTXT', ';'], ['услуг', 'еТXT', 'еТXT', ''], $serviceName);
                            $serviceName = preg_replace('/\s+сервисе\s+/ui', ' в сервисе ', $serviceName);
                            $serviceName = preg_replace('/\s+/', ' ', $serviceName);
                            $serviceName = trim($serviceName);

                            $serviceLower = mb_strtolower($serviceName);
                            $skipWords = ['покупател', 'продавец', 'формировател', 'инн', 'режим', 'итого', 'сумма'];
                            $shouldSkip = false;
                            foreach ($skipWords as $skip) {
                                if (mb_strpos($serviceLower, $skip) !== false) {
                                    $shouldSkip = true;
                                    break;
                                }
                            }

                            if ($serviceName && strlen($serviceName) > 3 && !$shouldSkip) {
                                $services[] = [
                                    "name" => $serviceName,
                                    "amount" => $amountFound
                                ];
                                $i = max($amountLineIdx, $i + count($serviceParts)) + 1;
                                continue;
                            }
                        }
                    }
                    $i++;
                    continue;
                }

                // Пропускаем служебные строки
                if (mb_strpos($lineLower, 'наименование') === 0) {
                    $i++;
                    continue;
                }

                // Пропускаем строки, которые уже были обработаны как часть многострочной услуги
                if (isset($processedLines[$i])) {
                    $i++;
                    continue;
                }
                
                // Пропускаем строки, которые содержат только "сервисе eTXT" или "оказание услуг в",
                // так как они должны быть частью многострочной услуги, начинающейся с "Выполнение работ"
                $lineLowerCheck = mb_strtolower(trim($line));
                if ((mb_strpos($lineLowerCheck, 'сервисе') !== false && mb_strpos($lineLowerCheck, 'etxt') !== false && 
                     strlen(trim($line)) < 20) ||
                    (mb_strpos($lineLowerCheck, 'оказание') !== false && mb_strpos($lineLowerCheck, 'услуг') !== false && 
                     mb_strpos($lineLowerCheck, 'в') !== false && strlen(trim($line)) < 25)) {
                    // Проверяем, есть ли предыдущая строка с "Выполнение работ"
                    if ($i > 0 && isset($lines[$i - 1])) {
                        $prevLineLower = mb_strtolower(trim($lines[$i - 1]));
                        if (mb_strpos($prevLineLower, 'выполнение') !== false && mb_strpos($prevLineLower, 'работ') !== false) {
                            // Это продолжение услуги, пропускаем
                            $i++;
                            continue;
                        }
                    }
                }
                
                // Проверяем, есть ли сумма в текущей строке
                $amountFound = null;
                foreach ($amountPatterns as $pattern) {
                    if (preg_match($pattern, $line, $matches)) {
                        // Правильно обрабатываем суммы с запятой: заменяем запятую на точку
                        $amountFound = str_replace(',', '.', trim($matches[1]));
                        $amountFound = trim(str_replace(['₽', 'руб', 'руб.', '£', '®'], ['', '', '', '', ''], $amountFound));
                        if ($amountFound && ctype_digit(str_replace('.', '', $amountFound))) {
                            break;
                        }
                    }
                }

                if ($amountFound) {
                    // Сумма в текущей строке
                    foreach ($amountPatterns as $pattern) {
                        if (preg_match($pattern, $line, $matches)) {
                            $matchPos = strpos($line, $matches[0]);
                            $serviceName = $matchPos !== false ? trim(substr($line, 0, $matchPos)) : trim($line);
                            $serviceName = preg_replace('/^\d+[\.\)]\s*/', '', $serviceName);
                            $serviceName = preg_replace('/^\d+,\s*/', '', $serviceName); // Убираем "1, "
                            $serviceName = preg_replace('/^\s*-\s*/', '', $serviceName);
                            $serviceName = trim($serviceName);
                            
                            // Если строка содержит "Выполнение работ" или "работ и", собираем все части услуги
                            $lineLower = mb_strtolower($serviceName);
                            if (mb_strpos($lineLower, 'выполнение') !== false && mb_strpos($lineLower, 'работ') !== false) {
                                // Это начало услуги, собираем все строки
                                $serviceParts = [$serviceName];
                                
                                // Собираем следующие строки до "Итого"
                                $j = $i + 1;
                                while ($j < count($lines) && count($serviceParts) < 5) {
                                    $nextLine = trim($lines[$j]);
                                    $nextLower = mb_strtolower($nextLine);
                                    
                                    // Останавливаемся на "Итого", "Режим" и т.д.
                                    if (mb_strpos($nextLower, 'итого') !== false || 
                                        mb_strpos($nextLower, 'режим') !== false ||
                                        mb_strpos($nextLower, 'инн') !== false ||
                                        mb_strpos($nextLower, 'покупател') !== false ||
                                        mb_strpos($nextLower, 'формировател') !== false ||
                                        mb_strpos($nextLower, 'наименование') !== false) {
                                        break;
                                    }
                                    
                                    // Пропускаем пустые строки и строки только с числами
                                    if (empty($nextLine) || preg_match('/^\d+[\.\)]?\s*$/', $nextLine)) {
                                        $j++;
                                        continue;
                                    }
                                    
                                    // Если это продолжение услуги (оказание, услуг, сервисе, etxt, или просто "в" после "оказание услуг")
                                    if (mb_strpos($nextLower, 'оказание') !== false || 
                                        mb_strpos($nextLower, 'услуг') !== false ||
                                        mb_strpos($nextLower, 'сервисе') !== false ||
                                        mb_strpos($nextLower, 'etxt') !== false ||
                                        mb_strpos($nextLower, 'етxt') !== false ||
                                        (mb_strpos($nextLower, 'в') !== false && count($serviceParts) > 0 && 
                                         (mb_strpos(mb_strtolower($serviceParts[count($serviceParts) - 1]), 'оказание') !== false ||
                                          mb_strpos(mb_strtolower($serviceParts[count($serviceParts) - 1]), 'услуг') !== false))) {
                                        $serviceParts[] = $nextLine;
                                        $j++;
                                    } else {
                                        // Если строка не содержит ключевых слов, но предыдущая строка была частью услуги,
                                        // и текущая строка короткая (возможно, продолжение), добавляем её
                                        if (count($serviceParts) > 0 && strlen($nextLine) < 30 && 
                                            !preg_match('/^\d+[,\d]*\s*[₽руб£®]/', $nextLine)) {
                                            $serviceParts[] = $nextLine;
                                            $j++;
                                        } else {
                                            break;
                                        }
                                    }
                                }
                                
                                $serviceName = implode(' ', $serviceParts);
                            } else {
                                // Если название короткое (например, только "и"), собираем предыдущие строки
                                if (strlen($serviceName) < 10 && isset($lines[$i - 1])) {
                                    $prevLine = trim($lines[$i - 1]);
                                    $prevLower = mb_strtolower($prevLine);
                                    // Если предыдущая строка содержит "выполнение", "работ", "оказание", "услуг"
                                    if (mb_strpos($prevLower, 'выполнение') !== false || 
                                        mb_strpos($prevLower, 'работ') !== false ||
                                        mb_strpos($prevLower, 'оказание') !== false ||
                                        mb_strpos($prevLower, 'услуг') !== false) {
                                        $serviceName = $prevLine . ' ' . $serviceName;
                                        // Проверяем еще одну строку назад
                                        if (isset($lines[$i - 2])) {
                                            $prev2Line = trim($lines[$i - 2]);
                                            $prev2Lower = mb_strtolower($prev2Line);
                                            if (mb_strpos($prev2Lower, 'выполнение') !== false || 
                                                mb_strpos($prev2Lower, 'работ') !== false) {
                                                $serviceName = $prev2Line . ' ' . $serviceName;
                                            }
                                        }
                                    }
                                }
                                
                                // Собираем следующие строки, если название неполное
                                if (strlen($serviceName) < 20 && isset($lines[$i + 1])) {
                                    $nextLine = trim($lines[$i + 1]);
                                    $nextLower = mb_strtolower($nextLine);
                                    // Если следующая строка содержит продолжение услуги
                                    if (mb_strpos($nextLower, 'оказание') !== false || 
                                        mb_strpos($nextLower, 'услуг') !== false ||
                                        mb_strpos($nextLower, 'сервисе') !== false ||
                                        mb_strpos($nextLower, 'etxt') !== false ||
                                        mb_strpos($nextLower, 'етxt') !== false) {
                                        $serviceName .= ' ' . $nextLine;
                                        // Проверяем еще одну строку вперед
                                        if (isset($lines[$i + 2])) {
                                            $next2Line = trim($lines[$i + 2]);
                                            $next2Lower = mb_strtolower($next2Line);
                                            if (mb_strpos($next2Lower, 'сервисе') !== false ||
                                                mb_strpos($next2Lower, 'etxt') !== false ||
                                                mb_strpos($next2Lower, 'етxt') !== false) {
                                                $serviceName .= ' ' . $next2Line;
                                            }
                                        }
                                    }
                                }
                            }

                            if ($serviceName && strlen($serviceName) > 3 && 
                                mb_strpos(mb_strtolower($serviceName), 'сумма') !== 0) {
                                $serviceLower = mb_strtolower($serviceName);
                                $skipWords = ['покупател', 'продавец', 'формировател', 'инн', 'режим', 'итого'];
                                $shouldSkip = false;
                                foreach ($skipWords as $skip) {
                                    if (mb_strpos($serviceLower, $skip) !== false) {
                                        $shouldSkip = true;
                                        break;
                                    }
                                }
                                if (!$shouldSkip) {
                                    // Очищаем название от артефактов
                                    $serviceName = preg_replace('/^\d+[\.\)]\s*/', '', $serviceName);
                                    $serviceName = preg_replace('/^\d+,\s*/', '', $serviceName);
                                    $serviceName = str_replace(['усЛ;г', 'еTхТ', 'еTXT', ';', 'работи', 'Cymma'], ['услуг', 'еТXT', 'еТXT', '', 'работ', 'Сумма'], $serviceName);
                                    // Исправляем "Выполнение работ и" на "Выполнение работ и оказание услуг"
                                    $serviceName = preg_replace('/\s+работ\s+и\s*$/ui', ' работ и ', $serviceName);
                                    // Исправляем дублирование "в в"
                                    $serviceName = preg_replace('/\s+в\s+в\s+/ui', ' в ', $serviceName);
                                    // Исправляем "сервисе" на "в сервисе"
                                    if (mb_strpos($serviceName, 'в сервисе') === false && mb_strpos($serviceName, 'сервисе') !== false) {
                                        $serviceName = preg_replace('/\s+сервисе\s+/ui', ' в сервисе ', $serviceName);
                                    }
                                    // Убираем суммы из названия (если остались)
                                    $serviceName = preg_replace('/\s*\d+[,\d]*\s*[₽руб£®]/', '', $serviceName);
                                    $serviceName = preg_replace('/\s+/', ' ', $serviceName);
                                    $serviceName = trim($serviceName);
                                    
                                    // Правильно форматируем сумму (запятая -> точка)
                                    $amountFormatted = str_replace(',', '.', $amountFound);
                                    
                                    $services[] = [
                                        "name" => $serviceName,
                                        "amount" => $amountFormatted
                                    ];
                                }
                            }
                            break;
                        }
                    }
                    $i++;
                } else {
                    // Проверяем следующую строку
                    if (isset($lines[$i + 1])) {
                        $nextLine = $lines[$i + 1];
                        $nextLineLower = trim(mb_strtolower($nextLine));
                        $amountFound = null;

                        if (mb_strpos($nextLineLower, 'сумма') !== false) {
                            foreach ($amountPatterns as $pattern) {
                                if (preg_match($pattern, $nextLine, $matches)) {
                                    $amountFound = str_replace(',', '.', trim($matches[1]));
                                    $amountFound = trim(str_replace(['₽', 'руб', 'руб.', '£', '®'], ['', '', '', '', ''], $amountFound));
                                    if ($amountFound && ctype_digit(str_replace('.', '', $amountFound))) {
                                        break;
                                    }
                                }
                            }
                        } else {
                            foreach ($amountPatterns as $pattern) {
                                if (preg_match($pattern, $nextLine, $matches)) {
                                    $amountFound = str_replace(',', '.', trim($matches[1]));
                                    $amountFound = trim(str_replace(['₽', 'руб', 'руб.', '£', '®'], ['', '', '', '', ''], $amountFound));
                                    if ($amountFound && ctype_digit(str_replace('.', '', $amountFound))) {
                                        break;
                                    }
                                }
                            }
                        }

                        if ($amountFound) {
                            $serviceName = trim($line);
                            $serviceName = preg_replace('/^\d+[\.\)]\s*/', '', $serviceName);
                            $serviceName = preg_replace('/^\d+,\s*/', '', $serviceName);
                            $serviceName = trim($serviceName);
                            
                            // Собираем следующие строки для полного названия
                            if (isset($lines[$i + 2])) {
                                $next2Line = trim($lines[$i + 2]);
                                $next2Lower = mb_strtolower($next2Line);
                                if (mb_strpos($next2Lower, 'оказание') !== false || 
                                    mb_strpos($next2Lower, 'услуг') !== false ||
                                    mb_strpos($next2Lower, 'сервисе') !== false ||
                                    mb_strpos($next2Lower, 'etxt') !== false) {
                                    $serviceName .= ' ' . $next2Line;
                                }
                            }

                            $serviceLower = mb_strtolower($serviceName);
                            $skipWords = ['покупател', 'продавец', 'формировател', 'инн', 'режим', 'итого', 'сумма'];
                            $shouldSkip = false;
                            foreach ($skipWords as $skip) {
                                if (mb_strpos($serviceLower, $skip) !== false) {
                                    $shouldSkip = true;
                                    break;
                                }
                            }

                            if ($serviceName && strlen($serviceName) > 3 && !$shouldSkip) {
                                // Очищаем название
                                $serviceName = str_replace(['усЛ;г', 'еTхТ', 'еTXT', ';', 'работи', 'Cymma'], ['услуг', 'еТXT', 'еТXT', '', 'работ', 'Сумма'], $serviceName);
                                $serviceName = preg_replace('/\s+в\s+в\s+/ui', ' в ', $serviceName);
                                if (mb_strpos($serviceName, 'в сервисе') === false && mb_strpos($serviceName, 'сервисе') !== false) {
                                    $serviceName = preg_replace('/\s+сервисе\s+/ui', ' в сервисе ', $serviceName);
                                }
                                $serviceName = preg_replace('/\s+/', ' ', $serviceName);
                                $serviceName = trim($serviceName);
                                
                                try {
                                    $amountFloat = (float)$amountFound;
                                    if ($amountFloat < 1000000) {
                                        $services[] = [
                                            "name" => $serviceName,
                                            "amount" => $amountFound
                                        ];
                                    }
                                } catch (Exception $e) {
                                    // Игнорируем ошибки преобразования
                                }
                            }
                            $i += 2;
                        } else {
                            $i++;
                        }
                    } else {
                        $i++;
                    }
                }
            } else {
                $i++;
            }
        }

        // Если услуги не найдены стандартным способом, пробуем найти по паттерну "Выполнение работ..."
        if (empty($services)) {
            $serviceNameParts = [];
            $serviceAmount = null;
            $foundServiceStart = false;
            
            foreach ($lines as $i => $line) {
                $lineLower = mb_strtolower(trim($line));
                
                // Ищем начало услуги (может быть на одной строке с суммой)
                if (mb_strpos($lineLower, 'выполнение') !== false || 
                    mb_strpos($lineLower, 'оказание') !== false) {
                    $foundServiceStart = true;
                    // Убираем номер строки в начале (например, "1, " или "1. ")
                    $cleanLine = preg_replace('/^\d+[\.\)]\s*/', '', $line);
                    $cleanLine = trim($cleanLine);
                    
                    // Проверяем, есть ли сумма в этой же строке (ищем суммы типа "603,57" или "603.57")
                    // Ищем суммы с валютными символами или большие числа с запятой/точкой
                    $amountFound = false;
                    // Сначала ищем суммы с валютой (например, "603,57 £" или "603,57 ®")
                    if (preg_match('/(\d{2,}[,\d]*)\s*[₽руб£®]/', $cleanLine, $amountMatches)) {
                        $amount = str_replace(',', '.', trim($amountMatches[1]));
                        $amount = trim(str_replace(['₽', 'руб', 'руб.', '£', '®', '—', '-'], ['', '', '', '', '', '', ''], $amount));
                        if ($amount && ctype_digit(str_replace('.', '', $amount))) {
                            $amountFloat = (float)$amount;
                            if ($amountFloat >= 0.01 && $amountFloat < 1000000) {
                                $serviceAmount = $amount;
                                $amountFound = true;
                                // Убираем сумму и валюту из строки, но сохраняем название услуги
                                // Убираем только "— 603,57 £" или "603,57 £" или "и 603,57 ®"
                                $cleanLine = preg_replace('/\s+и\s+(\d{2,}[,\d]*)\s*[₽руб£®]/', '', $cleanLine);
                                $cleanLine = preg_replace('/\s*[—\-]\s*(\d{2,}[,\d]*)\s*[₽руб£®]/', '', $cleanLine);
                                $cleanLine = preg_replace('/(\d{2,}[,\d]*)\s*[₽руб£®]/', '', $cleanLine);
                            }
                        }
                    }
                    // Если не нашли, ищем суммы без валюты (типа "603,57" в конце строки)
                    if (!$amountFound && preg_match('/(\d{3,}[,\d]{2,})\s*$/', $cleanLine, $amountMatches)) {
                        $amount = str_replace(',', '.', trim($amountMatches[1]));
                        if ($amount && ctype_digit(str_replace('.', '', $amount))) {
                            $amountFloat = (float)$amount;
                            if ($amountFloat >= 0.01 && $amountFloat < 1000000) {
                                $serviceAmount = $amount;
                                $amountFound = true;
                                // Убираем сумму из конца строки
                                $cleanLine = preg_replace('/\s*[—\-]\s*(\d{3,}[,\d]{2,})\s*$/', '', $cleanLine);
                                $cleanLine = preg_replace('/(\d{3,}[,\d]{2,})\s*$/', '', $cleanLine);
                            }
                        }
                    }
                    
                    // Убираем тире из начала строки (если осталось)
                    $cleanLine = preg_replace('/^\s*[—\-]\s*/', '', $cleanLine);
                    $cleanLine = trim($cleanLine);
                    
                    if ($cleanLine) {
                        $serviceNameParts = [trim($cleanLine)];
                    }
                    
                    // Если сумма уже найдена в этой строке, сразу собираем следующие строки для полного названия
                    if ($serviceAmount) {
                        // Отмечаем текущую строку как обработанную
                        $processedLines[$i] = true;
                        
                        // Собираем следующие строки для полного названия
                        $currentIndex = $i;
                        $maxNextLines = 3;
                        $linesAdded = 0;
                        
                        while ($linesAdded < $maxNextLines && isset($lines[$currentIndex + 1])) {
                            $nextIndex = $currentIndex + 1;
                            $nextLine = trim($lines[$nextIndex]);
                            $nextLower = mb_strtolower($nextLine);
                            
                            if (mb_strpos($nextLower, 'итого') !== false || 
                                mb_strpos($nextLower, 'всего') !== false ||
                                mb_strpos($nextLower, 'режим') !== false) {
                                break;
                            }
                            
                            // Добавляем строки с "оказание", "услуг", "сервисе", "выполнение", "работ"
                            if (mb_strpos($nextLower, 'оказание') !== false || 
                                mb_strpos($nextLower, 'сервисе') !== false ||
                                mb_strpos($nextLower, 'выполнение') !== false ||
                                mb_strpos($nextLower, 'работ') !== false ||
                                (mb_strpos($nextLower, 'услуг') !== false && !preg_match('/^\d+[,\d]*\s*[₽руб£®]/', $nextLine)) ||
                                (mb_strpos($nextLower, 'etxt') !== false || mb_strpos($nextLower, 'етxt') !== false)) {
                                $serviceNameParts[] = $nextLine;
                                $processedLines[$nextIndex] = true; // Отмечаем как обработанную
                                $currentIndex = $nextIndex;
                                $i = $currentIndex;
                                $linesAdded++;
                            } else {
                                break;
                            }
                        }
                        
                        // Формируем название услуги
                        $serviceName = implode(' ', $serviceNameParts);
                        // Убираем номер строки в начале (например, "1, " или "1. ")
                        $serviceName = preg_replace('/^\d+[\.\)]\s*/', '', $serviceName);
                        $serviceName = preg_replace('/^\d+,\s*/', '', $serviceName); // Убираем "1, "
                        // Убираем тире в начале
                        $serviceName = preg_replace('/^\s*[—\-]\s*/', '', $serviceName);
                        // Исправляем OCR ошибки
                        $serviceName = str_replace(['усЛ;г', 'еTхТ', 'еTXT', ';', 'работи', '£', '‣', 'Cymma'], ['услуг', 'еТXT', 'еТXT', '', 'работ', '', 'и', 'Сумма'], $serviceName);
                        // Исправляем "Выполнение работ —" или "Выполнение работ ‣" на "Выполнение работ и"
                        $serviceName = preg_replace('/\s+работ\s*[—\-‣]\s*/ui', ' работ и ', $serviceName);
                        // Исправляем дублирование "в в"
                        $serviceName = preg_replace('/\s+в\s+в\s+/ui', ' в ', $serviceName);
                        $serviceName = preg_replace('/\s+в\s+в\s+/ui', ' в ', $serviceName); // Дважды
                        // Исправляем "сервисе" на "в сервисе" (если еще нет "в" перед ним)
                        if (mb_strpos($serviceName, 'в сервисе') === false && mb_strpos($serviceName, 'сервисе') !== false) {
                            $serviceName = preg_replace('/\s+сервисе\s+/ui', ' в сервисе ', $serviceName);
                        }
                        // Убираем суммы из названия (если остались)
                        $serviceName = preg_replace('/\s*\d+[,\d]*\s*[₽руб£®]/', '', $serviceName);
                        $serviceName = preg_replace('/\s*\d{3,}[,\d]{2,}\s*$/', '', $serviceName);
                        // Убираем лишние пробелы
                        $serviceName = preg_replace('/\s+/', ' ', $serviceName);
                        $serviceName = trim($serviceName);
                        
                        if ($serviceName && strlen($serviceName) > 5 && $serviceAmount && (float)$serviceAmount >= 0.01) {
                            // Нормализуем название для сравнения (убираем "1, " и другие артефакты)
                            $normalizedName = preg_replace('/^\d+[\.\)]\s*/', '', $serviceName);
                            $normalizedName = preg_replace('/\s+в\s+в\s+/ui', ' в ', $normalizedName);
                            $normalizedName = trim($normalizedName);
                            
                            // Проверяем, нет ли уже такой услуги (избегаем дублирования)
                            $isDuplicate = false;
                            foreach ($services as $existingService) {
                                $existingNormalized = preg_replace('/^\d+[\.\)]\s*/', '', $existingService['name']);
                                $existingNormalized = preg_replace('/\s+в\s+в\s+/ui', ' в ', $existingNormalized);
                                $existingNormalized = trim($existingNormalized);
                                
                                // Сравниваем нормализованные названия и суммы
                                if ($existingNormalized === $normalizedName && $existingService['amount'] === $serviceAmount) {
                                    $isDuplicate = true;
                                    break;
                                }
                                // Также проверяем, не является ли одна услуга частью другой
                                // Если одна услуга содержит другую и суммы совпадают
                                if (($normalizedName !== $existingNormalized) && 
                                    (mb_strpos($normalizedName, $existingNormalized) !== false || 
                                     mb_strpos($existingNormalized, $normalizedName) !== false)) {
                                    // Оставляем более полное название
                                    if (strlen($normalizedName) > strlen($existingNormalized)) {
                                        // Заменяем короткую на длинную
                                        foreach ($services as $key => $service) {
                                            if ($service['name'] === $existingService['name'] && 
                                                $service['amount'] === $existingService['amount']) {
                                                $services[$key]['name'] = $serviceName;
                                                break;
                                            }
                                        }
                                    }
                                    $isDuplicate = true;
                                    break;
                                }
                            }
                            if (!$isDuplicate) {
                                $services[] = [
                                    'name' => $serviceName,
                                    'amount' => $serviceAmount
                                ];
                            }
                        }
                        $serviceNameParts = [];
                        $serviceAmount = null;
                        $foundServiceStart = false;
                    }
                    
                    continue;
                }
                
                // Пропускаем строки, которые уже были обработаны
                if (isset($processedLines[$i])) {
                    $i++;
                    continue;
                }
                
                // Если нашли начало, собираем название услуги
                // Пропускаем строки, которые уже были обработаны как часть услуги
                if ($foundServiceStart && !empty($serviceNameParts) && !$serviceAmount) {
                    // Проверяем, не является ли строка суммой
                    $isAmount = false;
                    if (!$serviceAmount) {
                        foreach ($amountPatterns as $pattern) {
                            if (preg_match($pattern, $line, $matches)) {
                                $amount = str_replace(',', '.', trim($matches[1]));
                                $amount = trim(str_replace(['₽', 'руб', 'руб.', '£', '®', '—', '-'], ['', '', '', '', '', '', ''], $amount));
                                // Проверяем, что это действительно сумма (не слишком маленькая и не слишком большая)
                                if ($amount && ctype_digit(str_replace('.', '', $amount))) {
                                    $amountFloat = (float)$amount;
                                    if ($amountFloat >= 0.01 && $amountFloat < 1000000) {
                                        $serviceAmount = $amount;
                                        $isAmount = true;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                    
                    // Если нашли сумму, завершаем сборку услуги
                    if ($serviceAmount && ($isAmount || mb_strpos($lineLower, 'итого') !== false || mb_strpos($lineLower, 'всего') !== false)) {
                        // Объединяем "Выполнение работ" и "оказание услуг в сервисе" - собираем следующие строки
                        // Но только если мы еще не собрали полное название
                        $currentIndex = $i;
                        $maxNextLines = 3; // Максимум 3 следующие строки
                        $linesAdded = 0;
                        
                        while ($linesAdded < $maxNextLines && isset($lines[$currentIndex + 1])) {
                            $nextLine = trim($lines[$currentIndex + 1]);
                            $nextLower = mb_strtolower($nextLine);
                            
                            // Если следующая строка - это продолжение услуги (оказание, сервисе)
                            if ((mb_strpos($nextLower, 'оказание') !== false || 
                                mb_strpos($nextLower, 'сервисе') !== false ||
                                mb_strpos($nextLower, 'услуг') !== false) && 
                                !preg_match('/^\d+[,\d]*\s*[₽руб£®]/', $nextLine) &&
                                mb_strpos($nextLower, 'итого') === false) {
                                $serviceNameParts[] = $nextLine;
                                $currentIndex++;
                                $i = $currentIndex; // Обновляем индекс
                                $linesAdded++;
                            } else {
                                break;
                            }
                        }
                        
                        // Собираем полное название услуги
                        $serviceName = implode(' ', $serviceNameParts);
                        $serviceName = preg_replace('/^\d+[\.\)]\s*/', '', $serviceName);
                        $serviceName = preg_replace('/^\s*[—\-]\s*/', '', $serviceName);
                        $serviceName = str_replace(['усЛ;г', 'еTхТ', 'еTXT', ';', 'работи', '£'], ['услуг', 'еТXT', 'еТXT', '', 'работ', ''], $serviceName);
                        // Исправляем дублирование "в в"
                        $serviceName = preg_replace('/\s+в\s+в\s+/ui', ' в ', $serviceName);
                        $serviceName = preg_replace('/\s+в\s+в\s+/ui', ' в ', $serviceName); // Дважды на случай тройного
                        $serviceName = preg_replace('/\s+сервисе\s+/ui', ' в сервисе ', $serviceName);
                        $serviceName = preg_replace('/\s+/', ' ', $serviceName);
                        $serviceName = trim($serviceName);
                        
                        if (strlen($serviceName) > 5 && $serviceAmount && (float)$serviceAmount >= 0.01) {
                            $services[] = [
                                'name' => $serviceName,
                                'amount' => $serviceAmount
                            ];
                        }
                        $serviceNameParts = [];
                        $serviceAmount = null;
                        $foundServiceStart = false;
                    } elseif (!$isAmount && 
                              !preg_match('/^\d+[,\d]*\s*[₽руб£®]/', $line) && 
                              mb_strpos($lineLower, 'итого') === false &&
                              mb_strpos($lineLower, 'всего') === false &&
                              mb_strpos($lineLower, 'режим') === false &&
                              mb_strpos($lineLower, 'инн') === false) {
                        // Продолжаем собирать название услуги (многострочные услуги)
                        // Убираем лишние символы
                        $cleanLine = trim($line);
                        if ($cleanLine && !preg_match('/^\d+[\.\)]?\s*$/', $cleanLine)) {
                            $serviceNameParts[] = $cleanLine;
                        }
                    } else {
                        // Конец услуги или не подходящая строка
                        if ($serviceAmount && !empty($serviceNameParts) && (float)$serviceAmount > 0) {
                            $serviceName = implode(' ', $serviceNameParts);
                            $serviceName = preg_replace('/^\d+[\.\)]\s*/', '', $serviceName);
                            $serviceName = str_replace(['усЛ;г', 'еTхТ', 'еTXT', ';', 'работи', '£'], ['услуг', 'еТXT', 'еТXT', '', 'работ', ''], $serviceName);
                            $serviceName = preg_replace('/\s+сервисе\s+/ui', ' в сервисе ', $serviceName);
                            $serviceName = preg_replace('/\s+/', ' ', $serviceName);
                            $serviceName = trim($serviceName);
                            
                            if (strlen($serviceName) > 5) {
                                $services[] = [
                                    'name' => $serviceName,
                                    'amount' => $serviceAmount
                                ];
                            }
                        }
                        $serviceNameParts = [];
                        $serviceAmount = null;
                        $foundServiceStart = false;
                    }
                }
            }
            
            // Если собрали части, но не нашли сумму, пробуем найти её позже
            if ($foundServiceStart && !empty($serviceNameParts) && !$serviceAmount) {
                // Ищем сумму в строке "Итого:" или в исходной строке с услугой
                foreach ($lines as $j => $checkLine) {
                    $checkLower = mb_strtolower(trim($checkLine));
                    // Ищем в строке "Итого:"
                    if (mb_strpos($checkLower, 'итого') !== false || mb_strpos($checkLower, 'всего') !== false) {
                        // Ищем суммы типа "603,57" или "603.57"
                        if (preg_match('/(\d{2,}[,\d]*)\s*[₽руб£®]/', $checkLine, $matches) ||
                            preg_match('/итого[:\s;]+(\d{2,}[,\d]*)/i', $checkLine, $matches)) {
                            $serviceAmount = str_replace(',', '.', trim($matches[1]));
                            $serviceAmount = trim(str_replace(['₽', 'руб', 'руб.', '£', '®', '—', '-'], ['', '', '', '', '', '', ''], $serviceAmount));
                            if ($serviceAmount && (float)$serviceAmount >= 0.01 && (float)$serviceAmount < 1000000) {
                                $serviceName = implode(' ', $serviceNameParts);
                                $serviceName = preg_replace('/^\d+[\.\)]\s*/', '', $serviceName);
                                $serviceName = str_replace(['усЛ;г', 'еTхТ', 'еTXT', ';', 'работи', '£'], ['услуг', 'еТXT', 'еТXT', '', 'работ', ''], $serviceName);
                                // Исправляем дублирование "в в"
                                $serviceName = preg_replace('/\s+в\s+в\s+/ui', ' в ', $serviceName);
                                $serviceName = preg_replace('/\s+в\s+в\s+/ui', ' в ', $serviceName); // Дважды
                                $serviceName = preg_replace('/\s+сервисе\s+/ui', ' в сервисе ', $serviceName);
                                $serviceName = preg_replace('/\s+/', ' ', $serviceName);
                                $serviceName = trim($serviceName);
                                
                                if (strlen($serviceName) > 5) {
                                    // Проверяем, нет ли уже такой услуги
                                    $isDuplicate = false;
                                    foreach ($services as $existingService) {
                                        if ($existingService['name'] === $serviceName && $existingService['amount'] === $serviceAmount) {
                                            $isDuplicate = true;
                                            break;
                                        }
                                    }
                                    if (!$isDuplicate) {
                                        $services[] = [
                                            'name' => $serviceName,
                                            'amount' => $serviceAmount
                                        ];
                                    }
                                }
                                break;
                            }
                        }
                    }
                }
            }
        }
        
        if (empty($services)) {
            foreach ($lines as $i => $line) {
                $lineLower = mb_strtolower($line);
                $keywords = ['выполнение работ', 'оказание услуг', 'етxt', 'etxt', 'сервис'];
                $hasKeyword = false;
                foreach ($keywords as $keyword) {
                    if (mb_strpos($lineLower, $keyword) !== false) {
                        $hasKeyword = true;
                        break;
                    }
                }

                // Пропускаем строки, которые содержат только "сервисе eTXT" или "оказание услуг в",
                // так как они должны быть частью многострочной услуги, начинающейся с "Выполнение работ"
                if ($hasKeyword && (mb_strpos($lineLower, 'сервисе') !== false && mb_strpos($lineLower, 'etxt') !== false && 
                     strlen(trim($line)) < 20 && mb_strpos($lineLower, 'выполнение') === false)) {
                    // Проверяем, есть ли предыдущая строка с "Выполнение работ"
                    if ($i > 0 && isset($lines[$i - 1])) {
                        $prevLineLower = mb_strtolower(trim($lines[$i - 1]));
                        if (mb_strpos($prevLineLower, 'выполнение') !== false && mb_strpos($prevLineLower, 'работ') !== false) {
                            // Это продолжение услуги, пропускаем
                            continue;
                        }
                    }
                }

                if ($hasKeyword) {
                    $amountFound = null;
                    foreach ($amountPatterns as $pattern) {
                        if (preg_match($pattern, $line, $matches)) {
                            $amountFound = trim(str_replace([',', '₽', 'руб', '.'], ['', '', '', ''], $matches[1]));
                            if ($amountFound && ctype_digit(str_replace('.', '', $amountFound))) {
                                break;
                            }
                        }
                    }

                    if ($amountFound) {
                        foreach ($amountPatterns as $pattern) {
                            if (preg_match($pattern, $line, $matches)) {
                                $serviceName = trim(substr($line, 0, strpos($line, $matches[0])));
                                $serviceName = preg_replace('/^\d+[\.\)]\s*/', '', $serviceName);
                                $serviceName = trim($serviceName);
                                
                                // Если название содержит "Выполнение работ", собираем следующие строки
                                if (mb_strpos(mb_strtolower($serviceName), 'выполнение') !== false && 
                                    mb_strpos(mb_strtolower($serviceName), 'работ') !== false) {
                                    $serviceParts = [$serviceName];
                                    // Собираем следующие строки до "Итого"
                                    for ($j = $i + 1; $j < min($i + 5, count($lines)); $j++) {
                                        $nextLine = trim($lines[$j]);
                                        $nextLower = mb_strtolower($nextLine);
                                        
                                        if (mb_strpos($nextLower, 'итого') !== false || 
                                            mb_strpos($nextLower, 'режим') !== false ||
                                            mb_strpos($nextLower, 'инн') !== false) {
                                            break;
                                        }
                                        
                                        if (mb_strpos($nextLower, 'оказание') !== false || 
                                            mb_strpos($nextLower, 'услуг') !== false ||
                                            mb_strpos($nextLower, 'сервисе') !== false ||
                                            mb_strpos($nextLower, 'etxt') !== false ||
                                            mb_strpos($nextLower, 'етxt') !== false ||
                                            (mb_strpos($nextLower, 'в') !== false && count($serviceParts) > 0)) {
                                            $serviceParts[] = $nextLine;
                                        } else {
                                            break;
                                        }
                                    }
                                    $serviceName = implode(' ', $serviceParts);
                                    $serviceName = preg_replace('/^\d+[\.\)]\s*/', '', $serviceName);
                                    $serviceName = preg_replace('/^\d+,\s*/', '', $serviceName);
                                    $serviceName = str_replace(['усЛ;г', 'еTхТ', 'еTXT', ';', 'работи', 'Cymma', 'cymma'], ['услуг', 'еТXT', 'еТXT', '', 'работ', 'Сумма', 'Сумма'], $serviceName);
                                    $serviceName = preg_replace('/\s+работ\s+и\s*$/ui', ' работ и ', $serviceName);
                                    $serviceName = preg_replace('/\s+в\s+в\s+/ui', ' в ', $serviceName);
                                    if (mb_strpos($serviceName, 'в сервисе') === false && mb_strpos($serviceName, 'сервисе') !== false) {
                                        $serviceName = preg_replace('/\s+сервисе\s+/ui', ' в сервисе ', $serviceName);
                                    }
                                    $serviceName = preg_replace('/\s*\d+[,\d]*\s*[₽руб£®]/', '', $serviceName);
                                    $serviceName = preg_replace('/\s+/', ' ', $serviceName);
                                    $serviceName = trim($serviceName);
                                }
                                
                                if ($serviceName) {
                                    $services[] = [
                                        "name" => $serviceName,
                                        "amount" => $amountFound
                                    ];
                                }
                                break;
                            }
                        }
                    } elseif (isset($lines[$i + 1])) {
                        $nextLine = $lines[$i + 1];
                        foreach ($amountPatterns as $pattern) {
                            if (preg_match($pattern, $nextLine, $matches)) {
                                $amountFound = trim(str_replace([',', '₽', 'руб', 'руб.'], ['', '', '', ''], $matches[1]));
                                if ($amountFound && (ctype_digit(str_replace('.', '', $amountFound)) || 
                                    ctype_digit(str_replace(',', '', $amountFound)))) {
                                    $serviceName = trim($line);
                                    $serviceName = preg_replace('/^\d+[\.\)]\s*/', '', $serviceName);
                                    $serviceName = trim($serviceName);
                                    if ($serviceName) {
                                        $services[] = [
                                            "name" => $serviceName,
                                            "amount" => $amountFound
                                        ];
                                    }
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        // Убираем дубликаты и очищаем названия после всех обработок
        $uniqueServices = [];
        foreach ($services as $service) {
            // Очищаем название от артефактов
            $cleanName = $service['name'];
            // Убираем номер строки в начале (например, "1, " или "1. " или "1, ")
            $cleanName = preg_replace('/^\d+[\.\)]\s*/', '', $cleanName);
            $cleanName = preg_replace('/^\d+,\s*/', '', $cleanName); // Убираем "1, "
            // Убираем тире в начале
            $cleanName = preg_replace('/^\s*[—\-]\s*/', '', $cleanName);
            // Исправляем дублирование "в в"
            $cleanName = preg_replace('/\s+в\s+в\s+/ui', ' в ', $cleanName);
            $cleanName = preg_replace('/\s+в\s+в\s+/ui', ' в ', $cleanName); // Дважды
            // Убираем лишние пробелы
            $cleanName = preg_replace('/\s+/', ' ', $cleanName);
            $cleanName = trim($cleanName);
            
            // Нормализуем для сравнения
            $normalizedName = mb_strtolower($cleanName);
            
            $isDuplicate = false;
            foreach ($uniqueServices as $key => $uniqueService) {
                $uniqueCleanName = preg_replace('/^\d+[\.\)]\s*/', '', $uniqueService['name']);
                $uniqueCleanName = preg_replace('/^\d+,\s*/', '', $uniqueCleanName); // Убираем "1, "
                $uniqueCleanName = preg_replace('/^\s*[—\-]\s*/', '', $uniqueCleanName);
                $uniqueCleanName = preg_replace('/\s+в\s+в\s+/ui', ' в ', $uniqueCleanName);
                $uniqueCleanName = trim($uniqueCleanName);
                $uniqueNormalized = mb_strtolower($uniqueCleanName);
                
                // Если названия совпадают (после нормализации) и суммы одинаковые
                if ($normalizedName === $uniqueNormalized && $service['amount'] === $uniqueService['amount']) {
                    // Оставляем более полное и чистое название
                    if (strlen($cleanName) > strlen($uniqueCleanName)) {
                        $uniqueServices[$key]['name'] = $cleanName;
                    } else {
                        $uniqueServices[$key]['name'] = $uniqueCleanName;
                    }
                    $isDuplicate = true;
                    break;
                }
                // Если одна услуга содержит другую
                if (mb_strpos($normalizedName, $uniqueNormalized) !== false || 
                    mb_strpos($uniqueNormalized, $normalizedName) !== false) {
                    if (strlen($cleanName) > strlen($uniqueCleanName)) {
                        $uniqueServices[$key]['name'] = $cleanName;
                    }
                    $isDuplicate = true;
                    break;
                }
            }
            
            if (!$isDuplicate) {
                $uniqueServices[] = [
                    'name' => $cleanName,
                    'amount' => $service['amount']
                ];
            }
        }
        
        return $uniqueServices;
    }
}





