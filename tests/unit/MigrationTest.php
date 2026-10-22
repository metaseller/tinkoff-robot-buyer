<?php

use app\commands\BaseController;
use app\helpers\ArrayHelper;
use app\helpers\DateTimeHelper;
use app\models\TIAccount;
use app\models\TIProfile;
use app\models\TIServices;
use Metaseller\TinkoffInvestApi2\dto\Price;
use Metaseller\TinkoffInvestApi2\exceptions\ValidateException;
use Metaseller\TinkoffInvestApi2\helpers\QuotationHelper;
use Metaseller\TinkoffInvestApi2\TinkoffClientsFactory;
use PHPUnit\Framework\TestCase;
use Tinkoff\Invest\V1\Bond;
use Tinkoff\Invest\V1\MoneyValue;
use Tinkoff\Invest\V1\PortfolioPosition;
use Tinkoff\Invest\V1\PortfolioResponse;
use Tinkoff\Invest\V1\PostOrderRequest;
use Tinkoff\Invest\V1\Quotation;

final class MigrationTest extends TestCase
{
    public function testAccountResolvesByAliasAndId(): void
    {
        $account = TIAccount::create('test-account');
        self::assertSame('123456', $account->accountId);
        self::assertSame('test', $account->profile->profileAlias);
        self::assertSame('offline-test-token', $account->profile->secretToken);
        self::assertSame('test-account', TIAccount::create('123456')->accountAlias);
    }

    public function testUnknownAccountIsRejected(): void
    {
        $this->expectException(Exception::class);
        TIAccount::create('missing');
    }

    public function testDirectSdkAndProviderRefresh(): void
    {
        $profile = TIProfile::create('test');
        $client = TIServices::apiClient($profile, true);
        self::assertSame(TinkoffClientsFactory::class, $client::class);
        self::assertSame('robot.offline-tests', (new ReflectionProperty($client, '_app_name'))->getValue($client));
        self::assertSame($client, TIServices::apiClient($profile));
        $instruments = TIServices::instruments($profile);
        $market = TIServices::marketdata($profile);
        $portfolio = TIServices::portfolio($profile);
        self::assertNotSame($client, TIServices::apiClient($profile, true));
        self::assertNotSame($instruments, TIServices::instruments($profile));
        self::assertNotSame($market, TIServices::marketdata($profile));
        self::assertNotSame($portfolio, TIServices::portfolio($profile));
    }

    public function testGrpcClientsCanBeCreatedWithoutSendingRequests(): void
    {
        $client = TIServices::apiClient(TIProfile::create('test'), true);
        foreach (['instruments', 'marketData', 'operations', 'orders', 'users', 'sandbox', 'stopOrders'] as $service) {
            $method = 'get' . ucfirst($service) . 'ServiceClient';
            $grpc = $client->$method();
            self::assertInstanceOf(Grpc\BaseStub::class, $grpc);
            $grpc->close();
        }
    }

    public function testProtobufPortfolioRoundTripAndRepeatedFields(): void
    {
        $position = (new PortfolioPosition())->setTicker('TEST')->setQuantity(
            (new Quotation())->setUnits(3)->setNano(500000000)
        );
        $response = (new PortfolioResponse())->setPositions([$position]);
        $copy = new PortfolioResponse();
        $copy->mergeFromString($response->serializeToString());
        $positions = ArrayHelper::repeatedFieldToArray($copy->getPositions());
        self::assertCount(1, $positions);
        self::assertSame('TEST', $positions[0]->getTicker());
        self::assertSame(3.5, QuotationHelper::toDecimal($positions[0]->getQuantity()));
    }

    public function testNanounitPrecisionIsPreserved(): void
    {
        $quotation = (new Quotation())->setUnits(1234567)->setNano(123456789);
        $price = Price::createFromQuotation($quotation);
        self::assertSame(1234567123456789, QuotationHelper::toInteger($price->asQuotation()));
        self::assertSame(-1000000001, QuotationHelper::toInteger(
            QuotationHelper::integerToQuotation(-1000000001)
        ));
    }

    public function testBondQuotationDiffersFromCurrencyPrice(): void
    {
        $bond = (new Bond())->setNominal((new MoneyValue())->setUnits(1000)->setCurrency('rub'))
            ->setMinPriceIncrement(QuotationHelper::toQuotation(0.01));
        $price = QuotationHelper::toQuotation(95.25);
        self::assertSame(952.5, QuotationHelper::toCurrency($price, $bond));
        $request = (new PostOrderRequest())->setPrice($price)->setQuantity(1);
        $copy = new PostOrderRequest();
        $copy->mergeFromJsonString($request->serializeToJsonString());
        self::assertSame(95.25, QuotationHelper::toDecimal($copy->getPrice()));
    }

    public function testInvalidOrderIsRejectedBeforeAnyApiCall(): void
    {
        $this->expectException(ValidateException::class);
        OrderProbe::buy(TIAccount::create('test-account'), new Bond(), 0, new Quotation());
    }

    public function testMissingGrpcStatusIsNotAcceptedAsSuccess(): void
    {
        $this->expectException(Exception::class);
        OrderProbe::status((object) ['details' => 'missing code']);
    }

    public function testSuccessfulGrpcStatus(): void
    {
        OrderProbe::status((object) ['code' => 0]);
        self::assertTrue(true);
    }

    public function testNullableDateHelpers(): void
    {
        self::assertNull(DateTimeHelper::min(null, null));
        self::assertNull(DateTimeHelper::max(null, null));
        self::assertSame('2026-01-01', DateTimeHelper::min(null, '2026-01-01'));
        self::assertSame('2026-02-01', DateTimeHelper::max('2026-01-01', '2026-02-01'));
    }
}

final class OrderProbe extends BaseController
{
    public static function buy(TIAccount $account, $instrument, int $quantity, Quotation $price): ?string
    {
        return parent::placeBuyOrder($account, $instrument, $quantity, $price);
    }

    public static function status($status): void
    {
        parent::processRequestStatus($status);
    }
}
