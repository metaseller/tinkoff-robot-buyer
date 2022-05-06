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
        parent::stdout(DateTimeHelper::now() . "\t" . $string);
    }
    /**
     * @inheritDoc
     */
    public function stderr($string)
    {
        parent::stderr(DateTimeHelper::now() . "\t" . $string);
    }
}
