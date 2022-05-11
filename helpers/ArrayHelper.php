<?php

namespace app\helpers;

use Exception;
use Google\Protobuf\Internal\RepeatedField;
use yii\helpers\ArrayHelper as ArrayHelperBase;

/**
 * Класс дополнительных функций для работы с массивами данных
 *
 * @package app\helpers
 */
class ArrayHelper extends ArrayHelperBase
{
    /**
     * Объединение массивов с учётом порядка элементов
     *
     * @param array ...$args Переменное число объединяемых массивов
     *
     * @return array Объединённый массив
     *
     * @see ArrayHelperBase::merge()
     */
    public static function mergeWithOrdering(array ...$args): array
    {
        for ($count = 1, $size = count($args); $count < $size; $count++) {
            $buffer_master = $args[0];
            $result = $buffer_slave = [];

            foreach ($args[$count] as $key => $value) {
                if (!isset($args[0][$key])) {
                    $buffer_slave[$key] = false;
                } else {
                    $buffer_section = array_splice($buffer_master, 0, array_search($key, array_keys($buffer_master), true));

                    if ($buffer_slave) {
                        $buffer_section += $buffer_slave;
                        ksort($buffer_section);
                    }

                    $result += $buffer_section;
                    $result[$key] = array_shift($buffer_master);
                    $buffer_slave = [];
                }
            }

            $args[0] = $result + $buffer_master;
        }

        return parent::merge(...$args);
    }

    /**
     * Получение значений элементов массива или свойств объектов с заданными ключами или именами свойств
     *
     * @param array|object $array Исходный массив или объект
     * @param array|string $keys Список получаемых ключей элементов массива или свойств объекта в виде массива или строки с разделителем <code>$delimiter</code>
     * @param mixed $default Значение по умолчанию. Возвращается, если элемент не найден
     * @param string $delimiter Разделитель элементов в случае использования строкового значения <code>$keys</code>
     * @param bool $preserve_keys Признак необходимости сохранения ключей <code>$keys</code>. По умолчанию равно <code>true</code>
     *
     * @return array Ассоциативный массив полученных значений с ключами равными запрошенным
     *
     * @throws Exception
     *
     * @see ArrayHelperBase::getValue()
     */
    public static function getValues($array, $keys, $default = null, string $delimiter = ',', bool $preserve_keys = true): array
    {
        if (!is_array($keys)) {
            $keys = explode($delimiter, $keys);
        }

        $result = [];

        foreach ($keys as $key) {
            if (is_string($key)) {
                $key = trim($key);
            }

            $value = parent::getValue($array, $key, $default);

            if ($preserve_keys) {
                $result[$key] = $value;
            } else {
                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * Удаление из массива элементов с заданными ключами
     *
     * @param array $array Исходный массив
     * @param array $keys Список ключей
     *
     * @return array Отфильтрованный массив
     */
    public static function diffKeys(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }

    /**
     * Метод возвращает функцию сравнения двух массивов по указанному ключу
     *
     * @param string|int $attribute Ключ массива
     *
     * @return callable Функция сравнения
     */
    public static function buildKeySorter($attribute): callable
    {
        return function($a, $b) use ($attribute) {
            return strcmp($a[$attribute], $b[$attribute]);
        };
    }

    /**
     * Метод клонирует массив
     *
     * @param array $source Массив для клонирования
     *
     * @return array Клон массива
     */
    public static function cloneArray(array $source): array
    {
        return array_merge([], $source);
    }

    /**
     * Метод конвертирует поле типа {@link RepeatedField} в обычный массив
     *
     * @param RepeatedField $field Поле для конвертации
     *
     * @return array Результат конвертации
     */
    public static function repeatedFieldToArray(RepeatedField $field): array
    {
        if (function_exists('iterator_to_array')) {
            return iterator_to_array($field);
        }

        $data = [];

        foreach ($field as $value) {
            $data[] = $value;
        }

        return $data;
    }
}
