# Typesense Backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Install `run-as-root/magento2-typesense` and build `Develo/TypesenseGraphQl` — a custom GraphQL module exposing Typesense autocomplete and full-text search to the Daffodil storefront.

**Architecture:** The run-as-root Composer package handles all indexing (products, categories, CMS pages → Typesense collections). A new `Develo/TypesenseGraphQl` module wraps the Typesense PHP client and exposes two GraphQL queries: `typeseenseSuggest` (autocomplete) and `typesenseSearch` (full search with facets). Credentials are read from `app/etc/env.php`, never from the database.

**Tech Stack:** PHP 8.3, Magento Framework GraphQL, `typesense/typesense-php` PHP client, PHPUnit 10, Mage-OS 2.2.1

---

## File Map

**Install / configure (no custom files):**
- `composer.json` — add `run-as-root/magento2-typesense`
- `app/etc/env.php` — add `typesense` config block

**New module — `app/code/Develo/TypesenseGraphQl/`:**
- `registration.php` — component registration
- `composer.json` — module metadata + requires
- `etc/module.xml` — module declaration + sequence
- `etc/di.xml` — bind `TypesenseClientInterface` → `TypesenseClient`
- `etc/schema.graphqls` — GraphQL types and queries
- `Api/TypesenseClientInterface.php` — search contract
- `Model/Config.php` — reads `env.php` typesense block
- `Model/Client/TypesenseClient.php` — wraps `typesense/typesense-php` SDK
- `Model/Resolver/Suggest.php` — autocomplete resolver
- `Model/Resolver/Search.php` — full search + facets resolver
- `Test/Unit/Model/ConfigTest.php`
- `Test/Unit/Model/Client/TypesenseClientTest.php`
- `Test/Unit/Model/Resolver/SuggestTest.php`
- `Test/Unit/Model/Resolver/SearchTest.php`

---

## Task 1: Install run-as-root Composer package

**Files:** `composer.json` (project root), `app/etc/env.php`

- [ ] **Step 1: Install the package**

```bash
php -d memory_limit=-1 $(which composer) require run-as-root/magento2-typesense
```

Expected output: `Package operations: N installs` with no errors. The `typesense/typesense-php` client is pulled in as a transitive dependency.

- [ ] **Step 2: Verify the PHP client is available**

```bash
php -r "require 'vendor/autoload.php'; new Typesense\Client(['api_key'=>'x','nodes'=>[['host'=>'x','port'=>'443','protocol'=>'https']],'connection_timeout_seconds'=>1]); echo 'OK';"
```

Expected: `OK`

- [ ] **Step 3: Add placeholder Typesense config to env.php**

Open `app/etc/env.php` and add the following block at the top level of the returned array (alongside `'db'`, `'cache'`, etc.):

```php
'typesense' => [
    'host'              => 'TYPESENSE_HOST_PLACEHOLDER',
    'port'              => '443',
    'protocol'          => 'https',
    'api_key'           => 'TYPESENSE_ADMIN_KEY_PLACEHOLDER',
    'search_key'        => 'TYPESENSE_SEARCH_ONLY_KEY_PLACEHOLDER',
    'collection_prefix' => 'magento2',
],
```

- [ ] **Step 4: Enable the run-as-root module**

```bash
bin/magento module:enable RunAsRoot_Typesense
php -d memory_limit=-1 bin/magento setup:upgrade
```

Expected: `Nothing to upgrade` or schema changes applied without error.

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock app/etc/env.php app/etc/config.php
git commit -m "feat: install run-as-root/magento2-typesense package"
```

---

## Task 2: Scaffold Develo/TypesenseGraphQl module

**Files:** `registration.php`, `composer.json`, `etc/module.xml`

- [ ] **Step 1: Create the module directory**

```bash
mkdir -p app/code/Develo/TypesenseGraphQl/etc
mkdir -p app/code/Develo/TypesenseGraphQl/Api
mkdir -p app/code/Develo/TypesenseGraphQl/Model/Client
mkdir -p app/code/Develo/TypesenseGraphQl/Model/Resolver
mkdir -p app/code/Develo/TypesenseGraphQl/Test/Unit/Model/Client
mkdir -p app/code/Develo/TypesenseGraphQl/Test/Unit/Model/Resolver
```

- [ ] **Step 2: Create `registration.php`**

`app/code/Develo/TypesenseGraphQl/registration.php`:
```php
<?php

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Develo_TypesenseGraphQl',
    __DIR__
);
```

- [ ] **Step 3: Create `composer.json`**

`app/code/Develo/TypesenseGraphQl/composer.json`:
```json
{
    "name": "develo/module-typesense-graphql",
    "description": "Exposes Typesense autocomplete and full-text search via GraphQL",
    "type": "magento2-module",
    "version": "1.0.0",
    "require": {
        "php": "~8.3.0",
        "typesense/typesense-php": "^4.0",
        "run-as-root/magento2-typesense": "*"
    },
    "autoload": {
        "files": ["registration.php"],
        "psr-4": {
            "Develo\\TypesenseGraphQl\\": ""
        }
    }
}
```

- [ ] **Step 4: Create `etc/module.xml`**

`app/code/Develo/TypesenseGraphQl/etc/module.xml`:
```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Module/etc/module.xsd">
    <module name="Develo_TypesenseGraphQl">
        <sequence>
            <module name="Magento_GraphQl"/>
            <module name="RunAsRoot_Typesense"/>
        </sequence>
    </module>
</config>
```

- [ ] **Step 5: Enable module and verify it loads**

```bash
bin/magento module:enable Develo_TypesenseGraphQl
php -d memory_limit=-1 bin/magento setup:upgrade
```

Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add app/code/Develo/TypesenseGraphQl/
git commit -m "feat: scaffold Develo_TypesenseGraphQl module"
```

---

## Task 3: Config model + tests

**Files:**
- Create: `app/code/Develo/TypesenseGraphQl/Model/Config.php`
- Test: `app/code/Develo/TypesenseGraphQl/Test/Unit/Model/ConfigTest.php`

- [ ] **Step 1: Write the failing test**

`app/code/Develo/TypesenseGraphQl/Test/Unit/Model/ConfigTest.php`:
```php
<?php

declare(strict_types=1);

namespace Develo\TypesenseGraphQl\Test\Unit\Model;

use Develo\TypesenseGraphQl\Model\Config;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private Config $config;
    private DeploymentConfig|MockObject $deploymentConfig;

    protected function setUp(): void
    {
        $this->deploymentConfig = $this->createMock(DeploymentConfig::class);
        $this->config = new Config($this->deploymentConfig);
    }

    public function testGetHostReturnsConfiguredValue(): void
    {
        $this->deploymentConfig->method('get')
            ->with('typesense/host', '')
            ->willReturn('xxx.a1.typesense.net');

        $this->assertSame('xxx.a1.typesense.net', $this->config->getHost());
    }

    public function testGetPortDefaultsTo443(): void
    {
        $this->deploymentConfig->method('get')
            ->with('typesense/port', '443')
            ->willReturn('443');

        $this->assertSame('443', $this->config->getPort());
    }

    public function testGetSearchKeyReturnsConfiguredValue(): void
    {
        $this->deploymentConfig->method('get')
            ->with('typesense/search_key', '')
            ->willReturn('abc123');

        $this->assertSame('abc123', $this->config->getSearchKey());
    }

    public function testGetCollectionPrefixReturnsConfiguredValue(): void
    {
        $this->deploymentConfig->method('get')
            ->with('typesense/collection_prefix', 'magento2')
            ->willReturn('mystore');

        $this->assertSame('mystore', $this->config->getCollectionPrefix());
    }

    public function testIsConfiguredReturnsTrueWhenHostAndKeyPresent(): void
    {
        $this->deploymentConfig->method('get')
            ->willReturnMap([
                ['typesense/host', '', 'xxx.a1.typesense.net'],
                ['typesense/search_key', '', 'abc123'],
            ]);

        $this->assertTrue($this->config->isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenHostMissing(): void
    {
        $this->deploymentConfig->method('get')
            ->willReturnMap([
                ['typesense/host', '', ''],
                ['typesense/search_key', '', 'abc123'],
            ]);

        $this->assertFalse($this->config->isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenKeyMissing(): void
    {
        $this->deploymentConfig->method('get')
            ->willReturnMap([
                ['typesense/host', '', 'xxx.a1.typesense.net'],
                ['typesense/search_key', '', ''],
            ]);

        $this->assertFalse($this->config->isConfigured());
    }
}
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
vendor/bin/phpunit app/code/Develo/TypesenseGraphQl/Test/Unit/Model/ConfigTest.php
```

Expected: `Error: Class "Develo\TypesenseGraphQl\Model\Config" not found`

- [ ] **Step 3: Implement `Config.php`**

`app/code/Develo/TypesenseGraphQl/Model/Config.php`:
```php
<?php

declare(strict_types=1);

namespace Develo\TypesenseGraphQl\Model;

use Magento\Framework\App\DeploymentConfig;

class Config
{
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {}

    public function getHost(): string
    {
        return (string) $this->deploymentConfig->get('typesense/host', '');
    }

    public function getPort(): string
    {
        return (string) $this->deploymentConfig->get('typesense/port', '443');
    }

    public function getProtocol(): string
    {
        return (string) $this->deploymentConfig->get('typesense/protocol', 'https');
    }

    public function getSearchKey(): string
    {
        return (string) $this->deploymentConfig->get('typesense/search_key', '');
    }

    public function getCollectionPrefix(): string
    {
        return (string) $this->deploymentConfig->get('typesense/collection_prefix', 'magento2');
    }

    public function isConfigured(): bool
    {
        return $this->getHost() !== '' && $this->getSearchKey() !== '';
    }
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
vendor/bin/phpunit app/code/Develo/TypesenseGraphQl/Test/Unit/Model/ConfigTest.php
```

Expected: `OK (7 tests, 7 assertions)`

- [ ] **Step 5: Commit**

```bash
git add app/code/Develo/TypesenseGraphQl/Model/Config.php \
        app/code/Develo/TypesenseGraphQl/Test/Unit/Model/ConfigTest.php
git commit -m "feat: add TypesenseGraphQl Config model"
```

---

## Task 4: TypesenseClientInterface + TypesenseClient + tests

**Files:**
- Create: `app/code/Develo/TypesenseGraphQl/Api/TypesenseClientInterface.php`
- Create: `app/code/Develo/TypesenseGraphQl/Model/Client/TypesenseClient.php`
- Create: `app/code/Develo/TypesenseGraphQl/etc/di.xml`
- Test: `app/code/Develo/TypesenseGraphQl/Test/Unit/Model/Client/TypesenseClientTest.php`

- [ ] **Step 1: Create the interface**

`app/code/Develo/TypesenseGraphQl/Api/TypesenseClientInterface.php`:
```php
<?php

declare(strict_types=1);

namespace Develo\TypesenseGraphQl\Api;

interface TypesenseClientInterface
{
    /**
     * Run a multi-collection autocomplete search.
     *
     * @return array{products: array, categories: array}
     */
    public function suggest(string $query): array;

    /**
     * Run a full-text product search with facets and pagination.
     *
     * @param array<array{field: string, value: string, condition_type?: string}> $filters
     * @param array{field: string, direction: string}|null $sort
     * @return array{items: array, facets: array, total_count: int, search_time_ms: int}
     */
    public function search(string $query, int $page, int $pageSize, array $filters, ?array $sort): array;
}
```

- [ ] **Step 2: Write the failing test**

`app/code/Develo/TypesenseGraphQl/Test/Unit/Model/Client/TypesenseClientTest.php`:
```php
<?php

declare(strict_types=1);

namespace Develo\TypesenseGraphQl\Test\Unit\Model\Client;

use Develo\TypesenseGraphQl\Model\Client\TypesenseClient;
use Develo\TypesenseGraphQl\Model\Config;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Typesense\Client;
use Typesense\MultiSearch;
use Typesense\Collections;
use Typesense\Collection;
use Typesense\Documents;

class TypesenseClientTest extends TestCase
{
    private TypesenseClient $tsClient;
    private Config|MockObject $config;
    private Client|MockObject $sdkClient;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->sdkClient = $this->createMock(Client::class);

        $this->tsClient = new TypesenseClient($this->config);
        // inject the mock SDK client via reflection
        $ref = new \ReflectionProperty(TypesenseClient::class, 'client');
        $ref->setAccessible(true);
        $ref->setValue($this->tsClient, $this->sdkClient);
    }

    public function testSuggestReturnsMappedProductsAndCategories(): void
    {
        $multiSearch = $this->createMock(MultiSearch::class);
        $this->sdkClient->multiSearch = $multiSearch;

        $this->config->method('getCollectionPrefix')->willReturn('magento2');

        $multiSearch->method('perform')->willReturn([
            'results' => [
                [
                    'hits' => [
                        ['document' => ['name' => 'Blue Shirt', 'sku' => 'BS-001', 'url' => '/blue-shirt', 'image_url' => '/img.jpg', 'price' => 29.99]],
                    ],
                ],
                [
                    'hits' => [
                        ['document' => ['name' => 'Men', 'url' => '/men', 'breadcrumb' => ['Men']]],
                    ],
                ],
            ],
        ]);

        $result = $this->tsClient->suggest('shirt');

        $this->assertCount(1, $result['products']);
        $this->assertSame('Blue Shirt', $result['products'][0]['name']);
        $this->assertSame('BS-001', $result['products'][0]['sku']);

        $this->assertCount(1, $result['categories']);
        $this->assertSame('Men', $result['categories'][0]['name']);
    }

    public function testSearchReturnsMappedItemsAndFacets(): void
    {
        $collections = $this->createMock(Collections::class);
        $collection = $this->createMock(Collection::class);
        $documents = $this->createMock(Documents::class);

        $this->sdkClient->collections = $collections;
        $collections->method('offsetGet')->willReturn($collection);
        $collection->documents = $documents;

        $this->config->method('getCollectionPrefix')->willReturn('magento2');

        $documents->method('search')->willReturn([
            'hits' => [
                ['document' => ['id' => '1', 'name' => 'Red Hat', 'sku' => 'RH-001', 'url' => '/red-hat', 'image_url' => '/img.jpg', 'price' => 19.99, 'categories' => ['Hats']]],
            ],
            'facet_counts' => [
                ['field_name' => 'categories', 'counts' => [['value' => 'Hats', 'count' => 4]]],
            ],
            'found' => 1,
            'search_time_ms' => 5,
            'page' => 1,
        ]);

        $result = $this->tsClient->search('hat', 1, 20, [], null);

        $this->assertCount(1, $result['items']);
        $this->assertSame('Red Hat', $result['items'][0]['name']);
        $this->assertSame(1, $result['total_count']);
        $this->assertSame(5, $result['search_time_ms']);
        $this->assertCount(1, $result['facets']);
        $this->assertSame('categories', $result['facets'][0]['name']);
    }
}
```

- [ ] **Step 3: Run test to confirm it fails**

```bash
vendor/bin/phpunit app/code/Develo/TypesenseGraphQl/Test/Unit/Model/Client/TypesenseClientTest.php
```

Expected: `Error: Class "Develo\TypesenseGraphQl\Model\Client\TypesenseClient" not found`

- [ ] **Step 4: Implement `TypesenseClient.php`**

`app/code/Develo/TypesenseGraphQl/Model/Client/TypesenseClient.php`:
```php
<?php

declare(strict_types=1);

namespace Develo\TypesenseGraphQl\Model\Client;

use Develo\TypesenseGraphQl\Api\TypesenseClientInterface;
use Develo\TypesenseGraphQl\Model\Config;
use Typesense\Client;

class TypesenseClient implements TypesenseClientInterface
{
    private ?Client $client = null;

    public function __construct(
        private readonly Config $config
    ) {}

    public function suggest(string $query): array
    {
        $prefix = $this->config->getCollectionPrefix();
        $results = $this->getClient()->multiSearch->perform([
            'searches' => [
                [
                    'collection' => $prefix . '_products',
                    'q'          => $query,
                    'query_by'   => 'name,sku,description',
                    'per_page'   => 5,
                    'prefix'     => true,
                ],
                [
                    'collection' => $prefix . '_categories',
                    'q'          => $query,
                    'query_by'   => 'name',
                    'per_page'   => 3,
                    'prefix'     => true,
                ],
            ],
        ], []);

        return [
            'products'   => $this->mapProductHits($results['results'][0]['hits'] ?? []),
            'categories' => $this->mapCategoryHits($results['results'][1]['hits'] ?? []),
            'terms'      => [], // Typesense Analytics needed for popular terms — Phase 2
        ];
    }

    public function search(string $query, int $page, int $pageSize, array $filters, ?array $sort): array
    {
        $prefix = $this->config->getCollectionPrefix();

        $params = [
            'q'        => $query,
            'query_by' => 'name,sku,description',
            'per_page' => $pageSize,
            'page'     => $page,
            'facet_by' => 'categories,price',
        ];

        if ($filters !== []) {
            $params['filter_by'] = $this->buildFilterString($filters);
        }

        if ($sort !== null) {
            $params['sort_by'] = $sort['field'] . ':' . strtolower($sort['direction']);
        }

        $raw = $this->getClient()->collections[$prefix . '_products']->documents->search($params);

        return [
            'items'          => $this->mapProductHits($raw['hits'] ?? []),
            'facets'         => $this->mapFacets($raw['facet_counts'] ?? []),
            'total_count'    => (int) ($raw['found'] ?? 0),
            'search_time_ms' => (int) ($raw['search_time_ms'] ?? 0),
            'page_info'      => [
                'current_page' => $page,
                'page_size'    => $pageSize,
                'total_pages'  => $pageSize > 0 ? (int) ceil(($raw['found'] ?? 0) / $pageSize) : 0,
            ],
        ];
    }

    private function getClient(): Client
    {
        if ($this->client === null) {
            $this->client = new Client([
                'api_key'                    => $this->config->getSearchKey(),
                'nodes'                      => [[
                    'host'     => $this->config->getHost(),
                    'port'     => $this->config->getPort(),
                    'protocol' => $this->config->getProtocol(),
                ]],
                'connection_timeout_seconds' => 2,
            ]);
        }
        return $this->client;
    }

    private function mapProductHits(array $hits): array
    {
        return array_map(static fn(array $hit): array => [
            'id'        => (int) ($hit['document']['id'] ?? 0),
            'name'      => $hit['document']['name'] ?? null,
            'sku'       => $hit['document']['sku'] ?? null,
            'url'       => $hit['document']['url'] ?? null,
            'image_url' => $hit['document']['image_url'] ?? null,
            'price'     => isset($hit['document']['price']) ? (float) $hit['document']['price'] : null,
            'categories'=> $hit['document']['categories'] ?? [],
        ], $hits);
    }

    private function mapCategoryHits(array $hits): array
    {
        return array_map(static fn(array $hit): array => [
            'name'       => $hit['document']['name'] ?? null,
            'url'        => $hit['document']['url'] ?? null,
            'breadcrumb' => $hit['document']['breadcrumb'] ?? [],
        ], $hits);
    }

    private function mapFacets(array $facetCounts): array
    {
        return array_map(static fn(array $facet): array => [
            'name'    => $facet['field_name'],
            'label'   => ucfirst(str_replace('_', ' ', $facet['field_name'])),
            'options' => array_map(static fn(array $c): array => [
                'value' => $c['value'],
                'label' => $c['value'],
                'count' => (int) $c['count'],
            ], $facet['counts'] ?? []),
        ], $facetCounts);
    }

    private function buildFilterString(array $filters): string
    {
        $parts = [];
        foreach ($filters as $filter) {
            $condition = $filter['condition_type'] ?? 'eq';
            $parts[] = match ($condition) {
                'eq'  => $filter['field'] . ':=' . $filter['value'],
                'gt'  => $filter['field'] . ':>' . $filter['value'],
                'lt'  => $filter['field'] . ':<' . $filter['value'],
                'gte' => $filter['field'] . ':>=' . $filter['value'],
                'lte' => $filter['field'] . ':<=' . $filter['value'],
                default => $filter['field'] . ':=' . $filter['value'],
            };
        }
        return implode(' && ', $parts);
    }
}
```

- [ ] **Step 5: Create `etc/di.xml` to bind the interface**

`app/code/Develo/TypesenseGraphQl/etc/di.xml`:
```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <preference for="Develo\TypesenseGraphQl\Api\TypesenseClientInterface"
                type="Develo\TypesenseGraphQl\Model\Client\TypesenseClient"/>
</config>
```

- [ ] **Step 6: Run tests to confirm they pass**

```bash
vendor/bin/phpunit app/code/Develo/TypesenseGraphQl/Test/Unit/Model/Client/TypesenseClientTest.php
```

Expected: `OK (2 tests, 8 assertions)`

- [ ] **Step 7: Commit**

```bash
git add app/code/Develo/TypesenseGraphQl/Api/ \
        app/code/Develo/TypesenseGraphQl/Model/Client/ \
        app/code/Develo/TypesenseGraphQl/etc/di.xml \
        app/code/Develo/TypesenseGraphQl/Test/Unit/Model/Client/
git commit -m "feat: add TypesenseClient and interface"
```

---

## Task 5: GraphQL schema + Suggest resolver + tests

**Files:**
- Create: `app/code/Develo/TypesenseGraphQl/etc/schema.graphqls`
- Create: `app/code/Develo/TypesenseGraphQl/Model/Resolver/Suggest.php`
- Test: `app/code/Develo/TypesenseGraphQl/Test/Unit/Model/Resolver/SuggestTest.php`

- [ ] **Step 1: Write the failing test**

`app/code/Develo/TypesenseGraphQl/Test/Unit/Model/Resolver/SuggestTest.php`:
```php
<?php

declare(strict_types=1);

namespace Develo\TypesenseGraphQl\Test\Unit\Model\Resolver;

use Develo\TypesenseGraphQl\Api\TypesenseClientInterface;
use Develo\TypesenseGraphQl\Model\Config;
use Develo\TypesenseGraphQl\Model\Resolver\Suggest;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SuggestTest extends TestCase
{
    private Suggest $resolver;
    private TypesenseClientInterface|MockObject $client;
    private Config|MockObject $config;

    protected function setUp(): void
    {
        $this->client = $this->createMock(TypesenseClientInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->resolver = new Suggest($this->client, $this->config);
    }

    public function testThrowsOnEmptyQuery(): void
    {
        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('query cannot be empty');

        $this->resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            ['query' => '   ']
        );
    }

    public function testThrowsWhenNotConfigured(): void
    {
        $this->config->method('isConfigured')->willReturn(false);
        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Typesense is not configured');

        $this->resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            ['query' => 'shirt']
        );
    }

    public function testReturnsGroupedResults(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->client->method('suggest')->with('shirt')->willReturn([
            'products'   => [['name' => 'Blue Shirt', 'sku' => 'BS-001', 'url' => '/blue-shirt', 'image_url' => '/img.jpg', 'price' => 29.99]],
            'categories' => [['name' => 'Men', 'url' => '/men', 'breadcrumb' => ['Men']]],
            'terms'      => [],
        ]);

        $result = $this->resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            ['query' => 'shirt']
        );

        $this->assertCount(1, $result['products']);
        $this->assertSame('Blue Shirt', $result['products'][0]['name']);
        $this->assertCount(1, $result['categories']);
        $this->assertSame([], $result['terms']);
    }

    public function testClientExceptionBecomesGraphQlError(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->client->method('suggest')->willThrowException(new \RuntimeException('connection refused'));

        $this->expectException(\Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException::class);
        $this->expectExceptionMessage('Search temporarily unavailable');

        $this->resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            ['query' => 'shirt']
        );
    }
}
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
vendor/bin/phpunit app/code/Develo/TypesenseGraphQl/Test/Unit/Model/Resolver/SuggestTest.php
```

Expected: `Error: Class "Develo\TypesenseGraphQl\Model\Resolver\Suggest" not found`

- [ ] **Step 3: Create the GraphQL schema**

`app/code/Develo/TypesenseGraphQl/etc/schema.graphqls`:
```graphql
type Query {
    typeseenseSuggest(query: String! @doc(description: "The search term to get autocomplete suggestions for")): TypeseenseSuggestResult
        @resolver(class: "Develo\\TypesenseGraphQl\\Model\\Resolver\\Suggest")
        @doc(description: "Returns Typesense autocomplete suggestions: matching products, categories, and popular search terms")
        @cache(cacheable: false)

    typesenseSearch(
        query: String! @doc(description: "The full-text search query")
        page: Int = 1 @doc(description: "Page number (1-indexed)")
        pageSize: Int = 20 @doc(description: "Results per page")
        filters: [TypesenseFilterInput] @doc(description: "Optional attribute filters")
        sort: TypesenseSortInput @doc(description: "Optional sort field and direction")
    ): TypesenseSearchResult
        @resolver(class: "Develo\\TypesenseGraphQl\\Model\\Resolver\\Search")
        @doc(description: "Returns Typesense full-text search results with facets and pagination")
        @cache(cacheable: false)
}

type TypeseenseSuggestResult @doc(description: "Autocomplete suggestions grouped by type") {
    products: [TypeseenseSuggestProduct] @doc(description: "Up to 5 matching products")
    categories: [TypeseenseSuggestCategory] @doc(description: "Up to 3 matching categories")
    terms: [TypeseenseSuggestTerm] @doc(description: "Popular search terms (empty until Typesense Analytics is configured)")
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

type TypesenseSearchResult @doc(description: "Full search results with facets and pagination") {
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

- [ ] **Step 4: Implement `Suggest.php`**

`app/code/Develo/TypesenseGraphQl/Model/Resolver/Suggest.php`:
```php
<?php

declare(strict_types=1);

namespace Develo\TypesenseGraphQl\Model\Resolver;

use Develo\TypesenseGraphQl\Api\TypesenseClientInterface;
use Develo\TypesenseGraphQl\Model\Config;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

class Suggest implements ResolverInterface
{
    public function __construct(
        private readonly TypesenseClientInterface $client,
        private readonly Config $config
    ) {}

    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): array
    {
        $queryText = trim((string) ($args['query'] ?? ''));
        if ($queryText === '') {
            throw new GraphQlInputException(__('query cannot be empty'));
        }

        if (!$this->config->isConfigured()) {
            throw new GraphQlInputException(__('Typesense is not configured'));
        }

        try {
            return $this->client->suggest($queryText);
        } catch (\Exception $e) {
            throw new GraphQlNoSuchEntityException(__('Search temporarily unavailable'));
        }
    }
}
```

- [ ] **Step 5: Run tests to confirm they pass**

```bash
vendor/bin/phpunit app/code/Develo/TypesenseGraphQl/Test/Unit/Model/Resolver/SuggestTest.php
```

Expected: `OK (4 tests, 5 assertions)`

- [ ] **Step 6: Commit**

```bash
git add app/code/Develo/TypesenseGraphQl/etc/schema.graphqls \
        app/code/Develo/TypesenseGraphQl/Model/Resolver/Suggest.php \
        app/code/Develo/TypesenseGraphQl/Test/Unit/Model/Resolver/SuggestTest.php
git commit -m "feat: add typeseenseSuggest GraphQL resolver and schema"
```

---

## Task 6: Search resolver + tests

**Files:**
- Create: `app/code/Develo/TypesenseGraphQl/Model/Resolver/Search.php`
- Test: `app/code/Develo/TypesenseGraphQl/Test/Unit/Model/Resolver/SearchTest.php`

- [ ] **Step 1: Write the failing test**

`app/code/Develo/TypesenseGraphQl/Test/Unit/Model/Resolver/SearchTest.php`:
```php
<?php

declare(strict_types=1);

namespace Develo\TypesenseGraphQl\Test\Unit\Model\Resolver;

use Develo\TypesenseGraphQl\Api\TypesenseClientInterface;
use Develo\TypesenseGraphQl\Model\Config;
use Develo\TypesenseGraphQl\Model\Resolver\Search;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SearchTest extends TestCase
{
    private Search $resolver;
    private TypesenseClientInterface|MockObject $client;
    private Config|MockObject $config;

    protected function setUp(): void
    {
        $this->client = $this->createMock(TypesenseClientInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->resolver = new Search($this->client, $this->config);
    }

    public function testThrowsOnEmptyQuery(): void
    {
        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('query cannot be empty');

        $this->resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            ['query' => '', 'page' => 1, 'pageSize' => 20, 'filters' => null, 'sort' => null]
        );
    }

    public function testThrowsWhenNotConfigured(): void
    {
        $this->config->method('isConfigured')->willReturn(false);
        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Typesense is not configured');

        $this->resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            ['query' => 'shirt', 'page' => 1, 'pageSize' => 20, 'filters' => null, 'sort' => null]
        );
    }

    public function testReturnsSearchResults(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->client->method('search')
            ->with('shirt', 1, 20, [], null)
            ->willReturn([
                'items'          => [['id' => 1, 'name' => 'Red Shirt', 'sku' => 'RS-001', 'url' => '/red-shirt', 'image_url' => '/img.jpg', 'price' => 19.99, 'categories' => ['Men']]],
                'facets'         => [['name' => 'categories', 'label' => 'Categories', 'options' => [['value' => 'Men', 'label' => 'Men', 'count' => 3]]]],
                'total_count'    => 1,
                'search_time_ms' => 4,
                'page_info'      => ['current_page' => 1, 'page_size' => 20, 'total_pages' => 1],
            ]);

        $result = $this->resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            ['query' => 'shirt', 'page' => 1, 'pageSize' => 20, 'filters' => null, 'sort' => null]
        );

        $this->assertSame(1, $result['total_count']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('Red Shirt', $result['items'][0]['name']);
        $this->assertCount(1, $result['facets']);
    }

    public function testClientExceptionBecomesGraphQlError(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->client->method('search')->willThrowException(new \RuntimeException('timeout'));

        $this->expectException(GraphQlNoSuchEntityException::class);
        $this->expectExceptionMessage('Search temporarily unavailable');

        $this->resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            ['query' => 'shirt', 'page' => 1, 'pageSize' => 20, 'filters' => null, 'sort' => null]
        );
    }
}
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
vendor/bin/phpunit app/code/Develo/TypesenseGraphQl/Test/Unit/Model/Resolver/SearchTest.php
```

Expected: `Error: Class "Develo\TypesenseGraphQl\Model\Resolver\Search" not found`

- [ ] **Step 3: Implement `Search.php`**

`app/code/Develo/TypesenseGraphQl/Model/Resolver/Search.php`:
```php
<?php

declare(strict_types=1);

namespace Develo\TypesenseGraphQl\Model\Resolver;

use Develo\TypesenseGraphQl\Api\TypesenseClientInterface;
use Develo\TypesenseGraphQl\Model\Config;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

class Search implements ResolverInterface
{
    public function __construct(
        private readonly TypesenseClientInterface $client,
        private readonly Config $config
    ) {}

    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): array
    {
        $queryText = trim((string) ($args['query'] ?? ''));
        if ($queryText === '') {
            throw new GraphQlInputException(__('query cannot be empty'));
        }

        if (!$this->config->isConfigured()) {
            throw new GraphQlInputException(__('Typesense is not configured'));
        }

        $page     = max(1, (int) ($args['page'] ?? 1));
        $pageSize = max(1, (int) ($args['pageSize'] ?? 20));
        $filters  = $args['filters'] ?? [];
        $sort     = $args['sort'] ?? null;

        try {
            return $this->client->search($queryText, $page, $pageSize, $filters ?: [], $sort);
        } catch (\Exception $e) {
            throw new GraphQlNoSuchEntityException(__('Search temporarily unavailable'));
        }
    }
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
vendor/bin/phpunit app/code/Develo/TypesenseGraphQl/Test/Unit/Model/Resolver/SearchTest.php
```

Expected: `OK (4 tests, 6 assertions)`

- [ ] **Step 5: Commit**

```bash
git add app/code/Develo/TypesenseGraphQl/Model/Resolver/Search.php \
        app/code/Develo/TypesenseGraphQl/Test/Unit/Model/Resolver/SearchTest.php
git commit -m "feat: add typesenseSearch GraphQL resolver"
```

---

## Task 7: Compile DI and verify full test suite + GraphQL schema

- [ ] **Step 1: Run all module tests**

```bash
vendor/bin/phpunit app/code/Develo/TypesenseGraphQl/Test/
```

Expected: All tests pass (13+ tests, 0 failures)

- [ ] **Step 2: Compile DI**

```bash
php -d memory_limit=-1 bin/magento setup:di:compile
```

Expected: `Generated code successfully` with no errors referencing `Develo\TypesenseGraphQl`.

- [ ] **Step 3: Flush cache and verify GraphQL schema loads**

```bash
bin/magento cache:flush
```

Then run a quick introspection to confirm the queries exist:

```bash
curl -s -X POST http://localhost:8080/graphql \
  -H "Content-Type: application/json" \
  -d '{"query":"{ __schema { queryType { fields { name } } } }"}' \
  | python3 -c "import sys,json; fields=[f['name'] for f in json.load(sys.stdin)['data']['__schema']['queryType']['fields']]; print('typeseenseSuggest' in fields, 'typesenseSearch' in fields)"
```

Expected: `True True`

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "feat: Develo_TypesenseGraphQl — complete GraphQL search integration"
```

---

## Notes for integration (after client sends keys)

1. Replace placeholder values in `app/etc/env.php` with real Typesense credentials
2. Run full reindex: `bin/magento indexer:reindex catalogsearch_fulltext`
3. Verify collections exist in Typesense dashboard
4. Smoke test both queries via GraphQL playground at `http://localhost:8080/graphql`
5. Confirm collection field names match what run-as-root actually indexes — adjust `query_by` and `facet_by` in `TypesenseClient.php` if needed
