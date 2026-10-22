<?php

use omnilight\scheduling\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScheduleTest extends TestCase
{
    public static function environments(): array
    {
        return [['dev'], ['prod']];
    }

    /** @dataProvider environments */
    #[DataProvider('environments')]
    public function testUpdatedSchedulerParsesTemplatesAndQuotesArguments(string $environment): void
    {
        $schedule = new Schedule();
        $account = 'account with spaces; false';
        $ticker = 'TEST; false';
        $strategyName = 'strategy with spaces; false';
        $strategies = [
            'etf' => [[
                'active' => true, 'profile' => 'test', 'account' => $account,
                'etfs' => [$ticker => [
                    'INCREMENT_VALUE' => 1, 'INCREMENT_PERIOD' => 5,
                    'BUY_LOTS_BOTTOM_LIMIT' => 10, 'BUY_TRAILING_PERCENTAGE' => 0.16,
                    'BUY_CHECK_PERIOD' => 1,
                ]],
            ]],
            'manage' => [$strategyName => [
                'active' => true, 'profile' => 'test', 'account' => $account,
                'shares' => ['TEST'], 'parking_money_etf' => $ticker,
            ]],
        ];
        $directory = dirname(__DIR__, 2) . '/environments/' . $environment . '/config/';
        require $directory . 'schedule-strategy-etf.php';
        require $directory . 'schedule-strategy-manage.php';
        $events = (new ReflectionProperty($schedule, '_events'))->getValue($schedule);
        self::assertCount(10, $events);
        $commands = implode("\n", array_map(static fn ($event) => $event->buildCommand(), $events));
        self::assertStringContainsString(escapeshellarg($account), $commands);
        self::assertStringContainsString(escapeshellarg($ticker), $commands);
        self::assertStringContainsString(escapeshellarg($strategyName), $commands);
        // Only calculate due events; never run a scheduled command.
        self::assertIsArray($schedule->dueEvents(Yii::$app));
    }
}
