<?php
declare(strict_types=1);

namespace Develo\StripePayments\Test\Unit\Model\Resolver;

use Develo\StripePayments\Model\Resolver\StripePublishableKey;
use Develo\StripePayments\Model\StripeClient;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StripePublishableKeyTest extends TestCase
{
    private MockObject $stripeClient;
    private StripePublishableKey $resolver;

    protected function setUp(): void
    {
        $this->stripeClient = $this->createMock(StripeClient::class);
        $this->resolver = new StripePublishableKey($this->stripeClient);
    }

    public function testResolveReturnsPublishableKey(): void
    {
        $this->stripeClient->method('getPublishableKey')->willReturn('pk_test_abc123');

        $result = $this->resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            [],
            []
        );

        $this->assertSame('pk_test_abc123', $result);
    }

    public function testResolveReturnsNullWhenKeyEmpty(): void
    {
        $this->stripeClient->method('getPublishableKey')->willReturn('');

        $result = $this->resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            [],
            []
        );

        $this->assertNull($result);
    }
}
