<?php
declare(strict_types=1);

namespace Develo\StripePayments\Test\Unit\Model;

use Develo\StripePayments\Model\StripeClient;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StripeClientTest extends TestCase
{
    private MockObject $scopeConfig;
    private StripeClient $stripeClient;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->stripeClient = new StripeClient($this->scopeConfig);
    }

    public function testGetPublishableKeyReturnsConfigValue(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('payment/stripe_payments/publishable_key', ScopeInterface::SCOPE_STORE)
            ->willReturn('pk_test_abc123');

        $this->assertSame('pk_test_abc123', $this->stripeClient->getPublishableKey());
    }

    public function testGetSecretKeyReturnsConfigValue(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('payment/stripe_payments/secret_key', ScopeInterface::SCOPE_STORE)
            ->willReturn('sk_test_xyz789');

        $this->assertSame('sk_test_xyz789', $this->stripeClient->getSecretKey());
    }

    public function testIsConfiguredReturnsTrueWhenBothKeysPresent(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['payment/stripe_payments/publishable_key', ScopeInterface::SCOPE_STORE, null, 'pk_test_abc'],
                ['payment/stripe_payments/secret_key', ScopeInterface::SCOPE_STORE, null, 'sk_test_xyz'],
            ]);

        $this->assertTrue($this->stripeClient->isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenSecretKeyMissing(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['payment/stripe_payments/publishable_key', ScopeInterface::SCOPE_STORE, null, 'pk_test_abc'],
                ['payment/stripe_payments/secret_key', ScopeInterface::SCOPE_STORE, null, ''],
            ]);

        $this->assertFalse($this->stripeClient->isConfigured());
    }
}
