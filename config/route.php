<?php

use yii\web\UrlRule;

return [
    [
        'pattern' => '<controller>/<action>/<id:[A-Za-z0-9_-]+>',
        'route' => '<controller>/<action>',
        'mode' => UrlRule::CREATION_ONLY,
    ],

    '<route:.*>' => 'site/index',
];
