# Typesense Integration Design

**Date:** 2026-04-24
**Branch:** `feature/typesense`
**Status:** Approved

## Context

This project connects a Mage-OS 2.2.1 backend to a Daffodil Angular storefront (`develodesign/dai-builder`) via GraphQL. The current search stack is ElasticSuite v2.11.18.1 + OpenSearch. Typesense replaces ElasticSuite as the search engine for the storefront while ElasticSuite remains installed on `main` (parallel branch strategy).

**Typesense credentials:** Client will supply demo API keys later. All credential wiring is implemented with placeholder env vars — no keys are committed.

---

## Architecture Overview

```
Magento Catalog Events
  → run-as-root/magento2-typesense indexer
    → Typesense Collections (products, categories, cms_pages)

Angular (Daffodil)
  → GraphQL query
    → Develo/TypesenseGraphQl resolver
      → Typesense PHP client
        → Typesense Cloud
```

Everything flows through Magento GraphQL — no Typesense JS SDK on the frontend. This keeps the Daffodil driver pattern consistent with existing integrations (menu, social login, autocomplete).

---

## Backend Design

### 1. Composer Package — `run-as-root/magento2-typesense`

Installed via Composer. Provides:
- Typesense search engine adapter (replaces OpenSearch for Magento catalog queries)
- Indexers for products, categories, CMS pages
- Admin configuration UI (host, port, API key, collection prefix)

**Configuration:** Typesense credentials stored in `app/etc/env.php` under a `typesense` key, never in `core_config_data`:

```php
'typesense' => [
    'host'       => 'xxx.a1.typesense.net',
    'port'       => '443',
    'protocol'   => 'https',
    'api_key'    => 'TYPESENSE_ADMIN_KEY',      // placeholder until client sends keys
    'search_key' => 'TYPESENSE_SEARCH_ONLY_KEY', // read-only key for resolvers
]
```

**Indexed entities:**
- `products` — name, sku, description, price, categories, attributes, stock status, thumbnail URL
- `categories` — name, url, breadcrumb path
- `cms_pages` — title, content, url

### 2. Custom Module — `Develo/TypesenseGraphQl`

Lives in `app/code/Develo/TypesenseGraphQl/`. Owns all GraphQL exposure of Typesense features. Does not duplicate the indexing logic from run-as-root — only queries Typesense via the PHP client.

**Responsibilities:**
- Autocomplete resolver (`typeseenseSuggest` query) — replaces `elasticsuiteSuggest`
- Full-text search resolver with facets (`typesenseSearch` query)
- Reads credentials from `env.php` via a config model (no hardcoding)

**Module structure:**
```
Develo/TypesenseGraphQl/
  Api/
    TypesenseClientInterface.php
  Model/
    Client/TypesenseClient.php         # wraps typesense-php SDK
    Resolver/
      Suggest.php                      # autocomplete: products + categories + terms
      Search.php                       # full search with facets + pagination
    Config.php                         # reads env.php typesense config
  Test/Unit/
    Model/Resolver/SuggestTest.php
    Model/Resolver/SearchTest.php
    Model/ConfigTest.php
  etc/
    module.xml
    di.xml
    schema.graphqls
  registration.php
  composer.json
```

### 3. GraphQL Schema

```graphql
type Query {
    typeseenseSuggest(query: String!): TypeseenseSuggestResult
        @resolver(class: "Develo\\TypesenseGraphQl\\Model\\Resolver\\Suggest")
        @cache(cacheable: false)

    typesenseSearch(
        query: String!
        page: Int = 1
        pageSize: Int = 20
        filters: [TypesenseFilterInput]
        sort: TypesenseSortInput
    ): TypesenseSearchResult
        @resolver(class: "Develo\\TypesenseGraphQl\\Model\\Resolver\\Search")
        @cache(cacheable: false)
}

# Autocomplete
type TypeseenseSuggestResult {
    products: [TypeseenseSuggestProduct]
    categories: [TypeseenseSuggestCategory]
    terms: [TypeseenseSuggestTerm]
}

type TypeseenseSuggestProduct {
    name: String
    url: String
    sku: String
    image_url: String
    price: Float
}

type TypeseenseSuggestCategory {
    name: String
    url: String
    breadcrumb: [String]
}

type TypeseenseSuggestTerm {
    query_text: String
    num_results: Int
}

# Full search
type TypesenseSearchResult {
    items: [TypesenseProductItem]
    facets: [TypesenseFacet]
    total_count: Int
    search_time_ms: Int
    page_info: TypesensePageInfo
}

type TypesenseProductItem {
    id: Int
    name: String
    sku: String
    url: String
    image_url: String
    price: Float
    categories: [String]
}

type TypesenseFacet {
    name: String
    label: String
    options: [TypesenseFacetOption]
}

type TypesenseFacetOption {
    value: String
    label: String
    count: Int
}

type TypesensePageInfo {
    current_page: Int
    page_size: Int
    total_pages: Int
}

input TypesenseFilterInput {
    field: String!
    value: String!
    condition_type: String
}

input TypesenseSortInput {
    field: String!
    direction: String!
}
```

### 4. Error Handling

- If Typesense is unreachable, resolvers return a structured GraphQL error (not a 500) with message `"Search temporarily unavailable"`.
- Config model returns null values gracefully if `env.php` key is missing — resolvers check and throw `GraphQlInputException` with a developer-friendly message.

---

## Frontend Design (Daffodil / Angular)

### Overview

Two new Daffodil drivers, following the existing driver pattern in `dai-builder`:

| Driver | Purpose |
|--------|---------|
| `TypesenseSuggestDriver` | Replaces ElasticSuite autocomplete — drives the search-as-you-type dropdown |
| `TypesenseSearchDriver` | Drives full search results page (PLP equivalent for keyword search) |

Both drivers call Magento GraphQL exclusively — no Typesense JS SDK dependency on the frontend.

### 1. `TypesenseSuggestDriver`

**GraphQL query used:** `typeseenseSuggest`

**Wiring:**
- Injected into the existing Daffodil search bar component (replacing the ElasticSuite suggest driver)
- Debounce: 200ms (same as current)
- Min query length: 2 characters
- Returns: product suggestions, category suggestions, popular terms

**Transformer:** Maps `TypeseenseSuggestResult` → Daffodil `DaffSearchResult[]` shape.

### 2. `TypesenseSearchDriver`

**GraphQL query used:** `typesenseSearch`

**Wiring:**
- Injected into the Daffodil search results / PLP component
- Handles: pagination, facet selection, sort changes
- Facets mapped to Daffodil `DaffFilterRequest` shape for layered nav

**NgRx actions:**
- `TypesenseSearchLoad` — dispatched on query/filter/page change
- `TypesenseSearchSuccess` — populates product list + facets
- `TypesenseSearchFailure` — surfaces error state to UI

### 3. Module structure (Angular)

```
libs/typesense/                         # or driver/typesense/ — match dai-builder conventions
  src/
    drivers/
      typesense-suggest.driver.ts
      typesense-search.driver.ts
    graphql/
      typesense-suggest.query.ts        # gql fragment
      typesense-search.query.ts
    transformers/
      suggest.transformer.ts
      search.transformer.ts
    typesense.module.ts
  index.ts
```

### 4. Configuration

The Angular app receives no Typesense credentials — all queries go through GraphQL. The only config needed is the existing GraphQL endpoint URL (already wired in `dai-builder`).

---

## Testing Strategy

### Backend
- Unit tests for all resolvers (mock Typesense PHP client)
- Unit tests for `Config` model (mock `env.php` reader)
- Follow pattern of `Develo/ElasticSuiteGraphQl/Test/` and `Develo/SocialLoginGraphQl/Test/`

### Frontend
- Unit tests for transformers (pure functions — easy to test)
- Unit tests for drivers (mock GraphQL service)
- Follow dai-builder driver test conventions

---

## Implementation Sequence

### Backend (in `feature/typesense` branch)
1. Install `run-as-root/magento2-typesense` via Composer
2. Configure env.php placeholder + module setup
3. Enable and configure indexers (products, categories, cms_pages)
4. Create `Develo/TypesenseGraphQl` module scaffold
5. Implement `Config` model
6. Implement `TypesenseClient` wrapper
7. Implement `Suggest` resolver + schema
8. Implement `Search` resolver + schema
9. Write unit tests
10. Run `setup:di:compile` and verify GraphQL schema loads

### Frontend (in `feature/typesense` branch of dai-builder)
1. Scaffold `libs/typesense` module
2. Implement GraphQL query fragments
3. Implement suggest transformer + driver
4. Implement search transformer + driver
5. Wire suggest driver into search bar component
6. Wire search driver into search results component
7. Write unit tests

### Integration (after client sends keys)
1. Populate `env.php` with real Typesense credentials
2. Run full reindex
3. Smoke test autocomplete and search results
4. Verify facets and pagination

---

## Out of Scope

- Typesense synonyms management UI (add later if client requests)
- Merchandising / pinned results (Phase 2)
- A/B testing between ElasticSuite and Typesense
- Custom attribute indexing (add per client spec after demo)
