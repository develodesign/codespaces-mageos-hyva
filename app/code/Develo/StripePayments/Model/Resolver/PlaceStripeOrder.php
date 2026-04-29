<?php
declare(strict_types=1);

namespace Develo\StripePayments\Model\Resolver;

use Develo\StripePayments\Model\StripeClient;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class PlaceStripeOrder implements ResolverInterface
{
    public function __construct(
        private readonly StripeClient $stripeClient,
        private readonly MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CartManagementInterface $cartManagement,
        private readonly OrderRepositoryInterface $orderRepository,
    ) {
    }

    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): array
    {
        $maskedId        = trim((string) ($args['cart_id'] ?? ''));
        $paymentIntentId = trim((string) ($args['payment_intent_id'] ?? ''));

        if ($maskedId === '') {
            throw new GraphQlInputException(__('cart_id is required.'));
        }
        if ($paymentIntentId === '') {
            throw new GraphQlInputException(__('payment_intent_id is required.'));
        }

        try {
            $intent = $this->stripeClient->getClient()->paymentIntents->retrieve($paymentIntentId);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new GraphQlInputException(__('Payment verification failed. Please try again.'));
        }

        if (!in_array($intent->status, ['succeeded', 'requires_capture'], true)) {
            throw new GraphQlInputException(__('Payment has not been confirmed. Status: %1', $intent->status));
        }

        try {
            $quoteId = $this->maskedQuoteIdToQuoteId->execute($maskedId);
            $quote   = $this->cartRepository->get($quoteId);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            throw new GraphQlNoSuchEntityException(__('Could not find a cart with ID "%1".', $maskedId));
        }

        $customerId = (int) $context->getUserId();
        $userType   = $context->getUserType();
        if ($userType === UserContextInterface::USER_TYPE_CUSTOMER && $customerId > 0) {
            if ((int) $quote->getCustomerId() !== $customerId) {
                throw new GraphQlNoSuchEntityException(__('Could not find a cart with ID "%1".', $maskedId));
            }
        }

        $quote->getPayment()->setMethod('stripe_payments');
        $quote->getPayment()->setAdditionalInformation('stripe_payment_intent_id', $paymentIntentId);

        try {
            $orderId = $this->cartManagement->placeOrder($quoteId);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            throw new GraphQlInputException(__($e->getMessage()));
        } catch (\Throwable $e) {
            throw new GraphQlInputException(__('Unable to place order. Please try again.'));
        }
        $order   = $this->orderRepository->get($orderId);

        return ['order_number' => $order->getIncrementId()];
    }
}
