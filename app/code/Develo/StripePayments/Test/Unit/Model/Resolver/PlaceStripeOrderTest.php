<?php
declare(strict_types=1);

namespace Develo\StripePayments\Test\Unit\Model\Resolver;

use Develo\StripePayments\Model\Resolver\PlaceStripeOrder;
use Develo\StripePayments\Model\StripeClient;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient as BaseStripeClient;

class PlaceStripeOrderTest extends TestCase
{
    private MockObject $stripeClient;
    private MockObject $maskedQuoteIdToQuoteId;
    private MockObject $cartRepository;
    private MockObject $cartManagement;
    private MockObject $orderRepository;
    private PlaceStripeOrder $resolver;

    protected function setUp(): void
    {
        $this->stripeClient           = $this->createMock(StripeClient::class);
        $this->maskedQuoteIdToQuoteId = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);
        $this->cartRepository         = $this->createMock(CartRepositoryInterface::class);
        $this->cartManagement         = $this->createMock(CartManagementInterface::class);
        $this->orderRepository        = $this->createMock(OrderRepositoryInterface::class);

        $this->resolver = new PlaceStripeOrder(
            $this->stripeClient,
            $this->maskedQuoteIdToQuoteId,
            $this->cartRepository,
            $this->cartManagement,
            $this->orderRepository,
        );
    }

    private function makeGuestContext(): MockObject
    {
        $context = $this->createMock(\Magento\GraphQl\Model\Query\ContextInterface::class);
        $context->method('getUserType')->willReturn(UserContextInterface::USER_TYPE_GUEST);
        $context->method('getUserId')->willReturn(0);
        return $context;
    }

    public function testResolveThrowsWhenPaymentIntentIdMissing(): void
    {
        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('payment_intent_id is required');

        $this->resolver->resolve(
            $this->createMock(Field::class),
            $this->makeGuestContext(),
            $this->createMock(ResolveInfo::class),
            [],
            ['cart_id' => 'masked123', 'payment_intent_id' => '']
        );
    }

    public function testResolveThrowsWhenPaymentIntentNotSucceeded(): void
    {
        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Payment has not been confirmed');

        $paymentIntentService = $this->getMockBuilder(PaymentIntentService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['retrieve'])
            ->getMock();

        $paymentIntent = $this->getMockBuilder(PaymentIntent::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get'])
            ->getMock();
        $paymentIntent->method('__get')->with('status')->willReturn('requires_payment_method');

        $paymentIntentService->method('retrieve')->with('pi_test_123')->willReturn($paymentIntent);

        $baseClient = $this->getMockBuilder(BaseStripeClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get'])
            ->getMock();
        $baseClient->method('__get')->with('paymentIntents')->willReturn($paymentIntentService);
        $this->stripeClient->method('getClient')->willReturn($baseClient);

        $this->resolver->resolve(
            $this->createMock(Field::class),
            $this->makeGuestContext(),
            $this->createMock(ResolveInfo::class),
            [],
            ['cart_id' => 'masked123', 'payment_intent_id' => 'pi_test_123']
        );
    }

    public function testResolveReturnsOrderNumberOnSuccess(): void
    {
        $paymentIntentService = $this->getMockBuilder(PaymentIntentService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['retrieve'])
            ->getMock();

        $paymentIntent = $this->getMockBuilder(PaymentIntent::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get'])
            ->getMock();
        $paymentIntent->method('__get')->with('status')->willReturn('succeeded');

        $paymentIntentService->method('retrieve')->with('pi_test_123')->willReturn($paymentIntent);

        $baseClient = $this->getMockBuilder(BaseStripeClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get'])
            ->getMock();
        $baseClient->method('__get')->with('paymentIntents')->willReturn($paymentIntentService);
        $this->stripeClient->method('getClient')->willReturn($baseClient);

        $this->maskedQuoteIdToQuoteId->method('execute')->with('masked123')->willReturn(42);

        $quotePayment = $this->createMock(Payment::class);
        $quotePayment->expects($this->once())->method('setMethod')->with('stripe_payments');

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->addMethods(['getCustomerId'])
            ->onlyMethods(['getPayment'])
            ->getMock();
        $quote->method('getCustomerId')->willReturn(null);
        $quote->method('getPayment')->willReturn($quotePayment);
        $this->cartRepository->method('get')->with(42)->willReturn($quote);

        $this->cartManagement->method('placeOrder')->with(42)->willReturn(99);

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('000000099');
        $this->orderRepository->method('get')->with(99)->willReturn($order);

        $result = $this->resolver->resolve(
            $this->createMock(Field::class),
            $this->makeGuestContext(),
            $this->createMock(ResolveInfo::class),
            [],
            ['cart_id' => 'masked123', 'payment_intent_id' => 'pi_test_123']
        );

        $this->assertSame(['order_number' => '000000099'], $result);
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
            ['cart_id' => '', 'payment_intent_id' => 'pi_test_123']
        );
    }

    public function testResolveThrowsWhenCartNotFound(): void
    {
        $paymentIntentService = $this->getMockBuilder(PaymentIntentService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['retrieve'])
            ->getMock();

        $paymentIntent = $this->getMockBuilder(PaymentIntent::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get'])
            ->getMock();
        $paymentIntent->method('__get')->with('status')->willReturn('succeeded');
        $paymentIntentService->method('retrieve')->willReturn($paymentIntent);

        $baseClient = $this->getMockBuilder(BaseStripeClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get'])
            ->getMock();
        $baseClient->method('__get')->with('paymentIntents')->willReturn($paymentIntentService);
        $this->stripeClient->method('getClient')->willReturn($baseClient);

        $this->maskedQuoteIdToQuoteId->method('execute')
            ->with('bad_cart_id')
            ->willThrowException(new \Magento\Framework\Exception\NoSuchEntityException());

        $this->expectException(GraphQlNoSuchEntityException::class);

        $this->resolver->resolve(
            $this->createMock(Field::class),
            $this->makeGuestContext(),
            $this->createMock(ResolveInfo::class),
            [],
            ['cart_id' => 'bad_cart_id', 'payment_intent_id' => 'pi_test_123']
        );
    }

    public function testResolveThrowsWhenCustomerDoesNotOwnCart(): void
    {
        $paymentIntentService = $this->getMockBuilder(PaymentIntentService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['retrieve'])
            ->getMock();

        $paymentIntent = $this->getMockBuilder(PaymentIntent::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get'])
            ->getMock();
        $paymentIntent->method('__get')->with('status')->willReturn('succeeded');
        $paymentIntentService->method('retrieve')->willReturn($paymentIntent);

        $baseClient = $this->getMockBuilder(BaseStripeClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get'])
            ->getMock();
        $baseClient->method('__get')->with('paymentIntents')->willReturn($paymentIntentService);
        $this->stripeClient->method('getClient')->willReturn($baseClient);

        $this->maskedQuoteIdToQuoteId->method('execute')->with('masked123')->willReturn(42);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->addMethods(['getCustomerId'])
            ->onlyMethods(['getPayment'])
            ->getMock();
        $quote->method('getCustomerId')->willReturn(99); // different customer
        $this->cartRepository->method('get')->with(42)->willReturn($quote);

        $context = $this->createMock(\Magento\GraphQl\Model\Query\ContextInterface::class);
        $context->method('getUserType')->willReturn(UserContextInterface::USER_TYPE_CUSTOMER);
        $context->method('getUserId')->willReturn(1); // logged in as customer 1

        $this->expectException(GraphQlNoSuchEntityException::class);

        $this->resolver->resolve(
            $this->createMock(Field::class),
            $context,
            $this->createMock(ResolveInfo::class),
            [],
            ['cart_id' => 'masked123', 'payment_intent_id' => 'pi_test_123']
        );
    }
}
