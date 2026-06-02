<?php

namespace app\controllers;

use yii\filters\AccessControl;
use yii\web\Controller;

/**
 * Базовый контролер для frontend заглушки
 */
class SiteController extends Controller
{
    /**
     * @inheritDoc
     */
    public function behaviors()
    {
        return [
        ];
    }

    /**
     * Метод отображает страницу Homepage
     *
     * @return string HTML код страницы
     */
    public function actionIndex()
    {
        return $this->render('index');
    }
}
