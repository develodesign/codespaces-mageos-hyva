# Stripe Payments Integration Design

**Date:** 2026-04-29  
**Branch:** feature/stripe  
**Scope:** Mage-OS backend (`Develo/StripePayments`) + Daffodil Angular frontend (`dai-builder`)  
**Approach:** PaymentIntents + Stripe Elements, server-confirms

---

## Overview

Integrate Stripe card payments into the headless Daffodil storefront. The backend provides two GraphQL mutations and exposes `stripe_payments` as a standard Magento payment method. The frontend mounts Stripe Elements card fields, tokenizes the card client-side, and confirms the PaymentIntent before calling Magento to place the order. 3D Secure is handled automatically by Stripe.js in the browser.

---

## Architecture

### Backend: `app/code/Develo/StripePayments/`

**Payment method registration**
- Declares `stripe_payments` as a Magento offline/gateway payment method via `config.xml`
- Registered in `di.xml` as a value group entry under `Magento\Payment\Model\Config` so it appears in `available_payment_methods` on the cart GraphQL response automatically (via `Magento_PaymentGraphQl`)
- Method title: `"Credit / Debit Card (Stripe)"`

**Configuration**
- `etc/adminhtml/system.xml` — admin config fields: Publishable Key, Secret Key, enabled toggle
- Secret key stored in `core_config_data`, never exposed via GraphQL
- Publishable key returned via a `storeConfig` GraphQL extension field (`stripe_publishable_key`) so the frontend can load it at bootstrap

**GraphQL mutations**

```graphql
type Mutation {
  createStripePaymentIntent(cart_id: String!): StripePaymentIntentOutput!
  placeStripeOrder(cart_id: String!, payment_intent_id: String!): PlaceStripeOrderOutput!
}

type StripePaymentIntentOutput {
  client_secret: String!
}

type PlaceStripeOrderOutput {
  order_number: String!
}
```

`createStripePaymentIntent`:
- Loads cart, calculates grand total + currency
- Calls `stripe-php` `PaymentIntent::create()` with amount, currency, `automatic_payment_methods: {enabled: true}`
- Returns `client_secret`

`placeStripeOrder`:
- Retrieves PaymentIntent from Stripe API by ID
- Verifies `status === 'succeeded'` or `status === 'requires_capture'`
- Calls `Magento\Quote\Api\CartManagementInterface::placeOrder()` with `stripe_payments` as payment method
- Returns Magento order increment ID

**Dependencies**
- `stripe/stripe-php ^13.0` — added via Composer
- No dependency on `Magento_OfflinePayments` UI layer

**Module structure**
```
Develo/StripePayments/
├── registration.php
├── etc/
│   ├── module.xml
│   ├── di.xml
│   ├── config.xml
│   ├── schema.graphqls
│   └── adminhtml/system.xml
├── Model/
│   ├── Payment/StripePayments.php       # Payment method model
│   └── StripeClient.php                  # Wraps stripe-php SDK
├── Plugin/
│   └── StoreConfigPlugin.php            # Adds stripe_publishable_key to storeConfig
└── GraphQl/
    ├── Resolver/CreateStripePaymentIntent.php
    └── Resolver/PlaceStripeOrder.php
```

---

### Frontend: `dai-builder/src/app/`

**New package**
- `@stripe/stripe-js` added to `package.json`

**New component: `StripeCardComponent`**
- Standalone Angular component at `src/app/checkout/stripe-card/stripe-card.component.ts`
- Injects `STRIPE_PUBLISHABLE_KEY` token
- On init: calls `loadStripe(publishableKey)`, mounts `CardNumberElement`, `CardExpiryElement`, `CardCvcElement` into three `<div>` containers
- Styled via CSS variables matching existing checkout design (`--ds-color-border`, etc.)
- Exposes method `confirmCard(clientSecret): Promise<{paymentIntentId: string}>`
- Emits `stripeError` output for inline error display

**New injection token**
- `STRIPE_PUBLISHABLE_KEY` provided in `app.config.ts` via `APP_INITIALIZER` — fetches `storeConfig { stripe_publishable_key }` via Apollo at app startup, stores in an `InjectionToken<string>` for `StripeCardComponent` to inject

**`checkout.component.ts` changes**
- Add `stripe_payments` label to `paymentMethodLabels` map
- When user selects `stripe_payments`: call `createStripePaymentIntent` GraphQL → store `clientSecret` signal
- Show `<app-stripe-card>` beneath payment method list when `stripe_payments` is selected
- "Place Order" handler branches on `stripe_payments`:
  1. Call `stripeCardComponent.confirmCard(clientSecret)` → handles 3DS automatically
  2. On success: call `placeStripeOrder(cartId, paymentIntentId)` via Apollo directly
  3. Navigate to `/checkout/success` with order number (same path as existing flow)
  4. On error: display inline error, re-enable Place Order button

**No Daffodil dispatch used for Stripe** — same pattern as the existing `paypal_express` branch which bypasses `DaffCartPaymentUpdate`/`DaffCartPlaceOrder` and calls the service directly.

---

## Data Flow

```
1. User reaches payment step
       ↓
2. createStripePaymentIntent(cart_id)  →  Magento creates PaymentIntent
       ↓ client_secret
3. StripeCardComponent mounts Elements (card number / expiry / CVV iframes)
       ↓
4. User fills card details, clicks "Place Order"
       ↓
5. stripe.confirmCardPayment(client_secret)
       ↓  [3DS modal rendered by Stripe.js if required]
       ↓ paymentIntent.id  (status: succeeded)
6. placeStripeOrder(cart_id, payment_intent_id)  →  Magento verifies + places order
       ↓ order_number
7. Navigate to /checkout/success
```

---

## Error Handling

| Error scenario | Handling |
|---|---|
| Card declined | Stripe.js returns error → displayed inline below card fields |
| 3DS failed/abandoned | Stripe.js returns error → inline display |
| `createStripePaymentIntent` GraphQL fails | Toast notification, Place Order button disabled until retry |
| `placeStripeOrder` fails after Stripe success | "Order could not be placed — you have not been charged" message; PaymentIntent left open for webhook cleanup |
| Module disabled / key not configured | `stripe_payments` absent from `available_payment_methods` → not shown in UI |

---

## Configuration

| Key | Location | Exposed |
|---|---|---|
| Publishable key (`pk_test_...`) | Magento admin → Stores → Config → Payment → Stripe | Via `storeConfig` GraphQL |
| Secret key (`sk_test_...`) | Same admin path | Server-side only |
| Enabled | Same admin path | Implicit (method visibility) |

Initial values set via `bin/magento config:set`:
- `payment/stripe_payments/publishable_key`
- `payment/stripe_payments/secret_key`
- `payment/stripe_payments/active 1`

---

## Testing

**Backend (PHPUnit)**
- `CreateStripePaymentIntentTest` — mocks Stripe SDK, asserts `client_secret` returned
- `PlaceStripeOrderTest` — mocks Stripe SDK with `status: succeeded`, asserts order placed; asserts exception on `status: requires_payment_method`

**Frontend (manual)**
- Stripe test card `4242 4242 4242 4242`, expiry `12/34`, CVV `123` → order success
- Stripe test card `4000 0025 0000 3155` → 3DS modal → authenticate → order success
- Stripe test card `4000 0000 0000 9995` → decline → inline error
