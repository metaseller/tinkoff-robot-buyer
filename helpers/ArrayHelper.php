<?php

namespace app\helpers;

use app\components\exceptions\ValidateException;
use Exception;
use yii\helpers\ArrayHelper as ArrayHelperBase;
use Yii;

/**
 * Класс дополнительных функций для работы с массивами данных
 *
 * @package app\helpers
 */
class ArrayHelper extends ArrayHelperBase
{
    /**
     * @var int Код режима работы функции focussing "Удаление неподходящих"
     */
    public const FOCUSSING_MODE_REMOVE = 0;

    /**
     * @var int Код режима работы функции focussing "Обнуление неподходящих"
     */
    public const FOCUSSING_MODE_FALSE = 1;

    /**
     * @var int Код режима работы функции focussing "Оставление неподходящих"
     */
    public const FOCUSSING_MODE_LEAVE = 2;

    /**
     * Отображение значения в структурированном виде
     *
     * @param mixed $value Значение для отображения. По умолчанию равно <code>false</code>
     * @param mixed $key Ключ значения. По умолчанию равно <code>false</code>
     * @param bool $die Признак необходимости завершиния работы скрипта. По умолчанию равно <code>true</code>
     *
     * @throws Exception
     */
    public static function show($value = false, $key = false, $die = true): void
    {
        if (is_object($value)) {
            $name = get_class($value);
            $value = get_object_vars($value);
        } else {
            $name = is_array($value) ? 'array' : false;
        }

        $toggle = $name && count($value);
        $color = random_int(200, 245);

        echo '<div class="show-value' . ($toggle ? ' sv-toggle' : '') .
            '" style="display: inline-block; margin: 1px 0; padding: 3px; ' .
            ($toggle ? "background-color: rgb($color, $color, $color); " : '') . '">';

        if ($key !== false) {
            echo '<div class="sv-key" style="display: inline-block; margin-right: 6px; font-weight: bold">' .
                static::showValue($key) . '</div>';
        }

        echo '<div class="sv-value" style="display: inline-block">';

        echo $name ? $name . ' (' . count($value) . ')' : static::showValue($value);

        echo '</div>';

        if ($toggle) {
            echo '<div class="sv-childs" style="margin-left: 20px">';

            foreach ($value as $itemKey=>$itemValue) {
                static::show($itemValue, $itemKey, false);
            }

            echo '</div>';
        }

        echo '</div><br>';

        if ($die) {
            die();
        }
    }

    /**
     * Отображение значения переменной в строковом виде
     *
     * @param mixed $value Значение для отображения
     * @param bool|integer $limit Максимальная длина формируемой строки или <code>false</code>. По умолчанию равно <code>false</code>
     * @param bool $show_array Признак необходимости отображения значений массивов. По умолчанию равно <code>true</code>
     * @param bool $show_object Признак необходимости отображения значений объектов. По умолчанию равно <code>true</code>
     * @param bool $wrap Признак необходимости оборачивания значений массивов и объектов в обёртку. По умолчанию равно <code>true</code>
     *
     * @return bool|string Исходное значение в строковом виде
     */
    public static function showValue($value, $limit = false, $show_array = true, $show_object = true, $wrap = true)
    {
        $type = gettype($value);
        $str = false;

        switch ($type) {
            case 'boolean':
                $str = $value ? 'true' : 'false';

                break;
            case 'integer':
            case 'double':
                $str = (string) $value;

                break;
            case 'string':
                $len = mb_strlen($value);

                if (!$limit || $len + 2 <= $limit) {
                    return "'$value'";
                }

                return $limit < 6 ? false : "'" . mb_substr($value, 0, $limit - 5) . "...'";
            case 'object':
            case 'array':
                $isObject = $type === 'object';

                if ((!$isObject && $show_array) || ($isObject && $show_object)) {
                    if (!$wrap) {
                        $pattern = '%s';
                    } else {
                        $pattern = $isObject ? get_class($value) . '{%s}' : 'array(%s)';
                    }

                    $lenPattern = mb_strlen($pattern) - 2;

                    if ($limit && $limit < $lenPattern) {
                        return false;
                    }

                    if ($isObject) {
                        $value = get_object_vars($value);
                    }

                    $limitItems = $limit ? $limit - $lenPattern : false;
                    $values = array_values($value);
                    $includeKeys = $value !== $values;
                    $qu = 0;
                    $items = [];

                    foreach ($value as $key => $item) {
                        if ($qu && $limit) {
                            $limitItems -= 2;

                            if ($limitItems < 1) {
                                break;
                            }
                        }

                        if ($includeKeys) {
                            $key = static::showValue($key) . '=>';

                            if ($limit) {
                                $limitItems -= mb_strlen($key);

                                if ($limitItems < 1) {
                                    break;
                                }
                            }
                        }

                        if (gettype($item) !== $type || $item !== $value) {
                            $item = static::showValue($item, $limitItems, $show_array, $show_object);
                        } elseif (!$limit || $limitItems > 10) {
                            $item = '*RECURSION*';
                        } else {
                            break;
                        }

                        if ($item === false) {
                            break;
                        }

                        $items[] = ($includeKeys ? $key : '') . $item;
                        $qu++;

                        if ($limit) {
                            $limitItems -= mb_strlen($item);
                        }
                    }

                    if (!$limit || count($value) === $qu) {
                        return sprintf($pattern, implode(', ', $items));
                    }

                    while ($items) {
                        $str = sprintf($pattern, implode(', ', $items) . ',...');

                        if (mb_strlen($str) <= $limit) {
                            return $str;
                        }

                        $str = end($items);
                        $key = $includeKeys ? mb_substr($str, 0, mb_strpos($str, '=>') + 2) : '';
                        $limitItems = mb_strlen($str) - mb_strlen($key) - 1;
                        $item = $limitItems < 1 ? false : static::showValue($values[$qu - 1], $limitItems, $show_array, $show_object);

                        if ($item === false) {
                            array_pop($items);
                            $qu--;
                        } else {
                            $items[$qu - 1] = $key . $item;
                        }
                    }

                    return false;
                }

                break;
            default:
                $str = $type;
        }

        return !$limit || mb_strlen($str) <= $limit ? $str : false;
    }

    /**
     * Выборка и получение данных из таблицы фиксированных значений
     *
     * @param array $data Таблица фиксированных значений. Задаётся в виде:
     *  <pre>
     *      [
     *          [
     *              {Символьный идентификатор характеристики 1 фиксированного значения 1} => {Значение характеристики 1 фиксированного значения 1},
     *              {Символьный идентификатор характеристики 2 фиксированного значения 1} => {Значение характеристики 2 фиксированного значения 1},
     *          ],
     *          [
     *              {Символьный идентификатор характеристики 1 фиксированного значения 2} => {Значение характеристики 1 фиксированного значения 2},
     *              {Символьный идентификатор характеристики 2 фиксированного значения 2} => {Значение характеристики 2 фиксированного значения 2},
     *          ],
     *          ...
     *      ]
     *  </pre>
     * @param mixed $param1 Первый параметр или <code>null</code>. По умолчанию равно <code>null</code>. Может задавать как название, так и
     * значение искомой характеристики
     * @param string|null $param2 Второй параметр или <code>null</code>. По умолчанию равно <code>null</code>. Задаёт название характеристики, выводимой в ключ значений выборки
     * @param array $only Список значений характеристик фиксированных значений, допустимых для включения в результат. По умолчанию равно <code>array()</code>. Если не задано, будут включены
     * все выбираемые значения
     *
     * @return mixed Выбранные данные таблицы или <code>null</code>
     * Возможны следующие комбинации параметров param1 и param2:
     *  <pre>
     * 	    param1  param2  Результат
     *      -------------------------
     * 	    null    null   Данные всей таблицы
     * 	    value   null   Данные всех характеристик фиксированного значения, в котором найден <code>$param1</code>, или <code>null</code>
     * 	    feature null   Список характеристик <code>$param1</code> всех фиксированных значений таблицы
     * 	    value   feature Характеристика <code>param2</code> фиксированного значения, в котором найден <code>$param1</code>, или <code>null</code>
     * 	    feature feature Ассоциативный массив в формате <code>{Характеристика param2} => {Характеристики param1}</code>
     *  </pre>
     */
    public static function select(array $data, $param1 = null, string $param2 = null, array $only = [])
    {
        if (!$data) {
            return false;
        }

        if (!isset($param1)) {
            return $data;
        }

        $features = array_keys(reset($data));
        $feature1 = in_array($param1, $features, true);
        $feature2 = $param2 && in_array($param2, $features, true);

        foreach ($data as $rec) {
            if ((!$feature1 && !in_array($param1, $rec, true)) || ($only && !array_intersect($rec, $only))) {
                continue;
            }

            if (!$feature1) {
                $value = $rec;
            } else {
                $value = isset($rec[$param1]) ? $rec[$param1] : null;
            }

            $key = !$feature2 || !isset($rec[$param2]) ? false : $rec[$param2];

            if (!$feature1) {
                return isset($key) && $key !== false ? $key : $value;
            }

            if ($key) {
                $result[$key] = $value;
            } else {
                $result[] = $value;
            }
        }

        if (isset($result)) {
            return $result;
        }

        return !$feature1 ? null : [];
    }

    /**
     * Выборка и получение данных из таблицы фиксированных значений
     *
     * @param array $data Таблица фиксированных значений. Задаётся в виде:
     *  <pre>
     *      [
     *          [
     *              {Символьный идентификатор характеристики 1 фиксированного значения 1} => {Значение характеристики 1 фиксированного значения 1},
     *              {Символьный идентификатор характеристики 2 фиксированного значения 1} => {Значение характеристики 2 фиксированного значения 1},
     *          ],
     *          [
     *              {Символьный идентификатор характеристики 1 фиксированного значения 2} => {Значение характеристики 1 фиксированного значения 2},
     *              {Символьный идентификатор характеристики 2 фиксированного значения 2} => {Значение характеристики 2 фиксированного значения 2},
     *          ],
     *          ...
     *      ]
     *  </pre>
     * @param string|int $code_value Значение поля, задающего код
     * @param string|null $select_attribute_name Второй параметр выборки, определяющего имя возвращаемого атрибута фиксированного набора, соответствующее коду.
     * По умолчанию равно <code>null</code>, что означает выборку всех строки таблицы фиксированных значений, соответствующе значению кода
     *
     * @return mixed|null Выбранные данные или <code>null</code>
     */
    public static function selectByCode(array $data, $code_value, string $select_attribute_name = null)
    {
        if (!$data || !$code_value) {
            return null;
        }

        $value = null;

        foreach ($data as $values_row) {
            if (!empty($values_row['code'])) {
                if (is_int($values_row['code']) && (int) $values_row['code'] === (int) $code_value) {
                    $value = $select_attribute_name ? ($values_row[$select_attribute_name] ?? null) : $values_row;

                    break;
                } elseif (is_string($values_row['code']) && (string) $values_row['code'] === (string) $code_value) {
                    $value = $select_attribute_name ? ($values_row[$select_attribute_name] ?? null) : $values_row;

                    break;
                }
            }
        }

        return $value;
    }

    /**
     * Фокусировка трёхуровневого массива
     *
     * Вынос элемента третьего уровня на второй уровено. Пример:
     *  <pre>
     * 	    [
     * 	    	'developer' => [
     * 	    		'code' => 10,
     * 	    		'description' => 'Разработчик',
     * 	    		'state' => 20
     * 	    	],
     * 	    	'citizen' => [
     * 	    		'code' => 20,
     * 	    		'description' => 'Гражданин',
     * 	    		'state' => 20
     * 	    	]
     * 	    ]
     * 	    =>
     * 	    [
     * 	    	'developer' => 'Разработчик',
     * 	    	'citizen' => 'Гражданин'
     * 	    ]
     *  </pre>
     *
     * @param array $array Список ассоциативных массивов или объектов
     * @param string $key_main Ключ главного элемента второго уровня. По умолчанию равно "description". Если элементом второго уровня является объект, то можно задать название метода
     * @param bool $preserve_keys Флаг необходимости сохранения ключей. По умолчанию равно <code>true</code>
     * @param int $mode Режим работы функции. По умолчанию равно {@link ArrayHelper::FOCUSSING_MODE_FALSE Обнуление неподходящих}
     *
     * @return array Сфокусированный массив
     */
    public static function focussing(array $array, string $key_main = 'description', bool $preserve_keys = true, int $mode = self::FOCUSSING_MODE_FALSE): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if (is_object($value) && (($is_property = isset($value->$key_main)) || method_exists($value, $key_main))) {
                $item = $is_property ? $value->$key_main : $value->$key_main();
            } elseif (is_array($value) && array_key_exists($key_main, $value)) {
                $item = $value[$key_main];
            } elseif ($mode === static::FOCUSSING_MODE_REMOVE) {
                continue;
            } else {
                $item = $mode === static::FOCUSSING_MODE_FALSE ? false : $value;
            }

            if ($preserve_keys) {
                $result[$key] = $item;
            } else {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Облицовка массива
     *
     * Пример:
     *  <pre>
     *      [
     * 	    	[
     * 	    		'code' => 10,
     * 	    		'description' => 'Разработчик',
     * 	    		'state' => 20
     * 	    	],
     * 	    	[
     * 	    		'code' => 20,
     * 	    		'description' => 'Гражданин',
     * 	    		'state' => 20
     * 	    	]
     * 	    ]
     * 	    =>
     * 	    [
     * 	    	10 => [
     *              'code' => 10,
     * 	    		'description' => 'Разработчик',
     * 	    		'state' => 20
     *          ],
     * 	    	20 => [
     * 	    		'code' => 20,
     * 	    		'description' => 'Гражданин',
     * 	    		'state' => 20
     * 	    	]
     * 	    ]
     *  </pre>
     *
     * @param array $array Исходный массив
     * @param int|string $key_main Ключ главного элемента в ряду. По умолчанию равно 'code'
     * @param bool $remove_empty Признак необходимости удаления элементов без главного ключа. По умолчанию равно <code>false</code>
     * @param bool $group Признак необходимости сгруппировать элементы по главному ключу. Если равно <code>false</code> в результирующем
     * массиве останется одна запись соответствующая значению главного ключа. В противном случае значением будет массив записей. По-умолчанию равно <code>false</code>
     *
     * @return array|boolean Облицованный массив
     */
    public static function facing(array $array, $key_main = 'code', bool $remove_empty = false, bool $group = false): array
    {
        $result = [];

        foreach ($array as $key => $features) {
            $value_main = $features[$key_main] ?? null;

            if (isset($value_main) || !$remove_empty) {
                if ($group) {
                    $result[$value_main ?? $key][] = $features;
                } else {
                    $result[$value_main ?? $key] = $features;
                }
            }
        }

        return $result;
    }

    /**
     * Формирование массива с выборочным включением элементов
     *
     * @param array $params Массив параметров формирования финального массива. Возможные варианты указания параметров элемента:
     *  <ul>
     *      <li>{Числовой ключ}=>{Значение элемента\Признак включения элемента в финальный массив}</li>
     *      <li>{Значение элемента}=>{Признак включения элемента в финальный массив}</li>
     *      <li>{Ключ элемента}=>array({Значение элемента\Признак включения элемента})</li>
     *      <li>{Ключ элемента}=>array({Значение элемента}, {Признак включения элемента})</li>
     *      <li>array({Ключ элемента}, {Значение элемента}, {Признак включения элемента})</li>
     *  </ul>
     *
     * @return array Сформированный массив
     */
    public static function forming(array $params): array
    {
        $result = [];

        foreach ($params as $key => $value) {
            if (!is_array($value)) {
                if ($value) {
                    $result[] = is_int($key) ? $value : $key;
                }
            } else {
                $params = array_values($value);
                $value = $enabled = reset($params);

                if (($qu = count($params)) === 2) {
                    $enabled = $params[1];
                } elseif ($qu > 2) {
                    [$key, $value, $enabled] = $params;
                }

                if ($enabled) {
                    if ($key === false) {
                        $result[] = $value;
                    } else {
                        $result[$key] = $value;
                    }
                }
            }
        }

        return $result;
    }

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
     * Фильтрация массива с ассоциативной постобработкой
     *
     * Ассоциативная постобработка включает в себя замену ключей в соответсвии со списком алиасов и добавление элементов с несуществующими ключами
     * Например:
     *  <code>
     *      $array = [
     *          'q1' => 1,
     *          'q2' => 2,
     *          'q3' => 3
     *      ];
     *
     *      $result = ArrayHelper::filterAssociative($array, ['w1' => 'q1', 'q2', 'w3' => 'q3', 'q4']);
     *      // Теперь $result равен:
     *      // [
     *      //      'w1' => 1,
     *      //      'q2' => 2,
     *      //      'w3' => 3,
     *      //      'q4' => null
     *      // ]
     *  </code>
     *
     * @param array $array Исходный массив
     * @param array $filters Список ключей фильтруемых элементов исходного массива. Если заданы строковые ключи, они будут использованы в качестве алиасов
     * @param mixed $default Значение по умолчанию для несуществующих ключей
     *
     * @return array Отфильтрованный массив
     *
     * @see ArrayHelperBase::filter()
     */
    public static function filterAssociative(array $array, array $filters, $default = null): array
    {
        $array = parent::filter($array, $filters);
        $data = [];

        foreach ($filters as $alias => $name) {
            $data[is_string($alias) ? $alias : $name] = $array[$name] ?? $default;
        }

        return $data;
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
     * Метод расширяет элементы массива <code>$destination_array</code> значениями массива <code>$source_array</code>, используя в качестве ключа
     * значение <code>$destination_array[$key_attribute]</code>
     *
     * @param array $destination_array Массив, значения которого необходимо расширить
     * @param array $source_array Массив-источник данных
     * @param string $key_attribute Имя атрибута массива <code>$destination_array</code>, значение которого будет использоваться в качестве ключа
     * массива <code>$source_array</code> для поиска значений
     *
     * @return array Расширенный список
     *
     * Пример:
     * <pre>
     *      $destination_array = [
     *          ['code' => 10, 'name' => 'row1'],
     *          ['code' => 20, 'name' => 'row2'],
     *          ['code' => 30, 'name' => 'row3'],
     *      ];
     *
     *      $source_array = [
     *          'row1' => ['extended_data' => 'data1'],
     *          'row2' => ['extended_data' => 'data2'],
     *      ];
     *
     *      $key_attribute = 'name';
     * </pre>
     *
     * Будет возвращен массив:
     * <pre>
     *      $extended_destination_array = [
     *          ['code' => 10, 'name' => 'row1', 'extended_data' => 'data1'],
     *          ['code' => 20, 'name' => 'row2', 'extended_data' => 'data2'],
     *          ['code' => 30, 'name' => 'row3'],
     *      ];
     * </pre>
     *
     * @throws ValidateException
     * @throws Exception
     */
    public static function extendsList(array $destination_array, array $source_array, string $key_attribute = 'name'): array
    {
        foreach ($destination_array as $row => $data) {
            $key_value = ArrayHelper::getValue($data, $key_attribute);

            if (!isset($key_value)) {
                throw new ValidateException(Yii::t('errors', 'Wrong destination array format'));
            }

            $destination_array[$row] = array_merge($data, $source_array[$key_value] ?? []);
        }

        return $destination_array;
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
}
