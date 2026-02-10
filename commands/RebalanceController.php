<?php

namespace app\commands;

use app\components\traits\ProgressTrait;

/**
 * Консольный контроллер, обслуживающий стратегию автоматической ребалансировки и управления портфелем
 *
 * @package app\commands
 */
class RebalanceController extends BaseController
{
    use ProgressTrait;
}
