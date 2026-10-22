<?php

use app\commands\BaseController;
use app\models\TIServices;
use Grpc\UnaryCall;
use Metaseller\TinkoffInvestApi2\helpers\QuotationHelper;
use Metaseller\TinkoffInvestApi2\providers\InstrumentsProvider;
use Metaseller\TinkoffInvestApi2\providers\MarketDataProvider;
use Metaseller\TinkoffInvestApi2\TinkoffClientsFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tinkoff\Invest\V1\Bond;
use Tinkoff\Invest\V1\MoneyValue;
use Tinkoff\Invest\V1\OrderDirection;
use Tinkoff\Invest\V1\OrdersServiceClient;
use Tinkoff\Invest\V1\PostOrderRequest;
use Tinkoff\Invest\V1\PostOrderResponse;
use Tinkoff\Invest\V1\SecurityTradingStatus;

final class OrderRegressionTest extends TestCase
{
    public static function bondOrders(): array
    {
        return [
            'buy ask in percent' => [true, null, 95.25],
            'sell bid in percent' => [false, null, 94.75],
            'buy explicit percent' => [true, 95.5, 95.5],
            'sell explicit percent' => [false, 94.5, 94.5],
        ];
    }

    /** @dataProvider bondOrders */
    #[DataProvider('bondOrders')]
    public function testBondOrderKeepsApiQuotation(bool $buy, ?float $limit, float $expected): void
    {
        $instrument = (new Bond())->setTicker('TEST')->setFigi('TEST-FIGI')->setLot(1)
            ->setNominal((new MoneyValue())->setUnits(1000)->setCurrency('rub'))
            ->setMinPriceIncrement(QuotationHelper::toQuotation(0.01))
            ->setTradingStatus(SecurityTradingStatus::SECURITY_TRADING_STATUS_NORMAL_TRADING)
            ->setApiTradeAvailableFlag(true)->setBuyAvailableFlag(true)->setSellAvailableFlag(true);
        $instruments = $this->createMock(InstrumentsProvider::class);
        $instruments->expects(self::once())->method('bondByTicker')->willReturn($instrument);
        $market = $this->createMock(MarketDataProvider::class);
        $market->expects(self::once())->method('getOrderbookTopPrices')->willReturn([
            'ask' => QuotationHelper::toQuotation(95.25),
            'bid' => QuotationHelper::toQuotation(94.75),
        ]);
        $call = $this->getMockBuilder(UnaryCall::class)->disableOriginalConstructor()->onlyMethods(['wait'])->getMock();
        $call->expects(self::once())->method('wait')->willReturn([
            (new PostOrderResponse())->setOrderId('offline-order-id'), (object) ['code' => 0],
        ]);
        $orders = $this->getMockBuilder(OrdersServiceClient::class)->disableOriginalConstructor()->onlyMethods(['PostOrder'])->getMock();
        $orders->expects(self::once())->method('PostOrder')->with(self::callback(
            static function (PostOrderRequest $request) use ($expected, $buy): bool {
                self::assertSame($expected, QuotationHelper::toDecimal($request->getPrice()));
                self::assertSame('123456', $request->getAccountId());
                self::assertSame(2, $request->getQuantity());
                self::assertSame($buy ? OrderDirection::ORDER_DIRECTION_BUY : OrderDirection::ORDER_DIRECTION_SELL, $request->getDirection());
                self::assertSame(32, strlen($request->getOrderId()));
                return true;
            }
        ))->willReturn($call);
        $factory = $this->getMockBuilder(TinkoffClientsFactory::class)->disableOriginalConstructor()->onlyMethods(['getOrdersServiceClient'])->getMock();
        $factory->expects(self::once())->method('getOrdersServiceClient')->willReturn($orders);
        (new ReflectionProperty(TIServices::class, '_api_clients'))->setValue(null, ['test' => $factory]);
        (new ReflectionProperty(TIServices::class, '_providers'))->setValue(null, ['test' => [
            'instruments' => $instruments, 'marketdata' => $market,
        ]]);
        ob_start();
        try {
            (new ManualOrderProbe('offline-order', Yii::$app))->executeOrder($buy, $limit);
        } finally {
            ob_end_clean();
            (new ReflectionProperty(TIServices::class, '_api_clients'))->setValue(null, []);
            (new ReflectionProperty(TIServices::class, '_providers'))->setValue(null, []);
        }
    }
}

final class ManualOrderProbe extends BaseController
{
    public function executeOrder(bool $buy, ?float $price): void
    {
        if ($buy) {
            $this->buyTaskLogic('test-account', 'bond', 'TEST', 2, $price);
        } else {
            $this->sellTaskLogic('test-account', 'bond', 'TEST', 2, $price);
        }
    }
}
