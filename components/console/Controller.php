<?php

namespace app\components\console;

use app\components\log\Log;
use Yii;
use yii\console\Controller as ControllerBase;

/**
 * Базовый контроллер консольного приложения
 *
 * @package app\components\console
 */
class Controller extends ControllerBase
{
    /**
     * @inheritDoc
     */
    public function init()
    {
        parent::init();

        Log::initLogHelper();

        $this->initLanguage();
    }

    /**
     * Инициализация текущей локали
     *
     * Выставляем локаль по умолчанию
     */
    public function initLanguage(): void
    {
        Yii::$app->language = 'ru';
    }
}
