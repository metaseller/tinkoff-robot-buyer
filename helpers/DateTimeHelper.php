<?php

namespace app\helpers;

use app\components\db\ActiveRecord;
use DateInterval;
use DatePeriod;
use DateTime;
use DateTimeZone;
use Exception;
use Throwable;
use yii\db\Expression;

/**
 * Класс дополнительных функций для работы с датой/временем
 *
 * @package app\helpers
 */
class DateTimeHelper
{
    /**
     * @var int Количество секунд в одном дне
     */
    public const SECONDS_IN_DAY = 24 * 60 * 60;

    /**
     * @var int Количество секунд в одной неделе
     */
    public const SECONDS_IN_WEEK = self::SECONDS_IN_DAY * 7;

    /**
     * @var int Количество секунд в одном месяце
     */
    public const SECONDS_IN_MONTH = self::SECONDS_IN_DAY * 30;

    /**
     * @var int Количество секунд в одном году
     */
    public const SECONDS_IN_YEAR = self::SECONDS_IN_DAY * 365;

    /**
     * @var string Формат даты для работы с базой данных
     */
    public const DATE_FORMAT_SQL = 'Y-m-d';

    /**
     * @var string Формат даты для отображения в суперадминке
     */
    public const DATE_FORMAT_ADMIN = 'd-m-Y';

    /**
     * @var string Формат даты и времени для работы с базой данных
     */
    public const DATETIME_FORMAT_SQL = 'Y-m-d H:i:s';

    /**
     * @var string Формат даты и времени для отображения в суперадминке
     */
    public const DATETIME_FORMAT_ADMIN = 'd-m-Y H:i';

    /**
     * Метод форматирует дату для суперадминки
     *
     * @param string $date_time Строка, содержащая дату/время
     * @param bool $only_date Признак необходимости вернуть только дату. По умолчанию равно <code>false</code>
     * @param string $timezone Временная зона, в которой необходимо вернуть дату/время. По умолчанию равно "Europe/Moscow"
     *
     * @return string Отформатированная строка, содержащая дату/время
     *
     * @throws Exception
     */
    public static function formatAdminDateTime(string $date_time, $only_date = false, $timezone = 'Europe/Moscow'): string
    {
        $datetime = new DateTime($date_time);

        return $datetime->setTimeZone(new DateTimeZone($timezone))->format($only_date ? static::DATE_FORMAT_ADMIN : static::DATETIME_FORMAT_ADMIN);
    }

    /**
     * Метод форматирует дату для API запроса
     *
     * @param string $date_time Строка, содержащая дату/время
     * @param string $timezone Строка, содержащая имя таймзоны
     *
     * @return array Ассоциативный массив с датой в формате ATOM и таймзоной
     *
     * @throws Exception
     */
    public static function formatApiDateTime(string $date_time, string $timezone = 'UTC'): array
    {
        $datetime = new DateTime($date_time);

        return [
            'date' => $datetime->format(DateTime::ATOM),
            'timezone' => $timezone,
        ];
    }

    /**
     * Метод форматирует дату из поля <code>$model->$date_time_attribute</code> для API запроса
     *
     * @param ActiveRecord $model Модель, которая содержит атрибут с датой/временем
     * @param string $date_time_attribute Имя атрибута модели $model, который содержит данные о дате/времени
     * @param string $timezone Строка, содержащая имя таймзоны
     *
     * @return array Ассоциативный массив с датой в формате ATOM и таймзоной
     *
     * @throws Exception
     */
    public static function formatApiModelDateTime(ActiveRecord $model, string $date_time_attribute, string $timezone = 'UTC'): array
    {
        $date_time = $model->$date_time_attribute;

        if ($date_time instanceof Expression) {
            $model->refresh();
            $date_time = $model->$date_time_attribute;
        }

        return static::formatApiDateTime($date_time, $timezone);
    }

    /**
     * Метод возвращает массив, содержащий дни, затронутые временным интервалом между входными параметрами
     *
     * @param int $start_timestamp_at Timestamp начальной даты
     * @param int $end_timestamp_at Timestamp конечной даты
     *
     * @return DateTime[] Массив, содержащий дни, затронутые временным интервалом между входными параметрами
     *
     * @throws Exception
     */
    public static function takeDaysRange(int $start_timestamp_at, int $end_timestamp_at): array
    {
        if ($start_timestamp_at >= $end_timestamp_at) {
            return [];
        }

        $out = [];

        $start_date = new DateTime('@' . $start_timestamp_at);
        $end_date = new DateTime('@' . $end_timestamp_at);

        $start_date->setTime(0, 0, 0, 1);
        $end_date->setTime(23, 59, 59, 999999);

        $interval = new DateInterval('P1D');
        $date_range = new DatePeriod($start_date, $interval, $end_date);

        foreach ($date_range as $date) {
            $out[] = $date;
        }

        return $out;
    }

    /**
     * Метод возвращает фактическое количество дней, в рамках которых укладывается интервал между $start_date_at и $end_date_at
     *
     * @param int $start_timestamp_at Timestamp начальной даты
     * @param int $end_timestamp_at Timestamp конечной даты
     *
     * @return int Количество дней
     *
     * @throws Exception
     */
    public static function takeDaysCount(int $start_timestamp_at, int $end_timestamp_at): int
    {
        if ($start_timestamp_at >= $end_timestamp_at) {
            return 0;
        }

        $start_date = new DateTime('@' . $start_timestamp_at);
        $end_date = new DateTime('@' . $end_timestamp_at);

        $start_date->setTime(0, 0, 0, 1);
        $end_date->setTime(23, 59, 59, 999999);

        return (int) ceil(($end_date->getTimestamp() - $start_date->getTimestamp()) / static::SECONDS_IN_DAY);
    }

    /**
     * Метод вычисляет дробное количество дней периода между <code>$start_timestamp</code> и <code>$end_timestamp_at</code>
     *
     * @param int|null $start_timestamp Временная метка начала периода. Если передано <code>null</code>, то будет установлено как 0.
     * По умолчанию равно <code>null</code>
     * @param int|null $end_timestamp_at Временная метка начала периода. Если передано <code>null</code>, то будет установлено как <code>time()</code>.
     * По умолчанию равно <code>null</code>
     *
     * @return float Дробное количество дней
     */
    public static function daysCount(int $start_timestamp = null, int $end_timestamp_at = null): float
    {
        $from = ($start_timestamp ?: 0);
        $to = ($end_timestamp_at ?: time());

        return ($to - $from) / static::SECONDS_IN_DAY;
    }

    /**
     * Определение максимальной строковой даты из двух представленных
     *
     * @param null|string $date1 Дата 1
     * @param null|string $date2 Дата 2
     *
     * @return null|string Максимальная дата
     */
    public static function max(?string $date1, ?string $date2): ?string
    {
        return strtotime($date1) > strtotime($date2) ? $date1 : $date2;
    }

    /**
     * Определение минимальной строковой даты из двух представленных
     *
     * @param null|string $date1 Дата 1
     * @param null|string $date2 Дата 2
     *
     * @return string Минимальная дата
     */
    public static function min(?string $date1, ?string $date2): string
    {
        return strtotime($date1) < strtotime($date2) ? $date1 : $date2;
    }

    /**
     * Метод конвертирует формат даты из PHP формата в Moment формат
     *
     * @param string $date_format Формат даты в PHP формате
     *
     * @return string Формат даты в Moment формате
     *
     * @see http://php.net/manual/en/function.date.php
     */
    public static function convertDatePhpToMoment(string $date_format): string
    {
        return strtr($date_format, [
            'd' => 'DD',
            'D' => 'ddd',
            'j' => 'D',
            'l' => 'dddd',
            'N' => 'E',
            'S' => '',
            'w' => 'd',
            'z' => '',
            'W' => 'W',
            'F' => 'MMMM',
            'm' => 'MM',
            'M' => 'MMM',
            'n' => 'M',
            't' => '',
            'L' => '',
            'o' => 'GGGG',
            'Y' => 'YYYY',
            'y' => 'YY',
            'a' => 'a',
            'A' => 'A',
            'B' => '',
            'g' => 'h',
            'G' => 'H',
            'h' => 'hh',
            'H' => 'HH',
            'i' => 'mm',
            's' => 'ss',
            'u' => '[u]',
            'e' => '[e]',
            'I' => '',
            'O' => 'ZZ',
            'P' => 'Z',
            'T' => '[T]',
            'Z' => '',
            'c' => 'YYYY-MM-DD[T]HH:mm:ssZ',
            'r' => 'ddd, DD MMM YYYY HH:mm:ss ZZ',
            'U' => 'X',
        ]);
    }

    /**
     * Метод конвертирует дату и время в строке из одной временной зоны в другую
     *
     * @param string $datetime_in_utc Строка, содержащая дату и время во временной зоне <code>$from_timezone</code>
     *
     * @param string|null $to_timezone Имя временной зоны, в которую нужно сконвертировать дату и время. По умолчанию равно <code>'UTC'</code>
     * @param string|null $from_timezone Имя временной зоны, в которой задана исходная дату и время. По умолчанию равно <code>'UTC'</code>
     *
     * @return string Строка с датой и временем в новой временной зоне
     */
    public static function convertDateTimeTimezone(string $datetime_in_utc, ?string $to_timezone = 'UTC', ?string $from_timezone = 'UTC'): string
    {
        $to_timezone = $to_timezone ?: 'UTC';
        $from_timezone = $from_timezone ?: 'UTC';

        if ($to_timezone !== $from_timezone) {
            try {
                $source_timezone = new DateTimeZone($from_timezone);
                $source_date_time = new DateTime($datetime_in_utc, $source_timezone);

                $destination_timezone = new DateTimeZone($to_timezone);
                $offset = $destination_timezone->getOffset($source_date_time);
                $interval = DateInterval::createFromDateString((string) $offset . 'seconds');
                $source_date_time->add($interval);

                return $source_date_time->format(self::DATETIME_FORMAT_SQL);
            } catch (Throwable $e) {
            }
        }

        return $datetime_in_utc;

    }

    /**
     * Генерация временной метки, соответствующей текущему времени
     *
     * @return string Временная метка
     */
    public static function now(): string
    {
        return date(static::DATETIME_FORMAT_SQL);
    }
}
