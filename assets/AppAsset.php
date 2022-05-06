<?php

namespace app\assets;

use yii\bootstrap4\BootstrapAsset;
use yii\web\AssetBundle;
use yii\web\JqueryAsset;
use yii\web\YiiAsset;

/**
 * Главный комплект ресурсов web-приложения
 *
 * @package app\assets
 */
class AppAsset extends AssetBundle
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
    public $css = [
        'css/site.css',
    ];

    /**
     * @inheritDoc
     */
    public $depends = [
        JqueryAsset::class,
        YiiAsset::class,
        BootstrapAsset::class,
    ];
}
