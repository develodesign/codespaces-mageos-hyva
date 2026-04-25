# Typesense JS SDK Frontend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build two Daffodil drivers — `TypesenseSuggestDriver` (autocomplete) and `TypesenseSearchDriver` (full search + facets) — that call Typesense Cloud directly via the JS SDK, plus a `PriceEnricherService` that fetches accurate prices from Magento GraphQL by SKU batch after each search.

**Architecture:** Angular calls Typesense Cloud directly using `typesense-js` with a search-only API key embedded in `environment.ts`. Both drivers follow the existing Daffodil injectable-service driver pattern (same as the ElasticSuite driver already in the project). After search results land, a separate `PriceEnricherService` fires one batched Magento GraphQL query for accurate prices, merging them in-place; if it times out (>2s) the indexed price is kept.

**Tech Stack:** Angular 17+, TypeScript, NgRx, Apollo GraphQL, `typesense` npm package, Daffodil (`@daffodil/search`, `@daffodil/product`), Jest

---

## Background Context

### What the Magento backend provides

A `monogo/magento-typesense-suite` indexer pushes catalog data to Typesense Cloud on every save/reindex. **No Magento GraphQL resolvers exist for search** — the `Develo/TypesenseGraphQl` module was intentionally removed. All search/autocomplete goes directly to Typesense.

### Typesense Cloud connection

| Setting | Value |
|---|---|
| Node | `ge9f4n7drms0qoyvp-1.a2.typesense.net` |
| Port | `443` |
| Protocol | `https` |
| Search-only API key | `uL44TeA9antvemMLdehD2a53wwNa50x4` |

### Collection names

Pattern: `{index_prefix}{store_code}{suffix}`  
With `index_prefix=magento2`, `store_code=default`:

| Collection | Name |
|---|---|
| Products | `magento2default_products` |
| Categories | `magento2default_categories` |
| CMS Pages | `magento2default_cms_pages` |

### Product document fields (key fields only)

| Field | Type | Notes |
|---|---|---|
| `entity_id` | int32 | Magento product ID |
| `sku` | string | used to cross-reference Magento GraphQL prices |
| `name` | string | searchable |
| `url` | string | full storefront URL |
| `url_key` | string | |
| `description_stripped` | string | searchable (HTML stripped) |
| `short_description_stripped` | string | searchable |
| `meta_title`, `meta_keywords`, `meta_description` | string | searchable |
| `category_uid` | string[] | facetable |
| `stock_status` | string | facetable (`In Stock` / `Out of Stock`) |
| `final_price` | float | sortable, indexed base price |
| `currency` | string | |
| `thumbnail` | string | absolute image URL |
| `small_image` | string | absolute image URL |

### Category document fields

| Field | Type |
|---|---|
| `name` | string |
| `url` | string |
| `url_key` | string |
| `path` | string (breadcrumb, slash-separated) |

### Existing pattern to follow

Check `libs/search/drivers/elasticsuite/` (or wherever the ElasticSuite suggest driver lives) before writing anything. Match its file naming, injection token pattern, module wiring, and test style exactly.

### Why prices need enrichment

Typesense stores the **base catalogue price** indexed at reindex time. For logged-in customers with tier/group pricing the displayed price would be wrong. `PriceEnricherService` fires one batched Magento GraphQL `products(filter: {sku: {in: $skus}})` call after search resolves, merges `price_range` back into the result items, and dispatches a second NgRx action to update the UI. If the enrichment call takes >2s the UI keeps the Typesense price silently.

---

## File Map

```
libs/typesense/
  src/
    client/
      typesense.client.ts           ← Typesense.Client singleton (reads environment.typesense)
      typesense.client.spec.ts
    drivers/
      typesense-suggest.driver.ts   ← multiSearch → magento2default_products + _categories
      typesense-suggest.driver.spec.ts
      typesense-search.driver.ts    ← search magento2default_products, facets, pagination, sort
      typesense-search.driver.spec.ts
    transformers/
      suggest.transformer.ts        ← TypesenseMultiSearchResponse → DaffSearchResult[]
      suggest.transformer.spec.ts
      search.transformer.ts         ← TypesenseSearchResponse → DaffProductCollection + DaffFilterList[]
      search.transformer.spec.ts
    enrichers/
      price-enricher.service.ts     ← batched Magento GraphQL price fetch
      price-enricher.service.spec.ts
    graphql/
      product-prices.query.ts       ← gql fragment
    store/
      typesense-search.actions.ts
      typesense-search.effects.ts
      typesense-search.effects.spec.ts
      typesense-search.reducer.ts
      typesense-search.selectors.ts
    typesense.module.ts
  index.ts

Modify (do NOT create):
  src/environments/environment.ts
  src/environments/environment.prod.ts
  [search bar component] — swap suggest driver token
  [search results component] — swap search driver token
```

---

## Prerequisites

- [ ] Verify the collection names are correct: open the Typesense Cloud dashboard at `https://cloud.typesense.org`, log in, confirm collections `magento2default_products` and `magento2default_categories` exist and have documents.
- [ ] Find the existing ElasticSuite suggest driver file path in this repo. All patterns below assume you've read it first. Search for `ElasticSuiteSuggestDriver` or `elasticsuite` in `libs/`.
- [ ] Note the exact injection tokens used for the suggest driver and search driver in the existing code — you'll need to swap them.

---

## Task 1: Install typesense npm package + environment config

**Files:**
- Modify: `package.json`
- Modify: `src/environments/environment.ts`
- Modify: `src/environments/environment.prod.ts`

- [ ] **Step 1: Install the package**

```bash
npm install typesense
```

Expected: `typesense` appears in `package.json` dependencies.

- [ ] **Step 2: Add typesense block to environment.ts**

```typescript
// src/environments/environment.ts  — add inside the exported object:
typesense: {
  host: 'ge9f4n7drms0qoyvp-1.a2.typesense.net',
  port: 443,
  protocol: 'https' as const,
  searchOnlyApiKey: 'uL44TeA9antvemMLdehD2a53wwNa50x4',
  products: 'magento2default_products',
  categories: 'magento2default_categories',
}
```

- [ ] **Step 3: Add same block to environment.prod.ts**

Identical values — credentials are the same for now. The collection names and key will differ per environment when the client sets up staging/prod Typesense clusters.

- [ ] **Step 4: Commit**

```bash
git add package.json package-lock.json src/environments/environment.ts src/environments/environment.prod.ts
git commit -m "feat: add typesense npm package and environment config"
```

---

## Task 2: TypesenseClientService

**Files:**
- Create: `libs/typesense/src/client/typesense.client.ts`
- Create: `libs/typesense/src/client/typesense.client.spec.ts`

- [ ] **Step 1: Write the failing test**

```typescript
// libs/typesense/src/client/typesense.client.spec.ts
import { TestBed } from '@angular/core/testing';
import { TypesenseClientService } from './typesense.client';
import { environment } from '../../../environments/environment';

describe('TypesenseClientService', () => {
  let service: TypesenseClientService;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [TypesenseClientService] });
    service = TestBed.inject(TypesenseClientService);
  });

  it('should be created', () => {
    expect(service).toBeTruthy();
  });

  it('should expose a typesense Client instance', () => {
    expect(service.client).toBeDefined();
  });

  it('should configure the correct node host', () => {
    const nodes = (service.client as any).configuration.nodes;
    expect(nodes[0].host).toBe(environment.typesense.host);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
npx jest libs/typesense/src/client/typesense.client.spec.ts --no-coverage
```

Expected: FAIL — `TypesenseClientService` not found.

- [ ] **Step 3: Implement TypesenseClientService**

```typescript
// libs/typesense/src/client/typesense.client.ts
import { Injectable } from '@angular/core';
import Typesense from 'typesense';
import { Client } from 'typesense/lib/Typesense/Client';
import { environment } from '../../../environments/environment';

@Injectable({ providedIn: 'root' })
export class TypesenseClientService {
  readonly client: Client;

  constructor() {
    this.client = new Typesense.Client({
      nodes: [{
        host: environment.typesense.host,
        port: environment.typesense.port,
        protocol: environment.typesense.protocol,
      }],
      apiKey: environment.typesense.searchOnlyApiKey,
      connectionTimeoutSeconds: 2,
    });
  }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
npx jest libs/typesense/src/client/typesense.client.spec.ts --no-coverage
```

Expected: 3 passing.

- [ ] **Step 5: Commit**

```bash
git add libs/typesense/src/client/
git commit -m "feat: add TypesenseClientService singleton"
```

---

## Task 3: Suggest transformer

**Files:**
- Create: `libs/typesense/src/transformers/suggest.transformer.ts`
- Create: `libs/typesense/src/transformers/suggest.transformer.spec.ts`

- [ ] **Step 1: Write the failing tests**

```typescript
// libs/typesense/src/transformers/suggest.transformer.spec.ts
import { transformSuggestResponse } from './suggest.transformer';

const mockProductHits = [
  {
    document: {
      entity_id: 1,
      sku: 'WH01-XS-Black',
      name: 'Mona Pullover Hoodlie',
      url: 'https://demo.example.com/mona-pullover-hoodie.html',
      url_key: 'mona-pullover-hoodie',
      thumbnail: 'https://demo.example.com/media/catalog/product/w/h/wh01-xs-black.jpg',
      final_price: 57.00,
      currency: 'USD',
    },
    highlights: [],
    text_match: 1000,
  }
];

const mockCategoryHits = [
  {
    document: {
      entity_id: 15,
      name: 'Women',
      url: 'https://demo.example.com/women.html',
      url_key: 'women',
      path: 'Default Category/Women',
    },
    highlights: [],
    text_match: 900,
  }
];

describe('transformSuggestResponse', () => {
  it('maps product hits to suggest products', () => {
    const result = transformSuggestResponse(mockProductHits, []);
    expect(result.products).toHaveLength(1);
    expect(result.products[0].name).toBe('Mona Pullover Hoodlie');
    expect(result.products[0].sku).toBe('WH01-XS-Black');
    expect(result.products[0].url).toBe('https://demo.example.com/mona-pullover-hoodie.html');
    expect(result.products[0].thumbnailUrl).toBe('https://demo.example.com/media/catalog/product/w/h/wh01-xs-black.jpg');
    expect(result.products[0].price).toBe(57.00);
  });

  it('maps category hits to suggest categories', () => {
    const result = transformSuggestResponse([], mockCategoryHits);
    expect(result.categories).toHaveLength(1);
    expect(result.categories[0].name).toBe('Women');
    expect(result.categories[0].url).toBe('https://demo.example.com/women.html');
    expect(result.categories[0].breadcrumb).toEqual(['Default Category', 'Women']);
  });

  it('returns empty arrays when hits are empty', () => {
    const result = transformSuggestResponse([], []);
    expect(result.products).toHaveLength(0);
    expect(result.categories).toHaveLength(0);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
npx jest libs/typesense/src/transformers/suggest.transformer.spec.ts --no-coverage
```

Expected: FAIL — `transformSuggestResponse` not found.

- [ ] **Step 3: Implement the transformer**

```typescript
// libs/typesense/src/transformers/suggest.transformer.ts

export interface TypesenseSuggestProduct {
  name: string;
  sku: string;
  url: string;
  thumbnailUrl: string;
  price: number;
  currency: string;
}

export interface TypesenseSuggestCategory {
  name: string;
  url: string;
  breadcrumb: string[];
}

export interface TypesenseSuggestResult {
  products: TypesenseSuggestProduct[];
  categories: TypesenseSuggestCategory[];
}

export function transformSuggestResponse(
  productHits: any[],
  categoryHits: any[],
): TypesenseSuggestResult {
  return {
    products: productHits.map(hit => ({
      name: hit.document.name,
      sku: hit.document.sku,
      url: hit.document.url,
      thumbnailUrl: hit.document.thumbnail ?? hit.document.small_image ?? '',
      price: hit.document.final_price,
      currency: hit.document.currency ?? 'USD',
    })),
    categories: categoryHits.map(hit => ({
      name: hit.document.name,
      url: hit.document.url,
      breadcrumb: (hit.document.path as string ?? '').split('/').map((s: string) => s.trim()).filter(Boolean),
    })),
  };
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
npx jest libs/typesense/src/transformers/suggest.transformer.spec.ts --no-coverage
```

Expected: 3 passing.

- [ ] **Step 5: Commit**

```bash
git add libs/typesense/src/transformers/suggest.transformer.ts libs/typesense/src/transformers/suggest.transformer.spec.ts
git commit -m "feat: add suggest transformer (Typesense hits → suggest result shape)"
```

---

## Task 4: TypesenseSuggestDriver

**Files:**
- Create: `libs/typesense/src/drivers/typesense-suggest.driver.ts`
- Create: `libs/typesense/src/drivers/typesense-suggest.driver.spec.ts`

> **Before writing:** Read the existing ElasticSuite suggest driver and match its constructor injection token, return type, and method signature exactly. The tests below mock `TypesenseClientService` — adjust the mock shape if the SDK type differs.

- [ ] **Step 1: Write the failing tests**

```typescript
// libs/typesense/src/drivers/typesense-suggest.driver.spec.ts
import { TestBed } from '@angular/core/testing';
import { TypesenseSuggestDriver } from './typesense-suggest.driver';
import { TypesenseClientService } from '../client/typesense.client';
import { environment } from '../../../environments/environment';

const mockMultiSearchResult = {
  results: [
    {
      hits: [
        {
          document: {
            entity_id: 1, sku: 'WH01', name: 'Test Product',
            url: 'http://x.com/p.html', url_key: 'p',
            thumbnail: 'http://x.com/img.jpg',
            final_price: 10, currency: 'USD',
          },
          highlights: [], text_match: 1000,
        }
      ],
      found: 1,
    },
    { hits: [], found: 0 }, // categories result
  ],
};

describe('TypesenseSuggestDriver', () => {
  let driver: TypesenseSuggestDriver;
  let mockMultiSearch: jest.Mock;

  beforeEach(() => {
    mockMultiSearch = jest.fn().mockResolvedValue(mockMultiSearchResult);

    TestBed.configureTestingModule({
      providers: [
        TypesenseSuggestDriver,
        {
          provide: TypesenseClientService,
          useValue: {
            client: { multiSearch: { perform: mockMultiSearch } },
          },
        },
      ],
    });
    driver = TestBed.inject(TypesenseSuggestDriver);
  });

  it('should be created', () => {
    expect(driver).toBeTruthy();
  });

  it('should call multiSearch with both products and categories collections', async () => {
    await driver.search('test');
    expect(mockMultiSearch).toHaveBeenCalledTimes(1);
    const [payload] = mockMultiSearch.mock.calls[0];
    const collections = payload.searches.map((s: any) => s.collection);
    expect(collections).toContain(environment.typesense.products);
    expect(collections).toContain(environment.typesense.categories);
  });

  it('should return transformed products from search', async () => {
    const result = await driver.search('test');
    expect(result.products).toHaveLength(1);
    expect(result.products[0].sku).toBe('WH01');
  });

  it('should not call Typesense when query is shorter than 2 chars', async () => {
    const result = await driver.search('a');
    expect(mockMultiSearch).not.toHaveBeenCalled();
    expect(result.products).toHaveLength(0);
    expect(result.categories).toHaveLength(0);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
npx jest libs/typesense/src/drivers/typesense-suggest.driver.spec.ts --no-coverage
```

Expected: FAIL.

- [ ] **Step 3: Implement TypesenseSuggestDriver**

```typescript
// libs/typesense/src/drivers/typesense-suggest.driver.ts
import { Injectable } from '@angular/core';
import { TypesenseClientService } from '../client/typesense.client';
import { transformSuggestResponse, TypesenseSuggestResult } from '../transformers/suggest.transformer';
import { environment } from '../../../environments/environment';

const MIN_QUERY_LENGTH = 2;
const SUGGEST_LIMIT = 5;

@Injectable()
export class TypesenseSuggestDriver {
  constructor(private typesenseClient: TypesenseClientService) {}

  async search(query: string): Promise<TypesenseSuggestResult> {
    if (query.length < MIN_QUERY_LENGTH) {
      return { products: [], categories: [] };
    }

    const result = await this.typesenseClient.client.multiSearch.perform({
      searches: [
        {
          collection: environment.typesense.products,
          q: query,
          query_by: 'name,sku,meta_title,meta_keywords',
          per_page: SUGGEST_LIMIT,
          include_fields: 'entity_id,sku,name,url,url_key,thumbnail,small_image,final_price,currency',
        },
        {
          collection: environment.typesense.categories,
          q: query,
          query_by: 'name',
          per_page: SUGGEST_LIMIT,
          include_fields: 'entity_id,name,url,url_key,path',
        },
      ],
    });

    const [productsResult, categoriesResult] = result.results;
    return transformSuggestResponse(
      productsResult.hits ?? [],
      categoriesResult.hits ?? [],
    );
  }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
npx jest libs/typesense/src/drivers/typesense-suggest.driver.spec.ts --no-coverage
```

Expected: 4 passing.

- [ ] **Step 5: Commit**

```bash
git add libs/typesense/src/drivers/typesense-suggest.driver.ts libs/typesense/src/drivers/typesense-suggest.driver.spec.ts
git commit -m "feat: add TypesenseSuggestDriver (autocomplete via JS SDK)"
```

---

## Task 5: Search transformer

**Files:**
- Create: `libs/typesense/src/transformers/search.transformer.ts`
- Create: `libs/typesense/src/transformers/search.transformer.spec.ts`

- [ ] **Step 1: Write the failing tests**

```typescript
// libs/typesense/src/transformers/search.transformer.spec.ts
import { transformSearchResponse } from './search.transformer';

const mockSearchResponse = {
  hits: [
    {
      document: {
        entity_id: 1,
        sku: 'MH01-XS-Black',
        name: 'Chaz Kangeroo Hoodie',
        url: 'https://demo.example.com/chaz-kangeroo-hoodie.html',
        url_key: 'chaz-kangeroo-hoodie',
        thumbnail: 'https://demo.example.com/media/catalog/product/m/h/mh01-xs-black.jpg',
        final_price: 52.00,
        currency: 'USD',
        stock_status: 'In Stock',
        category_uid: ['abc123', 'def456'],
      },
      highlights: [],
      text_match: 1000,
    }
  ],
  found: 1,
  page: 1,
  search_time_ms: 3,
  facet_counts: [
    {
      field_name: 'stock_status',
      counts: [{ value: 'In Stock', count: 42 }],
    },
    {
      field_name: 'category_uid',
      counts: [
        { value: 'abc123', count: 10 },
        { value: 'def456', count: 5 },
      ],
    },
  ],
};

describe('transformSearchResponse', () => {
  it('maps hits to search items', () => {
    const result = transformSearchResponse(mockSearchResponse, 20);
    expect(result.items).toHaveLength(1);
    expect(result.items[0].sku).toBe('MH01-XS-Black');
    expect(result.items[0].name).toBe('Chaz Kangeroo Hoodie');
    expect(result.items[0].price).toBe(52.00);
    expect(result.items[0].thumbnailUrl).toBe(
      'https://demo.example.com/media/catalog/product/m/h/mh01-xs-black.jpg'
    );
  });

  it('maps facet_counts to filter list', () => {
    const result = transformSearchResponse(mockSearchResponse, 20);
    const stockFacet = result.facets.find(f => f.name === 'stock_status');
    expect(stockFacet).toBeDefined();
    expect(stockFacet!.options[0].value).toBe('In Stock');
    expect(stockFacet!.options[0].count).toBe(42);
  });

  it('computes pagination correctly', () => {
    const result = transformSearchResponse(mockSearchResponse, 20);
    expect(result.pageInfo.currentPage).toBe(1);
    expect(result.pageInfo.pageSize).toBe(20);
    expect(result.pageInfo.totalPages).toBe(1);
    expect(result.totalCount).toBe(1);
  });

  it('returns empty state for empty response', () => {
    const result = transformSearchResponse(
      { hits: [], found: 0, page: 1, search_time_ms: 1, facet_counts: [] },
      20
    );
    expect(result.items).toHaveLength(0);
    expect(result.totalCount).toBe(0);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
npx jest libs/typesense/src/transformers/search.transformer.spec.ts --no-coverage
```

Expected: FAIL.

- [ ] **Step 3: Implement the search transformer**

```typescript
// libs/typesense/src/transformers/search.transformer.ts

export interface TypesenseSearchItem {
  entityId: number;
  sku: string;
  name: string;
  url: string;
  thumbnailUrl: string;
  price: number;
  currency: string;
  stockStatus: string;
}

export interface TypesenseFacetOption {
  value: string;
  count: number;
}

export interface TypesenseFacet {
  name: string;
  options: TypesenseFacetOption[];
}

export interface TypesensePageInfo {
  currentPage: number;
  pageSize: number;
  totalPages: number;
}

export interface TypesenseSearchResult {
  items: TypesenseSearchItem[];
  facets: TypesenseFacet[];
  totalCount: number;
  searchTimeMs: number;
  pageInfo: TypesensePageInfo;
}

export function transformSearchResponse(
  response: any,
  pageSize: number,
): TypesenseSearchResult {
  const totalCount: number = response.found ?? 0;
  const currentPage: number = response.page ?? 1;

  return {
    items: (response.hits ?? []).map((hit: any) => ({
      entityId: hit.document.entity_id,
      sku: hit.document.sku,
      name: hit.document.name,
      url: hit.document.url,
      thumbnailUrl: hit.document.thumbnail ?? hit.document.small_image ?? '',
      price: hit.document.final_price,
      currency: hit.document.currency ?? 'USD',
      stockStatus: hit.document.stock_status ?? '',
    })),
    facets: (response.facet_counts ?? []).map((facet: any) => ({
      name: facet.field_name,
      options: (facet.counts ?? []).map((c: any) => ({
        value: c.value,
        count: c.count,
      })),
    })),
    totalCount,
    searchTimeMs: response.search_time_ms ?? 0,
    pageInfo: {
      currentPage,
      pageSize,
      totalPages: Math.ceil(totalCount / pageSize) || 1,
    },
  };
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
npx jest libs/typesense/src/transformers/search.transformer.spec.ts --no-coverage
```

Expected: 4 passing.

- [ ] **Step 5: Commit**

```bash
git add libs/typesense/src/transformers/search.transformer.ts libs/typesense/src/transformers/search.transformer.spec.ts
git commit -m "feat: add search transformer (Typesense response → search result shape)"
```

---

## Task 6: NgRx Store — actions + reducer + selectors

**Files:**
- Create: `libs/typesense/src/store/typesense-search.actions.ts`
- Create: `libs/typesense/src/store/typesense-search.reducer.ts`
- Create: `libs/typesense/src/store/typesense-search.selectors.ts`

- [ ] **Step 1: Create actions**

```typescript
// libs/typesense/src/store/typesense-search.actions.ts
import { createAction, props } from '@ngrx/store';
import { TypesenseSearchResult, TypesenseSearchItem } from '../transformers/search.transformer';

export const typesenseSearch = createAction(
  '[Typesense] Search',
  props<{ query: string; page: number; pageSize: number; filters: Record<string, string> }>()
);

export const typesenseSearchSuccess = createAction(
  '[Typesense] Search Success',
  props<{ result: TypesenseSearchResult; query: string }>()
);

export const typesenseSearchFailure = createAction(
  '[Typesense] Search Failure',
  props<{ error: string }>()
);

export const typesensePriceEnrichSuccess = createAction(
  '[Typesense] Price Enrich Success',
  props<{ prices: Record<string, number> }>()   // keyed by SKU
);
```

- [ ] **Step 2: Write failing reducer tests**

```typescript
// libs/typesense/src/store/typesense-search.reducer.spec.ts
import { typesenseSearchReducer, initialTypesenseSearchState } from './typesense-search.reducer';
import { typesenseSearch, typesenseSearchSuccess, typesenseSearchFailure, typesensePriceEnrichSuccess } from './typesense-search.actions';

describe('typesenseSearchReducer', () => {
  it('should set loading=true on typesenseSearch', () => {
    const action = typesenseSearch({ query: 'hoodie', page: 1, pageSize: 20, filters: {} });
    const state = typesenseSearchReducer(initialTypesenseSearchState, action);
    expect(state.loading).toBe(true);
    expect(state.error).toBeNull();
  });

  it('should store result on typesenseSearchSuccess', () => {
    const result = {
      items: [{ entityId: 1, sku: 'ABC', name: 'Test', url: '', thumbnailUrl: '', price: 10, currency: 'USD', stockStatus: 'In Stock' }],
      facets: [],
      totalCount: 1,
      searchTimeMs: 2,
      pageInfo: { currentPage: 1, pageSize: 20, totalPages: 1 },
    };
    const action = typesenseSearchSuccess({ result, query: 'test' });
    const state = typesenseSearchReducer(initialTypesenseSearchState, action);
    expect(state.loading).toBe(false);
    expect(state.items).toHaveLength(1);
    expect(state.totalCount).toBe(1);
  });

  it('should store error on typesenseSearchFailure', () => {
    const action = typesenseSearchFailure({ error: 'Typesense unavailable' });
    const state = typesenseSearchReducer(initialTypesenseSearchState, action);
    expect(state.loading).toBe(false);
    expect(state.error).toBe('Typesense unavailable');
  });

  it('should update item prices on typesensePriceEnrichSuccess', () => {
    const loadedState = {
      ...initialTypesenseSearchState,
      items: [
        { entityId: 1, sku: 'ABC', name: 'Test', url: '', thumbnailUrl: '', price: 10, currency: 'USD', stockStatus: 'In Stock' },
      ],
    };
    const action = typesensePriceEnrichSuccess({ prices: { 'ABC': 29.99 } });
    const state = typesenseSearchReducer(loadedState, action);
    expect(state.items[0].price).toBe(29.99);
  });
});
```

- [ ] **Step 3: Run test to verify it fails**

```bash
npx jest libs/typesense/src/store/typesense-search.reducer.spec.ts --no-coverage
```

Expected: FAIL.

- [ ] **Step 4: Implement reducer**

```typescript
// libs/typesense/src/store/typesense-search.reducer.ts
import { createReducer, on } from '@ngrx/store';
import { TypesenseSearchItem } from '../transformers/search.transformer';
import { TypesenseFacet, TypesensePageInfo } from '../transformers/search.transformer';
import {
  typesenseSearch, typesenseSearchSuccess,
  typesenseSearchFailure, typesensePriceEnrichSuccess,
} from './typesense-search.actions';

export interface TypesenseSearchState {
  loading: boolean;
  query: string;
  items: TypesenseSearchItem[];
  facets: TypesenseFacet[];
  totalCount: number;
  pageInfo: TypesensePageInfo;
  error: string | null;
}

export const initialTypesenseSearchState: TypesenseSearchState = {
  loading: false,
  query: '',
  items: [],
  facets: [],
  totalCount: 0,
  pageInfo: { currentPage: 1, pageSize: 20, totalPages: 1 },
  error: null,
};

export const typesenseSearchReducer = createReducer(
  initialTypesenseSearchState,
  on(typesenseSearch, (state) => ({ ...state, loading: true, error: null })),
  on(typesenseSearchSuccess, (state, { result, query }) => ({
    ...state,
    loading: false,
    query,
    items: result.items,
    facets: result.facets,
    totalCount: result.totalCount,
    pageInfo: result.pageInfo,
  })),
  on(typesenseSearchFailure, (state, { error }) => ({
    ...state,
    loading: false,
    error,
  })),
  on(typesensePriceEnrichSuccess, (state, { prices }) => ({
    ...state,
    items: state.items.map(item =>
      prices[item.sku] !== undefined
        ? { ...item, price: prices[item.sku] }
        : item
    ),
  })),
);
```

- [ ] **Step 5: Add selectors**

```typescript
// libs/typesense/src/store/typesense-search.selectors.ts
import { createFeatureSelector, createSelector } from '@ngrx/store';
import { TypesenseSearchState } from './typesense-search.reducer';

export const selectTypesenseSearchState =
  createFeatureSelector<TypesenseSearchState>('typesenseSearch');

export const selectTypesenseItems = createSelector(
  selectTypesenseSearchState, s => s.items
);
export const selectTypesenseFacets = createSelector(
  selectTypesenseSearchState, s => s.facets
);
export const selectTypesenseTotalCount = createSelector(
  selectTypesenseSearchState, s => s.totalCount
);
export const selectTypesensePageInfo = createSelector(
  selectTypesenseSearchState, s => s.pageInfo
);
export const selectTypesenseLoading = createSelector(
  selectTypesenseSearchState, s => s.loading
);
export const selectTypesenseError = createSelector(
  selectTypesenseSearchState, s => s.error
);
```

- [ ] **Step 6: Run tests to verify they pass**

```bash
npx jest libs/typesense/src/store/typesense-search.reducer.spec.ts --no-coverage
```

Expected: 4 passing.

- [ ] **Step 7: Commit**

```bash
git add libs/typesense/src/store/
git commit -m "feat: add Typesense NgRx store (actions, reducer, selectors)"
```

---

## Task 7: PriceEnricherService

**Files:**
- Create: `libs/typesense/src/graphql/product-prices.query.ts`
- Create: `libs/typesense/src/enrichers/price-enricher.service.ts`
- Create: `libs/typesense/src/enrichers/price-enricher.service.spec.ts`

- [ ] **Step 1: Create the GraphQL query fragment**

```typescript
// libs/typesense/src/graphql/product-prices.query.ts
import { gql } from 'apollo-angular';

export const PRODUCT_PRICES_QUERY = gql`
  query TypesenseProductPrices($skus: [String!]!) {
    products(filter: { sku: { in: $skus } }) {
      items {
        sku
        price_range {
          minimum_price {
            final_price {
              value
              currency
            }
          }
        }
      }
    }
  }
`;
```

- [ ] **Step 2: Write failing tests**

```typescript
// libs/typesense/src/enrichers/price-enricher.service.spec.ts
import { TestBed, fakeAsync, tick } from '@angular/core/testing';
import { Apollo } from 'apollo-angular';
import { of, throwError } from 'rxjs';
import { PriceEnricherService } from './price-enricher.service';

describe('PriceEnricherService', () => {
  let service: PriceEnricherService;
  let mockApollo: { query: jest.Mock };

  const mockApolloResponse = {
    data: {
      products: {
        items: [
          { sku: 'WH01', price_range: { minimum_price: { final_price: { value: 29.99, currency: 'USD' } } } },
          { sku: 'MH01', price_range: { minimum_price: { final_price: { value: 52.00, currency: 'USD' } } } },
        ]
      }
    }
  };

  beforeEach(() => {
    mockApollo = { query: jest.fn().mockReturnValue(of(mockApolloResponse)) };
    TestBed.configureTestingModule({
      providers: [
        PriceEnricherService,
        { provide: Apollo, useValue: mockApollo },
      ],
    });
    service = TestBed.inject(PriceEnricherService);
  });

  it('should be created', () => {
    expect(service).toBeTruthy();
  });

  it('should return prices keyed by SKU', async () => {
    const prices = await service.enrichPrices(['WH01', 'MH01']);
    expect(prices['WH01']).toBe(29.99);
    expect(prices['MH01']).toBe(52.00);
  });

  it('should return empty object when Apollo errors', async () => {
    mockApollo.query.mockReturnValue(throwError(() => new Error('Network error')));
    const prices = await service.enrichPrices(['WH01']);
    expect(prices).toEqual({});
  });

  it('should return empty object for empty SKU list', async () => {
    const prices = await service.enrichPrices([]);
    expect(mockApollo.query).not.toHaveBeenCalled();
    expect(prices).toEqual({});
  });
});
```

- [ ] **Step 3: Run test to verify it fails**

```bash
npx jest libs/typesense/src/enrichers/price-enricher.service.spec.ts --no-coverage
```

Expected: FAIL.

- [ ] **Step 4: Implement PriceEnricherService**

```typescript
// libs/typesense/src/enrichers/price-enricher.service.ts
import { Injectable } from '@angular/core';
import { Apollo } from 'apollo-angular';
import { firstValueFrom, timeout, catchError, of } from 'rxjs';
import { PRODUCT_PRICES_QUERY } from '../graphql/product-prices.query';

const PRICE_ENRICH_TIMEOUT_MS = 2000;

@Injectable({ providedIn: 'root' })
export class PriceEnricherService {
  constructor(private apollo: Apollo) {}

  async enrichPrices(skus: string[]): Promise<Record<string, number>> {
    if (skus.length === 0) return {};

    try {
      const response = await firstValueFrom(
        this.apollo.query<any>({
          query: PRODUCT_PRICES_QUERY,
          variables: { skus },
          fetchPolicy: 'network-only',
        }).pipe(
          timeout(PRICE_ENRICH_TIMEOUT_MS),
          catchError(() => of({ data: { products: { items: [] } } }))
        )
      );

      const prices: Record<string, number> = {};
      for (const item of response.data?.products?.items ?? []) {
        const value = item.price_range?.minimum_price?.final_price?.value;
        if (item.sku && value !== undefined) {
          prices[item.sku] = value;
        }
      }
      return prices;
    } catch {
      return {};
    }
  }
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
npx jest libs/typesense/src/enrichers/price-enricher.service.spec.ts --no-coverage
```

Expected: 4 passing.

- [ ] **Step 6: Commit**

```bash
git add libs/typesense/src/graphql/ libs/typesense/src/enrichers/
git commit -m "feat: add PriceEnricherService (batched Magento GraphQL price fetch)"
```

---

## Task 8: TypesenseSearchDriver + NgRx Effects

**Files:**
- Create: `libs/typesense/src/drivers/typesense-search.driver.ts`
- Create: `libs/typesense/src/drivers/typesense-search.driver.spec.ts`
- Create: `libs/typesense/src/store/typesense-search.effects.ts`
- Create: `libs/typesense/src/store/typesense-search.effects.spec.ts`

- [ ] **Step 1: Write failing driver tests**

```typescript
// libs/typesense/src/drivers/typesense-search.driver.spec.ts
import { TestBed } from '@angular/core/testing';
import { TypesenseSearchDriver } from './typesense-search.driver';
import { TypesenseClientService } from '../client/typesense.client';
import { environment } from '../../../environments/environment';

const mockSearchResult = {
  hits: [
    {
      document: {
        entity_id: 1, sku: 'WH01', name: 'Product',
        url: 'http://x.com/p.html', url_key: 'p',
        thumbnail: 'http://x.com/img.jpg',
        final_price: 57, currency: 'USD',
        stock_status: 'In Stock',
      },
      highlights: [], text_match: 1000,
    }
  ],
  found: 1, page: 1, search_time_ms: 3,
  facet_counts: [{ field_name: 'stock_status', counts: [{ value: 'In Stock', count: 1 }] }],
};

describe('TypesenseSearchDriver', () => {
  let driver: TypesenseSearchDriver;
  let mockSearch: jest.Mock;

  beforeEach(() => {
    mockSearch = jest.fn().mockResolvedValue(mockSearchResult);
    TestBed.configureTestingModule({
      providers: [
        TypesenseSearchDriver,
        {
          provide: TypesenseClientService,
          useValue: { client: { collections: () => ({ documents: () => ({ search: mockSearch }) }) } },
        },
      ],
    });
    driver = TestBed.inject(TypesenseSearchDriver);
  });

  it('should be created', () => expect(driver).toBeTruthy());

  it('should search the products collection', async () => {
    await driver.search({ query: 'hoodie', page: 1, pageSize: 20, filters: {} });
    expect(mockSearch).toHaveBeenCalledTimes(1);
  });

  it('should request correct facets', async () => {
    await driver.search({ query: 'hoodie', page: 1, pageSize: 20, filters: {} });
    const [params] = mockSearch.mock.calls[0];
    expect(params.facet_by).toContain('stock_status');
    expect(params.facet_by).toContain('category_uid');
  });

  it('should pass page and page_size to Typesense', async () => {
    await driver.search({ query: 'test', page: 2, pageSize: 12, filters: {} });
    const [params] = mockSearch.mock.calls[0];
    expect(params.page).toBe(2);
    expect(params.per_page).toBe(12);
  });

  it('should map filter values to filter_by string', async () => {
    await driver.search({ query: 'test', page: 1, pageSize: 20, filters: { stock_status: 'In Stock' } });
    const [params] = mockSearch.mock.calls[0];
    expect(params.filter_by).toContain('stock_status');
  });

  it('should return transformed result', async () => {
    const result = await driver.search({ query: 'hoodie', page: 1, pageSize: 20, filters: {} });
    expect(result.items).toHaveLength(1);
    expect(result.items[0].sku).toBe('WH01');
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
npx jest libs/typesense/src/drivers/typesense-search.driver.spec.ts --no-coverage
```

Expected: FAIL.

- [ ] **Step 3: Implement TypesenseSearchDriver**

```typescript
// libs/typesense/src/drivers/typesense-search.driver.ts
import { Injectable } from '@angular/core';
import { TypesenseClientService } from '../client/typesense.client';
import { transformSearchResponse, TypesenseSearchResult } from '../transformers/search.transformer';
import { environment } from '../../../environments/environment';

export interface TypesenseSearchParams {
  query: string;
  page: number;
  pageSize: number;
  filters: Record<string, string>;
  sortBy?: string;
}

@Injectable()
export class TypesenseSearchDriver {
  constructor(private typesenseClient: TypesenseClientService) {}

  async search(params: TypesenseSearchParams): Promise<TypesenseSearchResult> {
    const filterBy = Object.entries(params.filters)
      .map(([field, value]) => `${field}:=[${value}]`)
      .join(' && ');

    const searchParams: any = {
      q: params.query || '*',
      query_by: 'name,sku,description_stripped,short_description_stripped,meta_title,meta_keywords',
      facet_by: 'stock_status,category_uid',
      page: params.page,
      per_page: params.pageSize,
      sort_by: params.sortBy ?? '_text_match:desc,final_price:asc',
    };

    if (filterBy) {
      searchParams.filter_by = filterBy;
    }

    const response = await this.typesenseClient.client
      .collections(environment.typesense.products)
      .documents()
      .search(searchParams);

    return transformSearchResponse(response, params.pageSize);
  }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
npx jest libs/typesense/src/drivers/typesense-search.driver.spec.ts --no-coverage
```

Expected: 6 passing.

- [ ] **Step 5: Write failing effects tests**

```typescript
// libs/typesense/src/store/typesense-search.effects.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideMockActions } from '@ngrx/effects/testing';
import { Observable, of } from 'rxjs';
import { Action } from '@ngrx/store';
import { TypesenseSearchEffects } from './typesense-search.effects';
import { TypesenseSearchDriver } from '../drivers/typesense-search.driver';
import { PriceEnricherService } from '../enrichers/price-enricher.service';
import {
  typesenseSearch, typesenseSearchSuccess,
  typesenseSearchFailure, typesensePriceEnrichSuccess,
} from './typesense-search.actions';

const mockSearchResult = {
  items: [{ entityId: 1, sku: 'WH01', name: 'Test', url: '', thumbnailUrl: '', price: 57, currency: 'USD', stockStatus: 'In Stock' }],
  facets: [],
  totalCount: 1,
  searchTimeMs: 3,
  pageInfo: { currentPage: 1, pageSize: 20, totalPages: 1 },
};

describe('TypesenseSearchEffects', () => {
  let actions$: Observable<Action>;
  let effects: TypesenseSearchEffects;
  let mockDriver: { search: jest.Mock };
  let mockEnricher: { enrichPrices: jest.Mock };

  beforeEach(() => {
    mockDriver = { search: jest.fn().mockResolvedValue(mockSearchResult) };
    mockEnricher = { enrichPrices: jest.fn().mockResolvedValue({ 'WH01': 29.99 }) };

    TestBed.configureTestingModule({
      providers: [
        TypesenseSearchEffects,
        provideMockActions(() => actions$),
        { provide: TypesenseSearchDriver, useValue: mockDriver },
        { provide: PriceEnricherService, useValue: mockEnricher },
      ],
    });
    effects = TestBed.inject(TypesenseSearchEffects);
  });

  it('should dispatch typesenseSearchSuccess then typesensePriceEnrichSuccess', done => {
    actions$ = of(typesenseSearch({ query: 'hoodie', page: 1, pageSize: 20, filters: {} }));
    const dispatched: Action[] = [];

    effects.search$.subscribe(action => {
      dispatched.push(action);
      if (dispatched.length === 2) {
        expect(dispatched[0].type).toBe(typesenseSearchSuccess.type);
        expect(dispatched[1].type).toBe(typesensePriceEnrichSuccess.type);
        done();
      }
    });
  });

  it('should dispatch typesenseSearchFailure when driver throws', done => {
    mockDriver.search.mockRejectedValue(new Error('Typesense down'));
    actions$ = of(typesenseSearch({ query: 'hoodie', page: 1, pageSize: 20, filters: {} }));

    effects.search$.subscribe(action => {
      expect(action.type).toBe(typesenseSearchFailure.type);
      done();
    });
  });
});
```

- [ ] **Step 6: Run test to verify it fails**

```bash
npx jest libs/typesense/src/store/typesense-search.effects.spec.ts --no-coverage
```

Expected: FAIL.

- [ ] **Step 7: Implement effects**

```typescript
// libs/typesense/src/store/typesense-search.effects.ts
import { Injectable } from '@angular/core';
import { Actions, createEffect, ofType } from '@ngrx/effects';
import { from, of, merge, EMPTY } from 'rxjs';
import { switchMap, mergeMap, catchError } from 'rxjs/operators';
import { TypesenseSearchDriver } from '../drivers/typesense-search.driver';
import { PriceEnricherService } from '../enrichers/price-enricher.service';
import {
  typesenseSearch, typesenseSearchSuccess,
  typesenseSearchFailure, typesensePriceEnrichSuccess,
} from './typesense-search.actions';

@Injectable()
export class TypesenseSearchEffects {
  search$ = createEffect(() =>
    this.actions$.pipe(
      ofType(typesenseSearch),
      switchMap(action =>
        from(this.driver.search({
          query: action.query,
          page: action.page,
          pageSize: action.pageSize,
          filters: action.filters,
        })).pipe(
          mergeMap(result => {
            const skus = result.items.map(i => i.sku);
            const enrichPrice$ = from(this.enricher.enrichPrices(skus)).pipe(
              mergeMap(prices => of(typesensePriceEnrichSuccess({ prices }))),
              catchError(() => EMPTY),
            );
            return merge(
              of(typesenseSearchSuccess({ result, query: action.query })),
              enrichPrice$,
            );
          }),
          catchError(err =>
            of(typesenseSearchFailure({ error: err?.message ?? 'Search unavailable' }))
          ),
        )
      )
    )
  );

  constructor(
    private actions$: Actions,
    private driver: TypesenseSearchDriver,
    private enricher: PriceEnricherService,
  ) {}
}
```

- [ ] **Step 8: Run all tests to verify they pass**

```bash
npx jest libs/typesense/src/store/typesense-search.effects.spec.ts --no-coverage
```

Expected: 2 passing.

- [ ] **Step 9: Commit**

```bash
git add libs/typesense/src/drivers/typesense-search.driver.ts libs/typesense/src/drivers/typesense-search.driver.spec.ts libs/typesense/src/store/typesense-search.effects.ts libs/typesense/src/store/typesense-search.effects.spec.ts
git commit -m "feat: add TypesenseSearchDriver and NgRx effects with price enrichment"
```

---

## Task 9: Angular module + wiring

**Files:**
- Create: `libs/typesense/src/typesense.module.ts`
- Create: `libs/typesense/index.ts`
- Modify: the existing Angular app module that provides the suggest driver
- Modify: the existing Angular app module that provides the search driver

- [ ] **Step 1: Create the Angular module**

```typescript
// libs/typesense/src/typesense.module.ts
import { NgModule } from '@angular/core';
import { StoreModule } from '@ngrx/store';
import { EffectsModule } from '@ngrx/effects';
import { TypesenseSuggestDriver } from './drivers/typesense-suggest.driver';
import { TypesenseSearchDriver } from './drivers/typesense-search.driver';
import { TypesenseSearchEffects } from './store/typesense-search.effects';
import { typesenseSearchReducer } from './store/typesense-search.reducer';

@NgModule({
  imports: [
    StoreModule.forFeature('typesenseSearch', typesenseSearchReducer),
    EffectsModule.forFeature([TypesenseSearchEffects]),
  ],
  providers: [
    TypesenseSuggestDriver,
    TypesenseSearchDriver,
  ],
})
export class TypesenseModule {}
```

- [ ] **Step 2: Create the public index**

```typescript
// libs/typesense/index.ts
export { TypesenseModule } from './src/typesense.module';
export { TypesenseSuggestDriver } from './src/drivers/typesense-suggest.driver';
export { TypesenseSearchDriver } from './src/drivers/typesense-search.driver';
export * from './src/store/typesense-search.actions';
export * from './src/store/typesense-search.selectors';
export * from './src/transformers/suggest.transformer';
export * from './src/transformers/search.transformer';
```

- [ ] **Step 3: Wire the suggest driver into the search bar**

Find the component or module that currently injects the ElasticSuite suggest driver (search for `ElasticSuiteSuggestDriver` or the injection token). Replace or add the `TypesenseSuggestDriver` provider. The exact change depends on the project's injection token pattern — follow the existing convention.

- [ ] **Step 4: Wire the search driver + import TypesenseModule**

Find the search results or PLP module. Import `TypesenseModule` and replace the existing search driver provider with `TypesenseSearchDriver`. Dispatch `typesenseSearch` on query/filter/page changes.

- [ ] **Step 5: Run all Typesense library tests**

```bash
npx jest libs/typesense/ --no-coverage
```

Expected: all passing.

- [ ] **Step 6: Commit**

```bash
git add libs/typesense/src/typesense.module.ts libs/typesense/index.ts
git add [modified component/module files]
git commit -m "feat: wire TypesenseSuggestDriver and TypesenseSearchDriver into app"
```

---

## Task 10: Smoke test end-to-end

- [ ] **Step 1: Start the Angular dev server**

```bash
ng serve
```

- [ ] **Step 2: Test autocomplete**
  - Open the storefront, type `hoo` in the search bar (≥2 chars)
  - Expect: dropdown shows product suggestions with names, thumbnails, prices
  - Expect: category suggestions appear
  - Expect: network tab shows a request to `ge9f4n7drms0qoyvp-1.a2.typesense.net`

- [ ] **Step 3: Test full search**
  - Submit the search form with query `hoodie`
  - Expect: results page renders product cards from Typesense
  - Expect: facets (`stock_status`, `category_uid`) render in sidebar
  - Expect: a second network request to Magento GraphQL endpoint fires for prices
  - Expect: prices update in-place within ~200ms

- [ ] **Step 4: Test pagination**
  - Navigate to page 2
  - Expect: `typesenseSearch` action dispatched with `page: 2`
  - Expect: different products load

- [ ] **Step 5: Test price enrichment fallback**
  - In browser devtools, block the Magento GraphQL endpoint (Network → block URL pattern)
  - Perform a search
  - Expect: results still render with Typesense base prices (no blank prices, no error state)

- [ ] **Step 6: Test error state**
  - In browser devtools, block `ge9f4n7drms0qoyvp-1.a2.typesense.net`
  - Perform a search
  - Expect: UI shows an "Search unavailable" message (or equivalent error state from the store)

- [ ] **Step 7: Final commit**

```bash
git add .
git commit -m "feat: Typesense JS SDK search + autocomplete — smoke tested"
```

---

## Self-Review Notes

- All tasks produce committed, testable code independently
- Transformer tasks (3, 5) have no Angular dependencies — pure function tests run instantly
- Reducer tests (6) do not require Angular TestBed — can run in Node
- Driver tests (4, 8) mock `TypesenseClientService` to avoid hitting the live API
- `PriceEnricherService` (7) mocks Apollo — tests do not require a running Magento instance
- Effects tests (8) use `provideMockActions` per NgRx convention
- Task 9 (wiring) intentionally leaves step 3+4 partially open because the exact injection tokens depend on the project's Daffodil driver conventions — the developer MUST read the existing ElasticSuite driver first
- The `filterBy` format (`field:=[value]`) follows Typesense filter syntax for exact string match; for range filters on `final_price` the format is `final_price:[10..100]` — extend `TypesenseSearchDriver.search()` if range filtering is needed
