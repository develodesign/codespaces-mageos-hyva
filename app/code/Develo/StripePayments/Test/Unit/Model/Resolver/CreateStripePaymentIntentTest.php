<?php
declare(strict_types=1);

namespace Develo\StripePayments\Test\Unit\Model\Resolver;

use Develo\StripePayments\Model\Resolver\CreateStripePaymentIntent;
use Develo\StripePayments\Model\StripeClient;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient as BaseStripeClient;

class CreateStripePaymentIntentTest extends TestCase
{
    private MockObject $stripeClient;
    private MockObject $maskedQuoteIdToQuoteId;
    private MockObject $cartRepository;
    private CreateStripePaymentIntent $resolver;

    protected function setUp(): void
    {
        $this->stripeClient = $this->createMock(StripeClient::class);
        $this->maskedQuoteIdToQuoteId = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);

        $this->resolver = new CreateStripePaymentIntent(
            $this->stripeClient,
            $this->maskedQuoteIdToQuoteId,
            $this->cartRepository,
        );
    }

    private function makeGuestContext(): MockObject
    {
        $context = $this->createMock(\Magento\GraphQl\Model\Query\ContextInterface::class);
        $context->method('getUserType')->willReturn(UserContextInterface::USER_TYPE_GUEST);
        $context->method('getUserId')->willReturn(0);
        return $context;
    }

    public function testResolveThrowsWhenCartIdMissing(): void
    {
        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('cart_id is required');

        $this->resolver->resolve(
            $this->createMock(Field::class),
            $this->makeGuestContext(),
            $this->createMock(ResolveInfo::class),
            [],
            ['cart_id' => '']
        );
    }

    public function testResolveThrowsWhenCartTotalIsZero(): void
    {
        $this->maskedQuoteIdToQuoteId->method('execute')->with('masked123')->willReturn(42);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->addMethods(['getGrandTotal', 'getQuoteCurrencyCode', 'getCustomerId'])
            ->getMock();
        $quote->method('getGrandTotal')->willReturn(0.0);
        $quote->method('getQuoteCurrencyCode')->willReturn('USD');
        $quote->method('getCustomerId')->willReturn(null);
        $this->cartRepository->method('get')->with(42)->willReturn($quote);

        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Cart total must be greater than zero');

        $this->resolver->resolve(
            $this->createMock(Field::class),
            $this->makeGuestContext(),
            $this->createMock(ResolveInfo::class),
            [],
            ['cart_id' => 'masked123']
        );
    }

    public function testResolveReturnsClientSecret(): void
    {
        $this->maskedQuoteIdToQuoteId->method('execute')->with('masked123')->willReturn(42);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->addMethods(['getGrandTotal', 'getQuoteCurrencyCode', 'getCustomerId'])
            ->getMock();
        $quote->method('getGrandTotal')->willReturn(99.99);
        $quote->method('getQuoteCurrencyCode')->willReturn('USD');
        $quote->method('getCustomerId')->willReturn(null);
        $this->cartRepository->method('get')->with(42)->willReturn($quote);

        $paymentIntent = $this->getMockBuilder(PaymentIntent::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get'])
            ->getMock();
        $paymentIntent->method('__get')->with('client_secret')->willReturn('pi_test_secret_abc');

        $paymentIntentService = $this->createMock(PaymentIntentService::class);
        $paymentIntentService->method('create')->with([
            'amount'                    => 9999,
            'currency'                  => 'usd',
            'automatic_payment_methods' => ['enabled' => true],
        ])->willReturn($paymentIntent);

        $baseClient = $this->getMockBuilder(BaseStripeClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get'])
            ->getMock();
        $baseClient->method('__get')->with('paymentIntents')->willReturn($paymentIntentService);
        $this->stripeClient->method('getClient')->willReturn($baseClient);

        $result = $this->resolver->resolve(
            $this->createMock(Field::class),
            $this->makeGuestContext(),
            $this->createMock(ResolveInfo::class),
            [],
            ['cart_id' => 'masked123']
        );

        $this->assertSame(['client_secret' => 'pi_test_secret_abc'], $result);
    }
}
