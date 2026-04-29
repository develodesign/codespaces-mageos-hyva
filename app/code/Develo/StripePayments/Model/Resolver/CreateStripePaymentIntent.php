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
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;

class CreateStripePaymentIntent implements ResolverInterface
{
    public function __construct(
        private readonly StripeClient $stripeClient,
        private readonly MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId,
        private readonly CartRepositoryInterface $cartRepository,
    ) {
    }

    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): array
    {
        $maskedId = trim((string) ($args['cart_id'] ?? ''));
        if ($maskedId === '') {
            throw new GraphQlInputException(__('cart_id is required.'));
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

        $amountInCents = (int) round($quote->getGrandTotal() * 100);
        $currency      = strtolower((string) $quote->getQuoteCurrencyCode());

        if ($amountInCents <= 0) {
            throw new GraphQlInputException(__('Cart total must be greater than zero.'));
        }

        try {
            $intent = $this->stripeClient->getClient()->paymentIntents->create([
                'amount'                    => $amountInCents,
                'currency'                  => $currency,
                'automatic_payment_methods' => ['enabled' => true],
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new GraphQlInputException(__('Payment processing error. Please try again.'));
        }

        return ['client_secret' => $intent->client_secret];
    }
}
