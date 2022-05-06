<?php

namespace app\assets;

use yii\web\AssetBundle;

/**
 * Полный комплект ресурсов web-приложения
 *
 * Используется для консольной генерации ассетов
 *
 * @package app\assets
 */
class MasterAsset extends AssetBundle
{
    /**
     * @inheritDoc
     */
    public $basePath = '@webroot';

    /**
     * @inheritDoc
     */
    public $baseUrl = '@web';

    /**
     * @inheritDoc
     */
    public $depends = [
        AppAsset::class,
    ];
}
