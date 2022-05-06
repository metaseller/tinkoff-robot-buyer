<?php

namespace app\helpers;

use yii\helpers\Console as ConsoleHelperBase;
use Yii;
use yii\base\Exception as BaseException;

/**
 * Класс дополнительных функций для работы с консолью
 *
 * @package app\helpers
 */
class ConsoleHelper extends ConsoleHelperBase
{
    /**
     * Метод вызывает запрос подтверждения действие, в случае отмены выводит текст и осуществяляет выход
     *
     * @param string $confirmation_text Текст запроса подтверждения. По умолчанию равно <code>'Are you sure?'</code>
     * @param string $cancel_text Текс отмены действия. По умолчанию равно <code>'Action cancelled.'</code>
     */
    public static function confirmation(string $confirmation_text = 'Are you sure?', string $cancel_text = 'Action cancelled.'): void
    {
        if (!static::confirm($confirmation_text)) {
            exit($cancel_text . "\n");
        }
    }

    /**
     * Метод запрашивает ввод кода верификации. В случае неверного ввода завершает работу
     *
     * @param int $verification_length Количество символов в коде верификации. По умолчанию равно <code>5</code>
     *
     * @throws BaseException
     */
    public static function verification(int $verification_length = 5): void
    {
        $verification_code_source = Yii::$app->security->generateRandomString($verification_length);
        $verification_code = static::input('Enter verification code ' . $verification_code_source . ': ');

        if ($verification_code !== $verification_code_source) {
            exit('Wrong verification code. Action cancelled.' . "\n");
        }
    }
}
