# Stripe Payments — Backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `Develo/StripePayments` — a Magento module that registers `stripe_payments` as a payment method, exposes `stripe_publishable_key` on `storeConfig` GraphQL, and provides two mutations (`createStripePaymentIntent`, `placeStripeOrder`) for the Daffodil headless checkout.

**Architecture:** Custom lean module using `stripe/stripe-php` SDK. Two GraphQL resolvers handle the PaymentIntents flow server-side; the payment method model is a thin `AbstractMethod` subclass so Magento's `PaymentGraphQl` auto-includes it in `available_payment_methods`. Secret key stays server-side only.

**Tech Stack:** PHP 8.3, Magento 2 / Mage-OS 2.2.1, `stripe/stripe-php ^13.0`, PHPUnit 10.5, GraphQL

---

## File Map

| Action | Path | Responsibility |
|--------|------|----------------|
| Create | `app/code/Develo/StripePayments/registration.php` | Component registry |
| Create | `app/code/Develo/StripePayments/etc/module.xml` | Module declaration |
| Create | `app/code/Develo/StripePayments/etc/config.xml` | Default payment config + keys |
| Create | `app/code/Develo/StripePayments/etc/di.xml` | DI wiring |
| Create | `app/code/Develo/StripePayments/etc/adminhtml/system.xml` | Admin config UI |
| Create | `app/code/Develo/StripePayments/etc/schema.graphqls` | GraphQL schema |
| Create | `app/code/Develo/StripePayments/Model/Payment/StripePayments.php` | Payment method model |
| Create | `app/code/Develo/StripePayments/Model/StripeClient.php` | Stripe SDK wrapper |
| Create | `app/code/Develo/StripePayments/Model/Resolver/StripePublishableKey.php` | storeConfig field resolver |
| Create | `app/code/Develo/StripePayments/Model/Resolver/CreateStripePaymentIntent.php` | Mutation resolver |
| Create | `app/code/Develo/StripePayments/Model/Resolver/PlaceStripeOrder.php` | Mutation resolver |
| Create | `app/code/Develo/StripePayments/Test/Unit/Model/StripeClientTest.php` | Unit tests |
| Create | `app/code/Develo/StripePayments/Test/Unit/Model/Resolver/CreateStripePaymentIntentTest.php` | Unit tests |
| Create | `app/code/Develo/StripePayments/Test/Unit/Model/Resolver/PlaceStripeOrderTest.php` | Unit tests |

---

## Task 1: Install `stripe/stripe-php` via Composer

**Files:** `composer.json`, `composer.lock`

- [ ] **Step 1: Require stripe-php**

```bash
cd /workspaces/codespaces-mageos-hyva
php -d memory_limit=-1 $(which composer) require stripe/stripe-php:"^13.0" --no-interaction
```

Expected: composer resolves and installs `stripe/stripe-php 13.x.x`

- [ ] **Step 2: Verify**

```bash
php -d memory_limit=-1 $(which composer) show stripe/stripe-php | head -5
```

Expected output includes `stripe/stripe-php  13.x.x`

---

## Task 2: Module Scaffold

**Files:** `registration.php`, `etc/module.xml`

- [ ] **Step 1: Create `registration.php`**

```php
<?php
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Develo_StripePayments',
    __DIR__
);
```

Save to: `app/code/Develo/StripePayments/registration.php`

- [ ] **Step 2: Create `etc/module.xml`**

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Module/etc/module.xsd">
    <module name="Develo_StripePayments">
        <sequence>
            <module name="Magento_Payment"/>
            <module name="Magento_PaymentGraphQl"/>
            <module name="Magento_StoreGraphQl"/>
            <module name="Magento_Quote"/>
        </sequence>
    </module>
</config>
```

Save to: `app/code/Develo/StripePayments/etc/module.xml`

- [ ] **Step 3: Enable the module**

```bash
cd /workspaces/codespaces-mageos-hyva
bin/magento module:enable Develo_StripePayments
php -d memory_limit=-1 bin/magento setup:upgrade --keep-generated
```

Expected: `Develo_StripePayments` appears in `app/etc/config.php` with value `1`.

- [ ] **Step 4: Commit**

```bash
git add app/code/Develo/StripePayments/registration.php app/code/Develo/StripePayments/etc/module.xml app/etc/config.php composer.json composer.lock
git commit -m "feat: scaffold Develo_StripePayments module with stripe-php"
```

---

## Task 3: Payment Method Registration

**Files:** `etc/config.xml`, `etc/di.xml`, `Model/Payment/StripePayments.php`

- [ ] **Step 1: Create `etc/config.xml`**

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:module:Magento_Store:etc/config.xsd">
    <default>
        <payment>
            <stripe_payments>
                <active>0</active>
                <model>Develo\StripePayments\Model\Payment\StripePayments</model>
                <order_status>pending</order_status>
                <title>Credit / Debit Card (Stripe)</title>
                <allowspecific>0</allowspecific>
                <group>online</group>
                <publishable_key/>
                <secret_key/>
            </stripe_payments>
        </payment>
    </default>
</config>
```

Save to: `app/code/Develo/StripePayments/etc/config.xml`

- [ ] **Step 2: Create `Model/Payment/StripePayments.php`**

```php
<?php
declare(strict_types=1);

namespace Develo\StripePayments\Model\Payment;

use Magento\Payment\Model\Method\AbstractMethod;

class StripePayments extends AbstractMethod
{
    protected $_code = 'stripe_payments';
    protected $_isGateway = true;
    protected $_canCapture = true;
    protected $_canRefund = false;
}
```

Save to: `app/code/Develo/StripePayments/Model/Payment/StripePayments.php`

- [ ] **Step 3: Create `etc/di.xml`**

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
</config>
```

Save to: `app/code/Develo/StripePayments/etc/di.xml`  
(Minimal for now — resolvers wired via `schema.graphqls` `@resolver` annotations.)

- [ ] **Step 4: Flush cache**

```bash
bin/magento cache:flush
```

- [ ] **Step 5: Verify payment method is registered**

```bash
bin/magento config:show payment/stripe_payments/model
```

Expected: `Develo\StripePayments\Model\Payment\StripePayments`

- [ ] **Step 6: Commit**

```bash
git add app/code/Develo/StripePayments/etc/config.xml \
        app/code/Develo/StripePayments/etc/di.xml \
        app/code/Develo/StripePayments/Model/Payment/StripePayments.php
git commit -m "feat: register stripe_payments payment method"
```

---

## Task 4: Admin Config UI

**Files:** `etc/adminhtml/system.xml`

- [ ] **Step 1: Create `etc/adminhtml/system.xml`**

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:module:Magento_Config:etc/system_file.xsd">
    <system>
        <section id="payment">
            <group id="stripe_payments" translate="label" type="text" sortOrder="100" showInDefault="1" showInWebsite="1" showInStore="1">
                <label>Stripe Payments</label>
                <field id="active" translate="label" type="select" sortOrder="10" showInDefault="1" showInWebsite="1" showInStore="0">
                    <label>Enabled</label>
                    <source_model>Magento\Config\Model\Config\Source\Yesno</source_model>
                </field>
                <field id="title" translate="label" type="text" sortOrder="20" showInDefault="1" showInWebsite="1" showInStore="1">
                    <label>Title</label>
                </field>
                <field id="publishable_key" translate="label" type="text" sortOrder="30" showInDefault="1" showInWebsite="1" showInStore="1">
                    <label>Publishable Key</label>
                </field>
                <field id="secret_key" translate="label" type="obscure" sortOrder="40" showInDefault="1" showInWebsite="1" showInStore="0">
                    <label>Secret Key</label>
                    <backend_model>Magento\Config\Model\Config\Backend\Encrypted</backend_model>
                </field>
            </group>
        </section>
    </system>
</config>
```

Save to: `app/code/Develo/StripePayments/etc/adminhtml/system.xml`

- [ ] **Step 2: Commit**

```bash
git add app/code/Develo/StripePayments/etc/adminhtml/system.xml
git commit -m "feat: add Stripe admin config UI (publishable/secret key fields)"
```

---

## Task 5: StripeClient Wrapper + Unit Test

**Files:** `Model/StripeClient.php`, `Test/Unit/Model/StripeClientTest.php`

- [ ] **Step 1: Write the failing test**

Save to: `app/code/Develo/StripePayments/Test/Unit/Model/StripeClientTest.php`

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /workspaces/codespaces-mageos-hyva
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Develo/StripePayments/Test/Unit/Model/StripeClientTest.php
```

Expected: FAIL — `Class "Develo\StripePayments\Model\StripeClient" not found`

- [ ] **Step 3: Create `Model/StripeClient.php`**

```php
<?php
declare(strict_types=1);

namespace Develo\StripePayments\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Stripe\StripeClient as BaseStripeClient;

class StripeClient
{
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
        return new BaseStripeClient($this->getSecretKey());
    }
}
```

Save to: `app/code/Develo/StripePayments/Model/StripeClient.php`

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Develo/StripePayments/Test/Unit/Model/StripeClientTest.php
```

Expected: `OK (4 tests, 4 assertions)`

- [ ] **Step 5: Commit**

```bash
git add app/code/Develo/StripePayments/Model/StripeClient.php \
        app/code/Develo/StripePayments/Test/Unit/Model/StripeClientTest.php
git commit -m "feat: add StripeClient wrapper with config reads"
```

---

## Task 6: GraphQL Schema

**Files:** `etc/schema.graphqls`

- [ ] **Step 1: Create `etc/schema.graphqls`**

```graphql
type StoreConfig {
    stripe_publishable_key: String
        @resolver(class: "Develo\\StripePayments\\Model\\Resolver\\StripePublishableKey")
        @doc(description: "Stripe publishable key for client-side Stripe.js initialisation.")
        @cache(cacheable: false)
}

type Mutation {
    createStripePaymentIntent(
        cart_id: String!
            @doc(description: "The masked cart ID.")
    ): StripePaymentIntentOutput!
        @resolver(class: "Develo\\StripePayments\\Model\\Resolver\\CreateStripePaymentIntent")
        @doc(description: "Creates a Stripe PaymentIntent for the cart total and returns the client_secret for Stripe.js.")
        @cache(cacheable: false)

    placeStripeOrder(
        cart_id: String!
            @doc(description: "The masked cart ID.")
        payment_intent_id: String!
            @doc(description: "The Stripe PaymentIntent ID (pi_...) confirmed by Stripe.js on the frontend.")
    ): PlaceStripeOrderOutput!
        @resolver(class: "Develo\\StripePayments\\Model\\Resolver\\PlaceStripeOrder")
        @doc(description: "Verifies the Stripe PaymentIntent and places the Magento order.")
        @cache(cacheable: false)
}

type StripePaymentIntentOutput {
    client_secret: String! @doc(description: "The Stripe PaymentIntent client_secret for use with stripe.confirmCardPayment().")
}

type PlaceStripeOrderOutput {
    order_number: String! @doc(description: "The Magento order increment ID.")
}
```

Save to: `app/code/Develo/StripePayments/etc/schema.graphqls`

- [ ] **Step 2: Flush GraphQL schema cache**

```bash
bin/magento cache:clean full_page config
```

- [ ] **Step 3: Commit**

```bash
git add app/code/Develo/StripePayments/etc/schema.graphqls
git commit -m "feat: add Stripe GraphQL schema (storeConfig field + 2 mutations)"
```

---

## Task 7: `StripePublishableKey` Resolver + Test

**Files:** `Model/Resolver/StripePublishableKey.php`, `Test/Unit/Model/Resolver/StripePublishableKeyTest.php`

- [ ] **Step 1: Write the failing test**

Save to: `app/code/Develo/StripePayments/Test/Unit/Model/Resolver/StripePublishableKeyTest.php`

```php
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
```

- [ ] **Step 2: Run to verify it fails**

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Develo/StripePayments/Test/Unit/Model/Resolver/StripePublishableKeyTest.php
```

Expected: FAIL — class not found

- [ ] **Step 3: Create `Model/Resolver/StripePublishableKey.php`**

```php
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

    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): ?string
    {
        $key = $this->stripeClient->getPublishableKey();

        return $key !== '' ? $key : null;
    }
}
```

Save to: `app/code/Develo/StripePayments/Model/Resolver/StripePublishableKey.php`

- [ ] **Step 4: Run to verify it passes**

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Develo/StripePayments/Test/Unit/Model/Resolver/StripePublishableKeyTest.php
```

Expected: `OK (2 tests, 2 assertions)`

- [ ] **Step 5: Commit**

```bash
git add app/code/Develo/StripePayments/Model/Resolver/StripePublishableKey.php \
        app/code/Develo/StripePayments/Test/Unit/Model/Resolver/StripePublishableKeyTest.php
git commit -m "feat: add StripePublishableKey storeConfig GraphQL resolver"
```

---

## Task 8: `CreateStripePaymentIntent` Resolver + Test

**Files:** `Model/Resolver/CreateStripePaymentIntent.php`, `Test/Unit/Model/Resolver/CreateStripePaymentIntentTest.php`

- [ ] **Step 1: Write the failing test**

Save to: `app/code/Develo/StripePayments/Test/Unit/Model/Resolver/CreateStripePaymentIntentTest.php`

```php
<?php
declare(strict_types=1);

namespace Develo\StripePayments\Test\Unit\Model\Resolver;

use Develo\StripePayments\Model\Resolver\CreateStripePaymentIntent;
use Develo\StripePayments\Model\StripeClient;
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

    public function testResolveThrowsWhenCartIdMissing(): void
    {
        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('cart_id is required');

        $this->resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            [],
            ['cart_id' => '']
        );
    }

    public function testResolveReturnsClientSecret(): void
    {
        $this->maskedQuoteIdToQuoteId->method('execute')->with('masked123')->willReturn(42);

        $quote = $this->createMock(Quote::class);
        $quote->method('getGrandTotal')->willReturn(99.99);
        $quote->method('getQuoteCurrencyCode')->willReturn('USD');
        $this->cartRepository->method('get')->with(42)->willReturn($quote);

        $paymentIntent = $this->createMock(PaymentIntent::class);
        $paymentIntent->client_secret = 'pi_test_secret_abc';

        $paymentIntentService = $this->createMock(PaymentIntentService::class);
        $paymentIntentService->method('create')->with([
            'amount'   => 9999,
            'currency' => 'usd',
            'automatic_payment_methods' => ['enabled' => true],
        ])->willReturn($paymentIntent);

        $baseClient = $this->createMock(BaseStripeClient::class);
        $baseClient->paymentIntents = $paymentIntentService;
        $this->stripeClient->method('getClient')->willReturn($baseClient);

        $result = $this->resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            [],
            ['cart_id' => 'masked123']
        );

        $this->assertSame(['client_secret' => 'pi_test_secret_abc'], $result);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Develo/StripePayments/Test/Unit/Model/Resolver/CreateStripePaymentIntentTest.php
```

Expected: FAIL — class not found

- [ ] **Step 3: Create `Model/Resolver/CreateStripePaymentIntent.php`**

```php
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

    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): array
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
```

Save to: `app/code/Develo/StripePayments/Model/Resolver/CreateStripePaymentIntent.php`

- [ ] **Step 4: Run to verify it passes**

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Develo/StripePayments/Test/Unit/Model/Resolver/CreateStripePaymentIntentTest.php
```

Expected: `OK (2 tests, 2 assertions)`

- [ ] **Step 5: Commit**

```bash
git add app/code/Develo/StripePayments/Model/Resolver/CreateStripePaymentIntent.php \
        app/code/Develo/StripePayments/Test/Unit/Model/Resolver/CreateStripePaymentIntentTest.php
git commit -m "feat: add CreateStripePaymentIntent GraphQL resolver"
```

---

## Task 9: `PlaceStripeOrder` Resolver + Test

**Files:** `Model/Resolver/PlaceStripeOrder.php`, `Test/Unit/Model/Resolver/PlaceStripeOrderTest.php`

- [ ] **Step 1: Write the failing test**

Save to: `app/code/Develo/StripePayments/Test/Unit/Model/Resolver/PlaceStripeOrderTest.php`

```php
<?php
declare(strict_types=1);

namespace Develo\StripePayments\Test\Unit\Model\Resolver;

use Develo\StripePayments\Model\Resolver\PlaceStripeOrder;
use Develo\StripePayments\Model\StripeClient;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartManagementInterface;
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
        $this->stripeClient          = $this->createMock(StripeClient::class);
        $this->maskedQuoteIdToQuoteId = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);
        $this->cartRepository        = $this->createMock(CartRepositoryInterface::class);
        $this->cartManagement        = $this->createMock(CartManagementInterface::class);
        $this->orderRepository       = $this->createMock(OrderRepositoryInterface::class);

        $this->resolver = new PlaceStripeOrder(
            $this->stripeClient,
            $this->maskedQuoteIdToQuoteId,
            $this->cartRepository,
            $this->cartManagement,
            $this->orderRepository,
        );
    }

    public function testResolveThrowsWhenPaymentIntentIdMissing(): void
    {
        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('payment_intent_id is required');

        $this->resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            [],
            ['cart_id' => 'masked123', 'payment_intent_id' => '']
        );
    }

    public function testResolveThrowsWhenPaymentIntentNotSucceeded(): void
    {
        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Payment has not been confirmed');

        $paymentIntent = $this->createMock(PaymentIntent::class);
        $paymentIntent->status = 'requires_payment_method';

        $paymentIntentService = $this->createMock(PaymentIntentService::class);
        $paymentIntentService->method('retrieve')->with('pi_test_123')->willReturn($paymentIntent);

        $baseClient = $this->createMock(BaseStripeClient::class);
        $baseClient->paymentIntents = $paymentIntentService;
        $this->stripeClient->method('getClient')->willReturn($baseClient);

        $this->resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            [],
            ['cart_id' => 'masked123', 'payment_intent_id' => 'pi_test_123']
        );
    }

    public function testResolveReturnsOrderNumberOnSuccess(): void
    {
        $paymentIntent = $this->createMock(PaymentIntent::class);
        $paymentIntent->status = 'succeeded';

        $paymentIntentService = $this->createMock(PaymentIntentService::class);
        $paymentIntentService->method('retrieve')->with('pi_test_123')->willReturn($paymentIntent);

        $baseClient = $this->createMock(BaseStripeClient::class);
        $baseClient->paymentIntents = $paymentIntentService;
        $this->stripeClient->method('getClient')->willReturn($baseClient);

        $this->maskedQuoteIdToQuoteId->method('execute')->with('masked123')->willReturn(42);

        $quotePayment = $this->createMock(Payment::class);
        $quotePayment->expects($this->once())->method('setMethod')->with('stripe_payments');

        $quote = $this->createMock(Quote::class);
        $quote->method('getPayment')->willReturn($quotePayment);
        $this->cartRepository->method('get')->with(42)->willReturn($quote);

        $this->cartManagement->method('placeOrder')->with(42)->willReturn(99);

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('000000099');
        $this->orderRepository->method('get')->with(99)->willReturn($order);

        $result = $this->resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            [],
            ['cart_id' => 'masked123', 'payment_intent_id' => 'pi_test_123']
        );

        $this->assertSame(['order_number' => '000000099'], $result);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Develo/StripePayments/Test/Unit/Model/Resolver/PlaceStripeOrderTest.php
```

Expected: FAIL — class not found

- [ ] **Step 3: Create `Model/Resolver/PlaceStripeOrder.php`**

```php
<?php
declare(strict_types=1);

namespace Develo\StripePayments\Model\Resolver;

use Develo\StripePayments\Model\StripeClient;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
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

        $intent = $this->stripeClient->getClient()->paymentIntents->retrieve($paymentIntentId);

        if (!in_array($intent->status, ['succeeded', 'requires_capture'], true)) {
            throw new GraphQlInputException(__('Payment has not been confirmed. Status: %1', $intent->status));
        }

        $quoteId = $this->maskedQuoteIdToQuoteId->execute($maskedId);
        $quote   = $this->cartRepository->get($quoteId);
        $quote->getPayment()->setMethod('stripe_payments');

        $orderId = $this->cartManagement->placeOrder($quoteId);
        $order   = $this->orderRepository->get($orderId);

        return ['order_number' => $order->getIncrementId()];
    }
}
```

Save to: `app/code/Develo/StripePayments/Model/Resolver/PlaceStripeOrder.php`

- [ ] **Step 4: Run to verify it passes**

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Develo/StripePayments/Test/Unit/Model/Resolver/PlaceStripeOrderTest.php
```

Expected: `OK (3 tests, 3 assertions)`

- [ ] **Step 5: Commit**

```bash
git add app/code/Develo/StripePayments/Model/Resolver/PlaceStripeOrder.php \
        app/code/Develo/StripePayments/Test/Unit/Model/Resolver/PlaceStripeOrderTest.php
git commit -m "feat: add PlaceStripeOrder GraphQL resolver with intent verification"
```

---

## Task 10: Set API Keys and Enable Module

**Files:** none (CLI config only)

- [ ] **Step 1: Set the Stripe API keys**

The secret key field uses `Encrypted` backend model in `system.xml`, which means saving via Admin UI encrypts it. Using `config:set` stores it in plaintext — acceptable for a dev environment. For production, set it through Admin → Stores → Config → Payment → Stripe instead.

```bash
bin/magento config:set payment/stripe_payments/publishable_key "<YOUR_STRIPE_PUBLISHABLE_KEY>"
bin/magento config:set payment/stripe_payments/secret_key "<YOUR_STRIPE_SECRET_KEY>"
bin/magento config:set payment/stripe_payments/active 1
bin/magento cache:flush
```

- [ ] **Step 2: Verify payment method appears in GraphQL**

Run the following query against the Magento GraphQL endpoint (replace `CART_ID` with a real masked cart ID from your session):

```graphql
{
  cart(cart_id: "CART_ID") {
    available_payment_methods {
      code
      title
    }
  }
}
```

Expected: response includes `{ "code": "stripe_payments", "title": "Credit / Debit Card (Stripe)" }`

- [ ] **Step 3: Verify publishable key on storeConfig**

```graphql
{
  storeConfig {
    stripe_publishable_key
  }
}
```

Expected: `{ "stripe_publishable_key": "pk_test_51TRU22..." }`

- [ ] **Step 4: Run full test suite for the module**

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Develo/StripePayments/Test/Unit/
```

Expected: `OK (9 tests, 9 assertions)` (4 StripeClient + 2 StripePublishableKey + 2 CreateStripePaymentIntent + 3 PlaceStripeOrder)

- [ ] **Step 5: Commit**

```bash
git add app/etc/config.php
git commit -m "feat: enable Develo_StripePayments and set API keys via config"
```

---

## Task 11: DI Compile and Final Verification

- [ ] **Step 1: Compile DI**

```bash
php -d memory_limit=-1 bin/magento setup:di:compile
```

Expected: `Generated code successfully.` — no errors referencing `Develo\StripePayments`.

- [ ] **Step 2: Flush all caches**

```bash
bin/magento cache:flush
```

- [ ] **Step 3: End-to-end smoke test — create PaymentIntent**

Get a masked cart ID from a browser session (open the Angular storefront, add a product, proceed to checkout, copy the cart ID from a network request) then:

```bash
curl -s -X POST http://localhost:8080/graphql \
  -H "Content-Type: application/json" \
  -d '{"query":"mutation { createStripePaymentIntent(cart_id: \"MASKED_CART_ID\") { client_secret } }"}' | python3 -m json.tool
```

Expected: `{ "data": { "createStripePaymentIntent": { "client_secret": "pi_...._secret_..." } } }`

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "feat: Develo_StripePayments backend complete — ready for frontend integration"
```
