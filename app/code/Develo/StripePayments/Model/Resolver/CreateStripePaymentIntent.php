<?php
declare(strict_types=1);

namespace Develo\StripePayments\Model\Resolver;

use Develo\StripePayments\Model\StripeClient;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
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

    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): mixed
    {
        $maskedId = trim((string) ($args['cart_id'] ?? ''));
        if ($maskedId === '') {
            throw new GraphQlInputException(__('cart_id is required.'));
        }

        $quoteId = $this->maskedQuoteIdToQuoteId->execute($maskedId);
        $quote   = $this->cartRepository->get($quoteId);

        $amountInCents = (int) round($quote->getGrandTotal() * 100);
        $currency      = strtolower((string) $quote->getQuoteCurrencyCode());

        $intent = $this->stripeClient->getClient()->paymentIntents->create([
            'amount'                     => $amountInCents,
            'currency'                   => $currency,
            'automatic_payment_methods'  => ['enabled' => true],
        ]);

        return ['client_secret' => $intent->client_secret];
    }
}
