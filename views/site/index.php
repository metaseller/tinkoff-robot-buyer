<?php

use yii\helpers\Html;
use yii\web\View;

/**
 * @var View $this
 */

$this->title = 'Metaseller';

echo Html::beginTag('div', ['class' => 'site-index']);
echo Html::beginTag('div', ['class' => 'jumbotron', 'style' => 'text-align: center']);
echo Html::tag('h1', 'PHP Demo Robot for Tinkoff Contest!');

if ($version = @shell_exec('git describe --abbrev=0 --tags')) {
    echo Html::tag('p', $version, ['class' => 'lead']);
}
echo Html::endTag('div');
echo Html::endTag('div');

?>

<center><a href="https://github.com/metaseller/tinkoff-robot-buyer">https://github.com/metaseller/tinkoff-robot-buyer</a></center>
