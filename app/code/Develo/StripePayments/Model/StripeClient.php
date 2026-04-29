<?php
declare(strict_types=1);

namespace Develo\StripePayments\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Stripe\StripeClient as BaseStripeClient;

class StripeClient
{
    private ?BaseStripeClient $client = null;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function getPublishableKey(): string
    {
        return (string) $this->scopeConfig->getValue(
            'payment/stripe_payments/publishable_key',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getSecretKey(): string
    {
        return (string) $this->scopeConfig->getValue(
            'payment/stripe_payments/secret_key',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function isConfigured(): bool
    {
        return $this->getPublishableKey() !== '' && $this->getSecretKey() !== '';
    }

    public function getClient(): BaseStripeClient
    {
        return $this->client ??= new BaseStripeClient($this->getSecretKey());
    }
}
