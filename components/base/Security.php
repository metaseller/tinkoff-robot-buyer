<?php

namespace app\components\base;

use Exception;
use InvalidArgumentException;
use Yii;
use yii\base\Security as SecurityBase;

/**
 * Компонент приложения, предоставляющий расширенный функционал для обработки задач, связанных с безопасностью
 *
 * @package app\components\base
 */
class Security extends SecurityBase
{
    /**
     * Генерация цифрового ключа
     *
     * @param int $length Количество знаковых разрядов ключа
     * 
     * @return int Сгенерированный ключ
     *
     * @throws Exception
     */
    public function generateRandomNumber(int $length = 9): int
    {
        if ($length < 1) {
            throw new InvalidArgumentException(Yii::t('errors', 'Parameter "length" must be greater than 0'));
        }

        $max = 10 ** $length - 1;

        if ($max > PHP_INT_MAX) {
            throw new InvalidArgumentException(Yii::t('errors', 'Out of range'));
        }

        return random_int(10 ** ($length - 1), $max);
    }

    /**
     * Генерация уникальной строки, состоящей только из английских букв и цифр
     *
     * @param int $length Требуемая длина итоговой строки. По умолчанию равно 6
     * @param bool $upper_case Флаг необходимости привести строку к верхнему регистру. По умолчанию равен <code>false</code>
     * @param string $prefix Префикс строки. По умолчанию равен ""
     *
     * @return string Сгенерированная строка
     *
     * @throws Exception
     */
    public function generateRandomLettersNumbers(int $length = 6, bool $upper_case = false, string $prefix = ''): string
    {
        if ($length < 1) {
            throw new InvalidArgumentException(Yii::t('errors', 'Parameter "length" must be greater than 0'));
        }

        $permitted_chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $permitted_chars_length = mb_strlen($permitted_chars) - 1;

        $result = $prefix;

        for($i = 0; $i < $length; $i++) {
            $random_character = $permitted_chars[random_int(0, $permitted_chars_length)];
            $result .= $random_character;
        }

        return $upper_case ? mb_strtoupper($result) : $result;
    }
}
