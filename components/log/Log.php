<?php

namespace app\components\log;

use app\helpers\ModelHelper;
use yii\console\Request as ConsoleRequest;
use yii\log\Logger;
use yii\web\Request as WebRequest;
use app\helpers\ArrayHelper;
use Yii;
use Throwable;
use Exception;

/**
 * Класс дополнительных функций для работы с исключениями и логгером
 *
 * @package app\helpers
 */
class Log
{
    /**
     * @var bool Флаг, означающих, что в ходе вызова глобальные константы уже залоггированы
     */
    public static $is_global_constants_logged;

    /**
     * @var int Номер записи лога в рамках исполнения API запроса
     */
    public static $log_row_number;

    /**
     * Метод инициализирует статические переменные
     */
    public static function initLogHelper(): void
    {
        static::$is_global_constants_logged = false;
        static::$log_row_number = 1;
    }

    /**
     * Метод возвращает номер строки лога в ходе логгирования исполнения, затем инкрементирует номер строки на 1
     *
     * @return int Номер строки лога в ходе логгирования исполнения
     */
    public static function logRowNumber(): int
    {
        if (!isset(static::$log_row_number)) {
            static::$log_row_number = 1;
        }

        return static::$log_row_number++;
    }

    /**
     * Метод форматирует trace исключения в виде строки
     *
     * @param Throwable $exception Экземпляр исключения
     * @param int $max_trace_level Максимальная глубина trace
     *
     * @return string Текстовое представление trace исключения
     *
     * @throws Exception
     */
    public static function formatExceptionTraceAsString(Throwable $exception, int $max_trace_level = 10): string
    {
        $traces = [];

        foreach ($exception->getTrace() as $item) {
            if (!empty($item['file']) && !empty($item['line'])) {
                $traces[] = "in {$item['file']}:{$item['line']}";

                if (count($traces) === $max_trace_level) {
                    break;
                }
            }
        }

        if (!static::$is_global_constants_logged) {
            if (!empty($_POST)) {
                $traces[] = 'POST: ' . static::logSerialize($_POST);
            }

            if (!empty($_GET)) {
                $traces[] = 'GET: ' . static::logSerialize($_GET);
            }

            if (isset($_SERVER) && $url = ArrayHelper::getValue($_SERVER, 'REQUEST_URI')) {
                $traces[] = 'URL CALL: ' . $url;
            }

            static::$is_global_constants_logged = true;
        }

        return $traces ? implode("\n    ", $traces) : '';
    }

    /**
     * Метод логгирует базовую информацию об API запросе в соответствующую цель
     *
     * @param ConsoleRequest|WebRequest $request Логгируемый запрос
     *
     * @param string $log_target
     */
    public static function logRequest($request, string $log_target): void
    {
        Yii::$app->log->traceLevel = 0;

        try {
            if ($request instanceof WebRequest) {
                $request_data = [
                    'endpoint' => $request->absoluteUrl,
                    'method' => $request->method,
                ];

                if (!empty($_POST)) {
                    $request_data['POST'] = $_POST;
                }

                if (!empty($_GET)) {
                    $request_data['GET'] = $_GET;
                }

                static::info($request_data, $log_target, 0);
            } elseif ($request instanceof ConsoleRequest) {
                /**
                 * TODO: При необходимости можно добавить логгирование консольных вызовов
                 */
            }
        } catch (Throwable $e) {
        }
    }

    /**
     * Запись сообщения в лог
     *
     * @param mixed|null $message Сообщение, массив или объект, допускающий сериализацию
     *
     * @param string $category Категория логгирования. По умолчанию равно <code>"application"</code>
     *
     * @param int|null $trace_level Значение для <code>Yii::$app->log->traceLevel</code>. По умолчанию равно <code>0</code>,
     * <code>null</code> означает, что значение <code>Yii::$app->log->traceLevel</code> не устанавливается
     */
    public static function info($message, $category = 'application', ?int $trace_level = 0): void
    {
        $current_trace_level = Yii::$app->log->traceLevel;

        if (isset($trace_level)) {
            Yii::$app->log->traceLevel = $trace_level;
        }

        Yii::getLogger()
            ->log(is_string($message) ? $message : static::logSerialize($message), Logger::LEVEL_INFO, $category)
        ;

        if (isset($trace_level)) {
            Yii::$app->log->traceLevel = $current_trace_level;
        }
    }

    /**
     * Запись предупреждения в лог
     *
     * @param mixed|null $message Сообщение, массив или объект, допускающий сериализацию
     *
     * @param string $category Категория логгирования. По умолчанию равно <code>"application"</code>
     *
     * @param int|null $trace_level Значение для <code>Yii::$app->log->traceLevel</code>. По умолчанию равно <code>null</code>,
     * что означает, что значение <code>Yii::$app->log->traceLevel</code> не устанавливается
     */
    public static function warning($message, $category = 'application', ?int $trace_level = null): void
    {
        $current_trace_level = Yii::$app->log->traceLevel;

        if (isset($trace_level)) {
            Yii::$app->log->traceLevel = $trace_level;
        }

        Yii::getLogger()
            ->log(is_string($message) ? $message : static::logSerialize($message), Logger::LEVEL_WARNING, $category)
        ;

        if (isset($trace_level)) {
            Yii::$app->log->traceLevel = $current_trace_level;
        }
    }

    /**
     * Запись ошибки в лог
     *
     * @param mixed|null $message Сообщение, массив или объект, допускающий сериализацию
     *
     * @param string $category Категория логгирования. По умолчанию равно <code>"application"</code>
     *
     * @param int|null $trace_level Значение для <code>Yii::$app->log->traceLevel</code>. По умолчанию равно <code>10</code>,
     * <code>null</code> означает, что значение <code>Yii::$app->log->traceLevel</code> не устанавливается
     */
    public static function error($message, $category = 'application', ?int $trace_level = 10): void
    {
        $current_trace_level = Yii::$app->log->traceLevel;

        if (isset($trace_level)) {
            Yii::$app->log->traceLevel = $trace_level;
        }

        Yii::getLogger()
            ->log(is_string($message) ? $message : static::logSerialize($message), Logger::LEVEL_ERROR, $category)
        ;

        if (isset($trace_level)) {
            Yii::$app->log->traceLevel = $current_trace_level;
        }
    }

    /**
     * Метод сериализует данные для логгирования
     *
     * Если передан объект, к нему применяется {@link ModelHelper::getObjectPublicVars()}
     *
     * @param object|array|null|mixed $data Модель объекта, или значение, которое может быть сериализовано через <code>json_encode($data, $encode_options)</code>
     * @param int $encode_options Опции кодирования метода json_encode. По умолчанию равно <code>JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT</code>
     * @param string $prefix Префикс перед строковым представлением данных. По умолчанию стоит перевод строки для красивого отображения информации
     *
     * @return string Строковое представление данных
     *
     * @see https://www.php.net/manual/ru/json.constants.php
     */
    public static function logSerialize($data, $encode_options = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT, string $prefix = "\n"): string
    {
        if (!isset($data)) {
            return $prefix . 'null';
        }

        try {
            if (is_bool($data)) {
                return $prefix . '(bool)' . var_export($data, true);
            } elseif (is_string($data)) {
                return $prefix . '(string)' . var_export($data, true);
            } elseif (is_int($data)) {
                return $prefix . '(int)' . var_export($data, true);
            } else {
                $serializable_data = is_object($data) ? ModelHelper::getObjectPublicVars($data) : $data;

                return $prefix . json_encode($serializable_data, $encode_options);
            }
        } catch (Throwable $e) {
            return 'Impossible to serialize data for log';
        }
    }
}
