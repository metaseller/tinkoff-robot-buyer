<?php

namespace app\components\traits;

use app\helpers\DateTimeHelper;

/**
 * Трейт для отображения прогресса выполнения задач в командной строке
 *
 * @package app\components\traits
 */
trait ProgressTrait
{
    /**
     * @var float Текущий процент прогресса обработки данных
     */
    protected $progress;

    /**
     * @var float Шаг процента прогресса обработки данных, соответствующий одному элементу обработки
     */
    protected $progressStep;

    /**
     * Инициализация вывода в консоль прогресса обработки данных
     *
     * @param int $qu_items Количество обрабатываемых элементов
     * @param int|null $progress_limit Часть прогресса, соответствующая данному количеству элементов, в процентах. По умолчанию
     * равно <code>null</code>. Если равно <code>null</code>, значение принимается равным оставшейся части прогресса
     */
    public function progressStart(int $qu_items, int $progress_limit = null): void
    {
        $this->progress = 0;
        $this->progressCorrection($qu_items, $progress_limit);

        echo str_pad('Process start: ', 15) . DateTimeHelper::now() . PHP_EOL;
        echo 'Items: ' . $qu_items . PHP_EOL;

        $this->progressShow();
    }

    /**
     * Отображение в консоли текущего прогресса обработки данных
     */
    public function progressShow(): void
    {
        echo "\r" . str_pad(round(min($this->progress, 100), 1) . '%', 5);
    }

    /**
     * Фиксация итерации прогресса обработки данных
     *
     * @param int $qu_steps Количество итераций. По умолчанию равно 1
     */
    public function progressStep(int $qu_steps = 1): void
    {
        $this->progress += $qu_steps * $this->progressStep;
        $this->progressShow();
    }

    /**
     * Корректировка шага прогресса
     *
     * @param int $qu_items Количество обрабатываемых элементов
     * @param int|null $progress_limit Часть прогресса, соответствующая данному количеству элементов, в процентах. По умолчанию
     * равно <code>null</code>. Если равно <code>null</code>, значение принимается равным оставшейся части прогресса
     */
    public function progressCorrection(int $qu_items, int $progress_limit = null): void
    {
        if ($qu_items > 0) {
            $this->progressStep = ($progress_limit > 0 ? $progress_limit : (100 - $this->progress)) / $qu_items;
        } else {
            $this->progressStep = 100;
        }
    }

    /**
     * Завершение вывода в консоль прогресса обработки данных
     */
    public function progressEnd(): void
    {
        $this->progress = 100;
        $this->progressShow();

        echo PHP_EOL . str_pad('Process end: ', 15) . DateTimeHelper::now() . PHP_EOL;
    }
}
