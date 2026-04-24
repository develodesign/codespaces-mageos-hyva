# Typesense Frontend Implementation Plan (Daffodil / Angular)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build two Daffodil drivers — `TypesenseSuggestDriver` and `TypesenseSearchDriver` — that query the Magento GraphQL `typeseenseSuggest` and `typesenseSearch` queries and integrate them into the Angular storefront's search bar and search results page.

**Architecture:** Both drivers call Magento GraphQL exclusively (no Typesense JS SDK on the frontend). Each driver is an injectable Angular service implementing the relevant Daffodil driver interface. Transformers are pure functions that map GraphQL responses to Daffodil data shapes. NgRx actions are dispatched from the drivers following the existing dai-builder store pattern.

**Tech Stack:** Angular 17+, TypeScript, NgRx, Apollo GraphQL, Daffodil (`@daffodil/search`, `@daffodil/product`), Jest

> **Note on file paths:** Paths below follow the standard `dai-builder` monorepo layout. Verify the exact paths in your checked-out `dai-builder` repo before creating files — the `libs/` vs `projects/` split and exact driver locations vary by project.

---

## Prerequisites

- The `feature/typesense` branch of the Mage-OS backend must be deployed with the backend plan's Task 5 completed (schema published) so that the GraphQL queries are introspectable.
- Confirm the GraphQL endpoint URL in the Angular environment config.

---

## File Map

```
libs/typesense/
  src/
    graphql/
      typesense-suggest.query.ts       # gql fragment for typeseenseSuggest
      typesense-search.query.ts        # gql fragment for typesenseSearch
    transformers/
      suggest.transformer.ts           # maps GraphQL suggest response → Daffodil shape
      suggest.transformer.spec.ts
      search.transformer.ts            # maps GraphQL search response → Daffodil shape
      search.transformer.spec.ts
    drivers/
      typesense-suggest.driver.ts      # injectable service, implements Daffodil suggest driver interface
      typesense-suggest.driver.spec.ts
      typesense-search.driver.ts       # injectable service, implements Daffodil search driver interface
      typesense-search.driver.spec.ts
    store/
      typesense-search.actions.ts      # NgRx action creators
      typesense-search.effects.ts      # NgRx effects (dispatch on query change)
      typesense-search.effects.spec.ts
      typesense-search.reducer.ts      # search result state slice
      typesense-search.selectors.ts
    typesense.module.ts                # Angular module wiring providers
  index.ts                             # public API exports
```

**Wiring into existing components (modify, don't create):**
- `apps/store/src/app/search/search-bar.component.ts` (or equivalent) — swap suggest driver
- `apps/store/src/app/search/search-results.component.ts` (or equivalent) — swap search driver

---

## Task 1: GraphQL query fragments

**Files:**
- Create: `libs/typesense/src/graphql/typesense-suggest.query.ts`
- Create: `libs/typesense/src/graphql/typesense-search.query.ts`

- [ ] **Step 1: Create the suggest query fragment**

`libs/typesense/src/graphql/typesense-suggest.query.ts`:
```typescript
import { gql } from 'apollo-angular';

export const TYPESENSE_SUGGEST_QUERY = gql`
  query TypeseenseSuggest($query: String!) {
    typeseenseSuggest(query: $query) {
      products {
        name
        url
        sku
        image_url
        price
      }
      categories {
        name
        url
        breadcrumb
      }
      terms {
        query_text
        num_results
      }
    }
  }
`;
```

- [ ] **Step 2: Create the search query fragment**

`libs/typesense/src/graphql/typesense-search.query.ts`:
```typescript
import { gql } from 'apollo-angular';

export const TYPESENSE_SEARCH_QUERY = gql`
  query TypesenseSearch(
    $query: String!
    $page: Int
    $pageSize: Int
    $filters: [TypesenseFilterInput]
    $sort: TypesenseSortInput
  ) {
    typesenseSearch(
      query: $query
      page: $page
      pageSize: $pageSize
      filters: $filters
      sort: $sort
    ) {
      items {
        id
        name
        sku
        url
        image_url
        price
        categories
      }
      facets {
        name
        label
        options {
          value
          label
          count
        }
      }
      total_count
      search_time_ms
      page_info {
        current_page
        page_size
        total_pages
      }
    }
  }
`;
```

- [ ] **Step 3: Commit**

```bash
git add libs/typesense/src/graphql/
git commit -m "feat: add Typesense GraphQL query fragments"
```

---

## Task 2: Suggest transformer + tests

**Files:**
- Create: `libs/typesense/src/transformers/suggest.transformer.ts`
- Create: `libs/typesense/src/transformers/suggest.transformer.spec.ts`

- [ ] **Step 1: Write the failing test**

`libs/typesense/src/transformers/suggest.transformer.spec.ts`:
```typescript
import { transformSuggestResponse } from './suggest.transformer';

describe('transformSuggestResponse', () => {
  const raw = {
    typeseenseSuggest: {
      products: [
        { name: 'Blue Shirt', url: '/blue-shirt', sku: 'BS-001', image_url: '/img.jpg', price: 29.99 },
      ],
      categories: [
        { name: 'Men', url: '/men', breadcrumb: ['Root', 'Men'] },
      ],
      terms: [{ query_text: 'shirts', num_results: 42 }],
    },
  };

  it('maps products to DaffSearchResult shape', () => {
    const result = transformSuggestResponse(raw);
    expect(result.products).toHaveLength(1);
    expect(result.products[0]).toEqual({
      name: 'Blue Shirt',
      url: '/blue-shirt',
      sku: 'BS-001',
      imageUrl: '/img.jpg',
      price: 29.99,
    });
  });

  it('maps categories to DaffSearchResult shape', () => {
    const result = transformSuggestResponse(raw);
    expect(result.categories).toHaveLength(1);
    expect(result.categories[0]).toEqual({
      name: 'Men',
      url: '/men',
      breadcrumb: ['Root', 'Men'],
    });
  });

  it('maps terms to DaffSearchResult shape', () => {
    const result = transformSuggestResponse(raw);
    expect(result.terms).toHaveLength(1);
    expect(result.terms[0]).toEqual({ queryText: 'shirts', numResults: 42 });
  });

  it('handles null/missing products gracefully', () => {
    const result = transformSuggestResponse({ typeseenseSuggest: { products: null, categories: [], terms: [] } });
    expect(result.products).toEqual([]);
  });
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
npx jest libs/typesense/src/transformers/suggest.transformer.spec.ts
```

Expected: `Cannot find module './suggest.transformer'`

- [ ] **Step 3: Implement the transformer**

`libs/typesense/src/transformers/suggest.transformer.ts`:
```typescript
export interface SuggestProduct {
  name: string;
  url: string;
  sku: string;
  imageUrl: string | null;
  price: number | null;
}

export interface SuggestCategory {
  name: string;
  url: string;
  breadcrumb: string[];
}

export interface SuggestTerm {
  queryText: string;
  numResults: number | null;
}

export interface SuggestResult {
  products: SuggestProduct[];
  categories: SuggestCategory[];
  terms: SuggestTerm[];
}

export function transformSuggestResponse(raw: any): SuggestResult {
  const data = raw?.typeseenseSuggest ?? {};

  return {
    products: (data.products ?? []).map((p: any): SuggestProduct => ({
      name:     p.name,
      url:      p.url,
      sku:      p.sku,
      imageUrl: p.image_url ?? null,
      price:    p.price ?? null,
    })),
    categories: (data.categories ?? []).map((c: any): SuggestCategory => ({
      name:       c.name,
      url:        c.url,
      breadcrumb: c.breadcrumb ?? [],
    })),
    terms: (data.terms ?? []).map((t: any): SuggestTerm => ({
      queryText:  t.query_text,
      numResults: t.num_results ?? null,
    })),
  };
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
npx jest libs/typesense/src/transformers/suggest.transformer.spec.ts
```

Expected: `Tests: 4 passed`

- [ ] **Step 5: Commit**

```bash
git add libs/typesense/src/transformers/suggest.transformer.ts \
        libs/typesense/src/transformers/suggest.transformer.spec.ts
git commit -m "feat: add Typesense suggest transformer"
```

---

## Task 3: Search transformer + tests

**Files:**
- Create: `libs/typesense/src/transformers/search.transformer.ts`
- Create: `libs/typesense/src/transformers/search.transformer.spec.ts`

- [ ] **Step 1: Write the failing test**

`libs/typesense/src/transformers/search.transformer.spec.ts`:
```typescript
import { transformSearchResponse } from './search.transformer';

const raw = {
  typesenseSearch: {
    items: [
      { id: 1, name: 'Red Hat', sku: 'RH-001', url: '/red-hat', image_url: '/img.jpg', price: 19.99, categories: ['Hats'] },
    ],
    facets: [
      { name: 'categories', label: 'Categories', options: [{ value: 'Hats', label: 'Hats', count: 4 }] },
    ],
    total_count: 1,
    search_time_ms: 5,
    page_info: { current_page: 1, page_size: 20, total_pages: 1 },
  },
};

describe('transformSearchResponse', () => {
  it('maps items to product shape', () => {
    const result = transformSearchResponse(raw);
    expect(result.items).toHaveLength(1);
    expect(result.items[0]).toEqual({
      id: 1,
      name: 'Red Hat',
      sku: 'RH-001',
      url: '/red-hat',
      imageUrl: '/img.jpg',
      price: 19.99,
      categories: ['Hats'],
    });
  });

  it('maps facets correctly', () => {
    const result = transformSearchResponse(raw);
    expect(result.facets).toHaveLength(1);
    expect(result.facets[0].name).toBe('categories');
    expect(result.facets[0].options[0]).toEqual({ value: 'Hats', label: 'Hats', count: 4 });
  });

  it('maps pagination info', () => {
    const result = transformSearchResponse(raw);
    expect(result.totalCount).toBe(1);
    expect(result.pageInfo).toEqual({ currentPage: 1, pageSize: 20, totalPages: 1 });
  });

  it('handles empty results', () => {
    const result = transformSearchResponse({ typesenseSearch: { items: [], facets: [], total_count: 0, search_time_ms: 1, page_info: { current_page: 1, page_size: 20, total_pages: 0 } } });
    expect(result.items).toEqual([]);
    expect(result.totalCount).toBe(0);
  });
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
npx jest libs/typesense/src/transformers/search.transformer.spec.ts
```

Expected: `Cannot find module './search.transformer'`

- [ ] **Step 3: Implement the transformer**

`libs/typesense/src/transformers/search.transformer.ts`:
```typescript
export interface SearchProduct {
  id: number;
  name: string;
  sku: string;
  url: string;
  imageUrl: string | null;
  price: number | null;
  categories: string[];
}

export interface SearchFacetOption {
  value: string;
  label: string;
  count: number;
}

export interface SearchFacet {
  name: string;
  label: string;
  options: SearchFacetOption[];
}

export interface SearchPageInfo {
  currentPage: number;
  pageSize: number;
  totalPages: number;
}

export interface SearchResult {
  items: SearchProduct[];
  facets: SearchFacet[];
  totalCount: number;
  searchTimeMs: number;
  pageInfo: SearchPageInfo;
}

export function transformSearchResponse(raw: any): SearchResult {
  const data = raw?.typesenseSearch ?? {};

  return {
    items: (data.items ?? []).map((item: any): SearchProduct => ({
      id:         item.id,
      name:       item.name,
      sku:        item.sku,
      url:        item.url,
      imageUrl:   item.image_url ?? null,
      price:      item.price ?? null,
      categories: item.categories ?? [],
    })),
    facets: (data.facets ?? []).map((facet: any): SearchFacet => ({
      name:    facet.name,
      label:   facet.label,
      options: (facet.options ?? []).map((o: any): SearchFacetOption => ({
        value: o.value,
        label: o.label,
        count: o.count,
      })),
    })),
    totalCount:    data.total_count ?? 0,
    searchTimeMs:  data.search_time_ms ?? 0,
    pageInfo: {
      currentPage: data.page_info?.current_page ?? 1,
      pageSize:    data.page_info?.page_size ?? 20,
      totalPages:  data.page_info?.total_pages ?? 0,
    },
  };
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
npx jest libs/typesense/src/transformers/search.transformer.spec.ts
```

Expected: `Tests: 4 passed`

- [ ] **Step 5: Commit**

```bash
git add libs/typesense/src/transformers/search.transformer.ts \
        libs/typesense/src/transformers/search.transformer.spec.ts
git commit -m "feat: add Typesense search transformer"
```

---

## Task 4: TypesenseSuggestDriver + tests

**Files:**
- Create: `libs/typesense/src/drivers/typesense-suggest.driver.ts`
- Create: `libs/typesense/src/drivers/typesense-suggest.driver.spec.ts`

> Check the existing ElasticSuite suggest driver (if present in dai-builder) to confirm the exact Daffodil suggest driver interface name and import path. The pattern below assumes `@daffodil/search`'s `DaffSearchDriverInterface`.

- [ ] **Step 1: Write the failing test**

`libs/typesense/src/drivers/typesense-suggest.driver.spec.ts`:
```typescript
import { TestBed } from '@angular/core/testing';
import { Apollo } from 'apollo-angular';
import { of, throwError } from 'rxjs';
import { TypesenseSuggestDriver } from './typesense-suggest.driver';

describe('TypesenseSuggestDriver', () => {
  let driver: TypesenseSuggestDriver;
  let apollo: jest.Mocked<Apollo>;

  beforeEach(() => {
    apollo = { query: jest.fn() } as any;

    TestBed.configureTestingModule({
      providers: [
        TypesenseSuggestDriver,
        { provide: Apollo, useValue: apollo },
      ],
    });
    driver = TestBed.inject(TypesenseSuggestDriver);
  });

  it('queries GraphQL and returns transformed results', (done) => {
    apollo.query.mockReturnValue(of({
      data: {
        typeseenseSuggest: {
          products: [{ name: 'Shirt', url: '/shirt', sku: 'S-1', image_url: null, price: 20 }],
          categories: [],
          terms: [],
        },
      },
    }) as any);

    driver.search('shirt').subscribe((result) => {
      expect(result.products).toHaveLength(1);
      expect(result.products[0].name).toBe('Shirt');
      done();
    });
  });

  it('returns empty result on GraphQL error', (done) => {
    apollo.query.mockReturnValue(throwError(() => new Error('network error')));

    driver.search('shirt').subscribe({
      next: (result) => {
        expect(result.products).toEqual([]);
        expect(result.categories).toEqual([]);
        done();
      },
    });
  });

  it('debounces rapid calls and skips queries shorter than 2 chars', (done) => {
    driver.search('a').subscribe((result) => {
      expect(apollo.query).not.toHaveBeenCalled();
      expect(result.products).toEqual([]);
      done();
    });
  });
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
npx jest libs/typesense/src/drivers/typesense-suggest.driver.spec.ts
```

Expected: `Cannot find module './typesense-suggest.driver'`

- [ ] **Step 3: Implement the driver**

`libs/typesense/src/drivers/typesense-suggest.driver.ts`:
```typescript
import { Injectable } from '@angular/core';
import { Apollo } from 'apollo-angular';
import { Observable, of } from 'rxjs';
import { catchError, map } from 'rxjs/operators';
import { TYPESENSE_SUGGEST_QUERY } from '../graphql/typesense-suggest.query';
import { transformSuggestResponse, SuggestResult } from '../transformers/suggest.transformer';

const MIN_QUERY_LENGTH = 2;
const EMPTY_RESULT: SuggestResult = { products: [], categories: [], terms: [] };

@Injectable({ providedIn: 'root' })
export class TypesenseSuggestDriver {
  constructor(private apollo: Apollo) {}

  search(query: string): Observable<SuggestResult> {
    if (query.trim().length < MIN_QUERY_LENGTH) {
      return of(EMPTY_RESULT);
    }

    return this.apollo
      .query<any>({
        query: TYPESENSE_SUGGEST_QUERY,
        variables: { query: query.trim() },
        fetchPolicy: 'no-cache',
      })
      .pipe(
        map((response) => transformSuggestResponse(response.data)),
        catchError(() => of(EMPTY_RESULT))
      );
  }
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
npx jest libs/typesense/src/drivers/typesense-suggest.driver.spec.ts
```

Expected: `Tests: 3 passed`

- [ ] **Step 5: Commit**

```bash
git add libs/typesense/src/drivers/typesense-suggest.driver.ts \
        libs/typesense/src/drivers/typesense-suggest.driver.spec.ts
git commit -m "feat: add TypesenseSuggestDriver"
```

---

## Task 5: TypesenseSearchDriver + NgRx store + tests

**Files:**
- Create: `libs/typesense/src/store/typesense-search.actions.ts`
- Create: `libs/typesense/src/store/typesense-search.reducer.ts`
- Create: `libs/typesense/src/store/typesense-search.selectors.ts`
- Create: `libs/typesense/src/store/typesense-search.effects.ts`
- Create: `libs/typesense/src/store/typesense-search.effects.spec.ts`
- Create: `libs/typesense/src/drivers/typesense-search.driver.ts`
- Create: `libs/typesense/src/drivers/typesense-search.driver.spec.ts`

- [ ] **Step 1: Create NgRx actions**

`libs/typesense/src/store/typesense-search.actions.ts`:
```typescript
import { createAction, props } from '@ngrx/store';
import { SearchResult } from '../transformers/search.transformer';

export const typesenseSearchLoad = createAction(
  '[Typesense] Search Load',
  props<{ query: string; page?: number; pageSize?: number; filters?: any[]; sort?: any }>()
);

export const typesenseSearchSuccess = createAction(
  '[Typesense] Search Success',
  props<{ result: SearchResult }>()
);

export const typesenseSearchFailure = createAction(
  '[Typesense] Search Failure',
  props<{ error: string }>()
);
```

- [ ] **Step 2: Create reducer + selectors**

`libs/typesense/src/store/typesense-search.reducer.ts`:
```typescript
import { createReducer, on } from '@ngrx/store';
import { SearchResult } from '../transformers/search.transformer';
import { typesenseSearchLoad, typesenseSearchSuccess, typesenseSearchFailure } from './typesense-search.actions';

export interface TypesenseSearchState {
  result: SearchResult | null;
  loading: boolean;
  error: string | null;
}

export const initialState: TypesenseSearchState = {
  result: null,
  loading: false,
  error: null,
};

export const typesenseSearchReducer = createReducer(
  initialState,
  on(typesenseSearchLoad, (state) => ({ ...state, loading: true, error: null })),
  on(typesenseSearchSuccess, (state, { result }) => ({ ...state, loading: false, result })),
  on(typesenseSearchFailure, (state, { error }) => ({ ...state, loading: false, error }))
);
```

`libs/typesense/src/store/typesense-search.selectors.ts`:
```typescript
import { createFeatureSelector, createSelector } from '@ngrx/store';
import { TypesenseSearchState } from './typesense-search.reducer';

export const selectTypesenseSearch = createFeatureSelector<TypesenseSearchState>('typesenseSearch');
export const selectSearchResult = createSelector(selectTypesenseSearch, (s) => s.result);
export const selectSearchLoading = createSelector(selectTypesenseSearch, (s) => s.loading);
export const selectSearchError = createSelector(selectTypesenseSearch, (s) => s.error);
```

- [ ] **Step 3: Write the failing driver test**

`libs/typesense/src/drivers/typesense-search.driver.spec.ts`:
```typescript
import { TestBed } from '@angular/core/testing';
import { Apollo } from 'apollo-angular';
import { of, throwError } from 'rxjs';
import { TypesenseSearchDriver } from './typesense-search.driver';

describe('TypesenseSearchDriver', () => {
  let driver: TypesenseSearchDriver;
  let apollo: jest.Mocked<Apollo>;

  beforeEach(() => {
    apollo = { query: jest.fn() } as any;
    TestBed.configureTestingModule({
      providers: [
        TypesenseSearchDriver,
        { provide: Apollo, useValue: apollo },
      ],
    });
    driver = TestBed.inject(TypesenseSearchDriver);
  });

  it('queries GraphQL and returns transformed search result', (done) => {
    apollo.query.mockReturnValue(of({
      data: {
        typesenseSearch: {
          items: [{ id: 1, name: 'Hat', sku: 'H-1', url: '/hat', image_url: null, price: 15, categories: [] }],
          facets: [],
          total_count: 1,
          search_time_ms: 3,
          page_info: { current_page: 1, page_size: 20, total_pages: 1 },
        },
      },
    }) as any);

    driver.search('hat', 1, 20, [], null).subscribe((result) => {
      expect(result.items).toHaveLength(1);
      expect(result.totalCount).toBe(1);
      done();
    });
  });

  it('returns empty result on error', (done) => {
    apollo.query.mockReturnValue(throwError(() => new Error('timeout')));

    driver.search('hat', 1, 20, [], null).subscribe((result) => {
      expect(result.items).toEqual([]);
      done();
    });
  });
});
```

- [ ] **Step 4: Run test to confirm it fails**

```bash
npx jest libs/typesense/src/drivers/typesense-search.driver.spec.ts
```

Expected: `Cannot find module './typesense-search.driver'`

- [ ] **Step 5: Implement the search driver**

`libs/typesense/src/drivers/typesense-search.driver.ts`:
```typescript
import { Injectable } from '@angular/core';
import { Apollo } from 'apollo-angular';
import { Observable, of } from 'rxjs';
import { catchError, map } from 'rxjs/operators';
import { TYPESENSE_SEARCH_QUERY } from '../graphql/typesense-search.query';
import { transformSearchResponse, SearchResult } from '../transformers/search.transformer';

const EMPTY_RESULT: SearchResult = {
  items: [],
  facets: [],
  totalCount: 0,
  searchTimeMs: 0,
  pageInfo: { currentPage: 1, pageSize: 20, totalPages: 0 },
};

@Injectable({ providedIn: 'root' })
export class TypesenseSearchDriver {
  constructor(private apollo: Apollo) {}

  search(
    query: string,
    page: number,
    pageSize: number,
    filters: Array<{ field: string; value: string; condition_type?: string }>,
    sort: { field: string; direction: string } | null
  ): Observable<SearchResult> {
    return this.apollo
      .query<any>({
        query: TYPESENSE_SEARCH_QUERY,
        variables: { query, page, pageSize, filters: filters.length ? filters : undefined, sort: sort ?? undefined },
        fetchPolicy: 'no-cache',
      })
      .pipe(
        map((response) => transformSearchResponse(response.data)),
        catchError(() => of(EMPTY_RESULT))
      );
  }
}
```

- [ ] **Step 6: Write the failing effects test**

`libs/typesense/src/store/typesense-search.effects.spec.ts`:
```typescript
import { TestBed } from '@angular/core/testing';
import { provideMockActions } from '@ngrx/effects/testing';
import { Observable, of } from 'rxjs';
import { TypesenseSearchEffects } from './typesense-search.effects';
import { TypesenseSearchDriver } from '../drivers/typesense-search.driver';
import { typesenseSearchLoad, typesenseSearchSuccess, typesenseSearchFailure } from './typesense-search.actions';

describe('TypesenseSearchEffects', () => {
  let actions$: Observable<any>;
  let effects: TypesenseSearchEffects;
  let driver: jest.Mocked<TypesenseSearchDriver>;

  const mockResult = { items: [], facets: [], totalCount: 0, searchTimeMs: 1, pageInfo: { currentPage: 1, pageSize: 20, totalPages: 0 } };

  beforeEach(() => {
    driver = { search: jest.fn().mockReturnValue(of(mockResult)) } as any;

    TestBed.configureTestingModule({
      providers: [
        TypesenseSearchEffects,
        provideMockActions(() => actions$),
        { provide: TypesenseSearchDriver, useValue: driver },
      ],
    });
    effects = TestBed.inject(TypesenseSearchEffects);
  });

  it('dispatches success on successful search', (done) => {
    actions$ = of(typesenseSearchLoad({ query: 'hat' }));
    effects.search$.subscribe((action) => {
      expect(action).toEqual(typesenseSearchSuccess({ result: mockResult }));
      done();
    });
  });
});
```

- [ ] **Step 7: Implement effects**

`libs/typesense/src/store/typesense-search.effects.ts`:
```typescript
import { Injectable } from '@angular/core';
import { Actions, createEffect, ofType } from '@ngrx/effects';
import { of } from 'rxjs';
import { catchError, map, switchMap } from 'rxjs/operators';
import { TypesenseSearchDriver } from '../drivers/typesense-search.driver';
import { typesenseSearchLoad, typesenseSearchSuccess, typesenseSearchFailure } from './typesense-search.actions';

@Injectable()
export class TypesenseSearchEffects {
  search$ = createEffect(() =>
    this.actions$.pipe(
      ofType(typesenseSearchLoad),
      switchMap(({ query, page = 1, pageSize = 20, filters = [], sort = null }) =>
        this.driver.search(query, page, pageSize, filters, sort).pipe(
          map((result) => typesenseSearchSuccess({ result })),
          catchError((error) => of(typesenseSearchFailure({ error: error?.message ?? 'Unknown error' })))
        )
      )
    )
  );

  constructor(
    private actions$: Actions,
    private driver: TypesenseSearchDriver
  ) {}
}
```

- [ ] **Step 8: Run all driver and effects tests**

```bash
npx jest libs/typesense/src/drivers/ libs/typesense/src/store/
```

Expected: `Tests: 5 passed`

- [ ] **Step 9: Commit**

```bash
git add libs/typesense/src/store/ \
        libs/typesense/src/drivers/typesense-search.driver.ts \
        libs/typesense/src/drivers/typesense-search.driver.spec.ts
git commit -m "feat: add TypesenseSearchDriver and NgRx search store"
```

---

## Task 6: Angular module + public API

**Files:**
- Create: `libs/typesense/src/typesense.module.ts`
- Create: `libs/typesense/index.ts`

- [ ] **Step 1: Create the Angular module**

`libs/typesense/src/typesense.module.ts`:
```typescript
import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { StoreModule } from '@ngrx/store';
import { EffectsModule } from '@ngrx/effects';
import { typesenseSearchReducer } from './store/typesense-search.reducer';
import { TypesenseSearchEffects } from './store/typesense-search.effects';

@NgModule({
  imports: [
    CommonModule,
    StoreModule.forFeature('typesenseSearch', typesenseSearchReducer),
    EffectsModule.forFeature([TypesenseSearchEffects]),
  ],
})
export class TypesenseModule {}
```

- [ ] **Step 2: Create public API exports**

`libs/typesense/index.ts`:
```typescript
export { TypesenseModule } from './src/typesense.module';
export { TypesenseSuggestDriver } from './src/drivers/typesense-suggest.driver';
export { TypesenseSearchDriver } from './src/drivers/typesense-search.driver';
export { typesenseSearchLoad } from './src/store/typesense-search.actions';
export { selectSearchResult, selectSearchLoading, selectSearchError } from './src/store/typesense-search.selectors';
export type { SuggestResult } from './src/transformers/suggest.transformer';
export type { SearchResult, SearchProduct, SearchFacet } from './src/transformers/search.transformer';
```

- [ ] **Step 3: Commit**

```bash
git add libs/typesense/src/typesense.module.ts libs/typesense/index.ts
git commit -m "feat: add TypesenseModule and public API exports"
```

---

## Task 7: Wire drivers into the storefront

> **Before this task:** Identify the exact component files in your `apps/store/` that render (a) the search bar autocomplete and (b) the search results page. The names below are indicative — check your dai-builder app structure.

- [ ] **Step 1: Import TypesenseModule in AppModule (or the search feature module)**

In `apps/store/src/app/app.module.ts` (or the relevant feature module):
```typescript
import { TypesenseModule } from '@your-scope/typesense'; // adjust import path

@NgModule({
  imports: [
    // ... existing imports
    TypesenseModule,
  ],
})
export class AppModule {}
```

- [ ] **Step 2: Wire suggest driver into the search bar component**

In the search bar component (e.g., `apps/store/src/app/search/search-bar.component.ts`):
```typescript
import { TypesenseSuggestDriver } from '@your-scope/typesense';
import { Subject } from 'rxjs';
import { debounceTime, distinctUntilChanged, switchMap } from 'rxjs/operators';

@Component({ ... })
export class SearchBarComponent implements OnInit {
  suggestions$ = this.query$.pipe(
    debounceTime(200),
    distinctUntilChanged(),
    switchMap((query) => this.suggestDriver.search(query))
  );

  private query$ = new Subject<string>();

  constructor(private suggestDriver: TypesenseSuggestDriver) {}

  onQueryChange(query: string): void {
    this.query$.next(query);
  }
}
```

In the template, bind `suggestions$ | async` to render products, categories, and terms sections.

- [ ] **Step 3: Wire search driver into the search results component**

In the search results component (e.g., `apps/store/src/app/search/search-results.component.ts`):
```typescript
import { Store } from '@ngrx/store';
import { typesenseSearchLoad, selectSearchResult, selectSearchLoading } from '@your-scope/typesense';
import { ActivatedRoute } from '@angular/router';

@Component({ ... })
export class SearchResultsComponent implements OnInit {
  result$ = this.store.select(selectSearchResult);
  loading$ = this.store.select(selectSearchLoading);

  constructor(private store: Store, private route: ActivatedRoute) {}

  ngOnInit(): void {
    this.route.queryParamMap.subscribe((params) => {
      const query = params.get('q') ?? '';
      if (query) {
        this.store.dispatch(typesenseSearchLoad({ query }));
      }
    });
  }

  onFacetSelected(field: string, value: string): void {
    const query = this.route.snapshot.queryParamMap.get('q') ?? '';
    this.store.dispatch(typesenseSearchLoad({
      query,
      filters: [{ field, value, condition_type: 'eq' }],
    }));
  }

  onPageChange(page: number): void {
    const query = this.route.snapshot.queryParamMap.get('q') ?? '';
    this.store.dispatch(typesenseSearchLoad({ query, page }));
  }
}
```

- [ ] **Step 4: Run the full test suite**

```bash
npx jest libs/typesense/
```

Expected: All tests pass (12+ tests, 0 failures)

- [ ] **Step 5: Build to confirm no TypeScript errors**

```bash
npx nx build store --configuration=development
```

Expected: Build succeeds with no errors.

- [ ] **Step 6: Commit**

```bash
git add apps/store/src/app/
git commit -m "feat: wire Typesense suggest and search drivers into storefront"
```

---

## Notes for integration (after Magento backend is live with real keys)

1. Confirm the Magento GraphQL endpoint is accessible from the Angular dev server
2. Run `typeseenseSuggest(query: "shirt")` in GraphQL playground to verify data shape matches transformer expectations
3. If Typesense collection field names differ from plan assumptions (`image_url`, `breadcrumb`, etc.), update transformers accordingly — they are the only place field name mapping lives
4. Adjust `debounceTime` in the search bar component based on UX testing (200ms is a starting point)
