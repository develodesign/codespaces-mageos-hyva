<?php
declare(strict_types=1);

namespace Develo\StripePayments\Model\Resolver;

use Develo\StripePayments\Model\StripeClient;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

class StripePublishableKey implements ResolverInterface
{
    public function __construct(
        private readonly StripeClient $stripeClient,
    ) {
    }

    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): mixed
    {
        $key = $this->stripeClient->getPublishableKey();

        return $key !== '' ? $key : null;
    }
}
