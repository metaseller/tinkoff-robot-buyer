<?php

namespace app\commands;

use app\components\console\Controller;
use app\components\traits\ProgressTrait;

/**
 * Контроллер, содержащий общий базовый функционал взаимодействия с Tinkoff Invest Api
 *
 * @package app\commands
 */
abstract class BaseController extends Controller
{
    /**
     * @var float Расчетный размер комиссии за операции
     */
    public const COMMISSION = 0.0004;

    /**
     * @var string Основная цель логирования коммуникации с Tinkoff Invest
     */
    public const MAIN_LOG_TARGET = 'tinkoff_invest';

    /**
     * @var string Основная цель логирования исполнения стратегии автопокупки ETF
     */
    public const BUY_STRATEGY_LOG_TARGET = 'tinkoff_invest_strategy';

    /**
     * @var string Основная цель логирования исполнения стратегии автопокупки Бондов
     */
    public const BONDS_BUY_STRATEGY_LOG_TARGET = 'tinkoff_bonds_buy_strategy';

    /**
     * @var string Основная цель логирования исполнения стратегии автопокупки Акций
     */
    public const SHARES_BUY_STRATEGY_LOG_TARGET = 'tinkoff_shares_buy_strategy';

    //public static
}
