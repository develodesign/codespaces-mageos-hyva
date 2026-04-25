# Typesense JS SDK Refactor Design

**Date:** 2026-04-25
**Branch:** `feature/typesense`
**Status:** Approved
**Supersedes:** `2026-04-24-typesense-integration-design.md` (GraphQL approach)

## Context

The original Typesense design routed all search queries through Magento GraphQL
(`Develo/TypesenseGraphQl`). The client has now provided a Typesense Cloud instance
and wants Angular to call Typesense directly via the JS SDK, which is Typesense's
designed deployment model.

**Typesense Cloud node:** `ge9f4n7drms0qoyvp-1.a2.typesense.net` — port 443, HTTPS

---

## Architecture

```
┌─────────────────────────────────────────────────────┐
│  Angular (Daffodil / dai-builder)                   │
│                                                     │
│  TypesenseSuggestDriver ──┐                         │
│  TypesenseSearchDriver  ──┼──► typesense-js SDK ──► Typesense Cloud
│                           │    (search-only key)    ge9f4n7drms0qoyvp-1.a2.typesense.net:443
│  PriceEnricherService  ───┘──► Magento GraphQL ───► products(sku:{in:[...]})
│                                                     │
└─────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────┐
│  Magento / Mage-OS (feature/typesense branch)       │
│                                                     │
│  run-as-root/magento2-typesense ──────────────────► Typesense Cloud
│  (products, categories, cms_pages indexing)         (admin key in env.php only)
│                                                     │
│  Develo/TypesenseGraphQl  ◄── DELETED               │
│  typesense/typesense-php  ◄── KEPT (indexer dep)    │
└─────────────────────────────────────────────────────┘
```

### Concern Boundary

| Concern | Owner |
|---|---|
| Autocomplete / suggest | Typesense JS SDK |
| Full-text search + facets + pagination | Typesense JS SDK |
| Accurate prices in result cards | Magento GraphQL (batched by SKU) |
| Add-to-cart, checkout | Magento GraphQL |
| Customer-specific / tier pricing | Magento GraphQL |
| Indexing products, categories, cms_pages | Magento PHP (run-as-root) |

---

## Backend Changes

### Delete

`app/code/Develo/TypesenseGraphQl/` — entire module removed from `feature/typesense`.
This includes resolvers, GraphQL schema, PHP TypesenseClient wrapper, Config model,
DI config, and unit tests. The module was never merged to `main`.

### Keep

- `typesense/typesense-php` in `composer.json` — dependency of `run-as-root/magento2-typesense`
- `run-as-root/magento2-typesense` — continues to index products/categories/cms_pages
- `app/etc/env.php` `typesense` block — still needed by the indexer (host, port, protocol, admin key)

The backend work on `feature/typesense` is now: install indexer, configure `env.php`
with real credentials from the client, run `bin/magento indexer:reindex`.

---

## Frontend — Angular Module

### npm dependency

```
typesense   (official JS SDK — typesense-js)
```

### Environment config

```typescript
// environment.ts (and environment.prod.ts)
typesense: {
  host: 'ge9f4n7drms0qoyvp-1.a2.typesense.net',
  port: 443,
  protocol: 'https',
  searchOnlyApiKey: 'REPLACE_WITH_SEARCH_ONLY_KEY',
  collectionPrefix: 'magento_'   // verify against run-as-root admin config before deploying
}
```

The search-only key is safe to embed in browser bundles — it is read-only and
cannot write, delete, or administer collections. The admin key remains in
`env.php` server-side only.

### Module structure

```
libs/typesense/
  src/
    client/
      typesense.client.ts           # Typesense.Client singleton, reads environment.typesense
      typesense.client.spec.ts
    drivers/
      typesense-suggest.driver.ts   # autocomplete: products + categories multi-search
      typesense-suggest.driver.spec.ts
      typesense-search.driver.ts    # full search: facets, pagination, sort
      typesense-search.driver.spec.ts
    transformers/
      suggest.transformer.ts        # TypesenseHit[] → DaffSearchResult[]
      suggest.transformer.spec.ts
      search.transformer.ts         # TypesenseSearchResponse → DaffProductCollection + facets
      search.transformer.spec.ts
    enrichers/
      price-enricher.service.ts     # batched Magento GraphQL price fetch by SKU[]
      price-enricher.service.spec.ts
    graphql/
      product-prices.query.ts       # gql: products(filter:{sku:{in:$skus}}) { price_range }
    store/
      typesense-search.actions.ts
      typesense-search.effects.ts
      typesense-search.effects.spec.ts
      typesense-search.reducer.ts
      typesense-search.selectors.ts
    typesense.module.ts
  index.ts
```

---

## Driver Behaviour

### TypesenseSuggestDriver

- Debounce: 200ms, min query length: 2 chars
- Fires `multiSearch` across `magento_products` and `magento_categories` collections
- Products: `name`, `sku`, `url_key`, `thumbnail_url`, `price` (indexed base price — acceptable at autocomplete speed)
- Categories: `name`, `url_path`, `breadcrumbs`
- Transformer maps to `DaffSearchResult[]`

### TypesenseSearchDriver

- Collection: `magento_products`
- `query_by`: `name,description,sku`
- Facet filters: mapped from Daffodil `DaffFilterRequest` → Typesense `filter_by` syntax
- Pagination: `page` + `per_page` parameters
- Sort: `sort_by` field (`_text_match:desc` / `price:asc` / `price:desc`)
- After resolving hits, triggers `PriceEnricherService`

### PriceEnricherService

- Extracts SKU array for current result page (matches `per_page`, typically 20)
- Fires one batched Magento GraphQL query:
  ```graphql
  query ProductPrices($skus: [String!]!) {
    products(filter: { sku: { in: $skus } }) {
      items {
        sku
        price_range {
          minimum_price { regular_price { value currency } final_price { value } }
        }
        price_tiers { quantity final_price { value } }
      }
    }
  }
  ```
- Maps accurate prices back onto result items by SKU
- Timeout: 2 seconds — if exceeded, result cards retain the Typesense indexed price
- Price skeleton shown in cards while enrichment resolves

---

## Data Flow (Search)

```
User types query
  → TypesenseSearchDriver.search(query, filters, page)
      → Typesense JS SDK search()
          → Typesense Cloud (ge9f4n7drms0qoyvp-1.a2.typesense.net:443)
      ← hits[], facet_counts[], found, search_time_ms
  → search.transformer.ts
      ← DaffProductCollection (indexed prices) + DaffFilterList[]
  → dispatch TypesenseSearchSuccess        ← cards render immediately
  → PriceEnricherService.enrich(skus[])
      → Magento GraphQL products(sku:{in:[...]})
      ← price_range per SKU
  → dispatch TypesensePriceEnrichSuccess   ← prices update in-place
```

---

## Error Handling

| Failure | Behaviour |
|---|---|
| Typesense unreachable | Catch in driver, dispatch `TypesenseSearchFailure`, show "Search unavailable" |
| Typesense 401 invalid key | Catch in driver, dispatch `TypesenseSearchFailure` with developer message |
| Typesense 0 results | Normal empty state via transformer |
| Price enrichment fails | Keep indexed prices, log warning — never blocks search render |
| Price enrichment timeout (>2s) | Same as failure — fallback to indexed price silently |

---

## Testing Strategy

- **Transformers** — pure functions, unit tested with fixture Typesense response JSON (no mocks)
- **Drivers** — mock `TypesenseClientService`; assert correct collection, `query_by`, `filter_by`, `sort_by`
- **PriceEnricherService** — mock Apollo; verify SKU batching, timeout fallback, price merge by SKU
- **Effects** — standard NgRx effects testing; assert action sequence (Search → Success → PriceEnrich → PriceEnrichSuccess)
- Follow patterns of existing dai-builder driver specs and `Develo/ElasticSuiteGraphQl/Test/`

---

## Implementation Sequence

### Phase 1 — Backend cleanup (feature/typesense branch)
1. Delete `app/code/Develo/TypesenseGraphQl/` entirely
2. Populate `app/etc/env.php` `typesense` block with real client credentials
3. Run `bin/magento indexer:reindex` and verify collections appear in Typesense Cloud dashboard
4. Run `setup:di:compile` to confirm no broken DI references

### Phase 2 — Angular (dai-builder feature/typesense branch)
1. Install `typesense` npm package
2. Add `typesense` config block to `environment.ts` / `environment.prod.ts`
3. Scaffold `libs/typesense/` module structure
4. Implement `TypesenseClientService` singleton
5. Implement `suggest.transformer.ts` + tests
6. Implement `TypesenseSuggestDriver` + tests; wire into search bar component
7. Implement `search.transformer.ts` + tests
8. Implement `TypesenseSearchDriver` + tests
9. Implement `PriceEnricherService` + tests (including timeout fallback)
10. Implement NgRx store (actions, effects, reducer, selectors) + tests
11. Wire `TypesenseSearchDriver` into search results component
12. Smoke test: autocomplete, search, facets, pagination, price enrichment

### Phase 3 — Integration verification (after real key supplied)
1. Insert real search-only key into `environment.ts`
2. Run full reindex
3. Verify autocomplete returns results at <200ms
4. Verify facets and pagination function correctly
5. Verify price enrichment shows correct customer-group prices for a logged-in user

---

## Out of Scope

- Synonyms management UI
- Merchandising / pinned results
- A/B testing between old ElasticSuite and Typesense
- Custom attribute indexing (add per client spec post-demo)
- Server-side rendering of search results
