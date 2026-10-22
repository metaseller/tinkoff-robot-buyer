<?php

namespace app\commands;

use app\helpers\DateTimeHelper;
use omnilight\scheduling\ScheduleController;

/**
 * Контроллер планировщика заданий
 *
 * @package app\commands
 */
class AppScheduleController extends ScheduleController
{
    /**
     * @inheritDoc
     */
    public function stdout($string)
    {
        return parent::stdout(DateTimeHelper::now() . "\t" . $string);
    }
    /**
     * @inheritDoc
     */
    public function stderr($string)
    {
        return parent::stderr(DateTimeHelper::now() . "\t" . $string);
    }
}
