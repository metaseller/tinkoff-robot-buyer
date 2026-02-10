<?php

use yii\helpers\Html;
use yii\web\View;

/**
 * @var View $this
 */

$this->title = 'Metaseller';

echo Html::beginTag('div', ['class' => 'site-index']);
echo Html::beginTag('div', ['class' => 'jumbotron', 'style' => 'text-align: center']);
echo Html::tag('h1', 'PHP Робот-покупатель на базе T-Invest API');

if ($version = @shell_exec('git describe --abbrev=0 --tags')) {
    echo Html::tag('p', $version, ['class' => 'lead']);
}
echo Html::endTag('div');
echo Html::endTag('div');

?>
<center>Робот реализует две инвестиционные стратегии: Непрерывная покупка фондов с заданной скоростью расходования средств и автоматизированное управление и ребалансировка портфеля (автопарковка денежных средств в денежные фонды, автопокупка облигаций по заданию)</center>
<BR/>
<center>Данный робот на GitHub: <a href="https://github.com/metaseller/tinkoff-robot-buyer">metaseller/tinkoff-robot-buyer</a></center>
<center>Неофициальный PHP фреймворк для T-Invest API: <a href="https://github.com/metaseller/tinkoff-invest-api-v2-php">metaseller/tinkoff-invest-api-v2-php</a></center>
<center>Обертка для Yii2 PHP фреймворка для T-Invest API: <a href="https://github.com/metaseller/tinkoff-invest-api-v2-yii2">metaseller/tinkoff-invest-api-v2-yii2</a></center>
<BR/>
<center>Документация по T-Invest API: <a href="https://developer.tbank.ru/invest/intro/intro">Что такое T-Invest API</a></center>
<center>Официальное коммьюнити разработчиков T-Invest API в Telegram: <a href="https://t.me/joinchat/VaW05CDzcSdsPULM">T-Invest Api Community</a></center>
