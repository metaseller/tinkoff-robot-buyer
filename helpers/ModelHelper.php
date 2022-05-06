<?php

namespace app\helpers;

use ReflectionClass;
use Throwable;

/**
 * Класс дополнительных функций для работы с моделями данных
 *
 * @package app\helpers
 */
class ModelHelper
{
    /**
     * @var string Пространство имён моделей данных по умолчанию
     */
    public static $namespaceModelDef = 'app\models';

    /**
     * Определения полного названия класса (включая пространство имён)
     *
     * @param string $class_name Исходное название класса. Если пространство имён не задано, будет использовано пространство имён по умолчанию из свойства {@link ModelHelper::$namespaceModelDef}
     *
     * @return string Полное название класса
     */
    public static function getFullClassName(string $class_name): string
    {
        if (mb_strpos($class_name, '\\') === false) {
            $class_name = static::$namespaceModelDef . '\\' . $class_name;
        }

        return $class_name;
    }

    /**
     * Определения короткого названия класса (без пространства имён)
     *
     * @param string $class_name Исходное полное название класса.
     *
     * @return string Короткое название класса
     */
    public static function getShortClassName(string $class_name): string
    {
        try {
            $reflect = new ReflectionClass($class_name);

            return $reflect->getShortName();
        } catch (Throwable $e) {
        }

        return $class_name;
    }

    /**
     * Метод возвращает массив свойств переданного объекта используя метод <code>get_object_vars</code>
     *
     * Используется для того, чтобы вызывать, передавая <code>$this</code>, чтобы отбросить protected атрибуты
     *
     * @param object|mixed $model Модель объекта, или значение, которое может быть приведено к массиву
     *
     * @return array Массив свойств
     *
     * @see https://www.php.net/manual/en/function.get-object-vars.php#113435
     */
    public static function getObjectPublicVars($model): array
    {
        return is_object($model) ? get_object_vars($model) : (array) $model;
    }
}
