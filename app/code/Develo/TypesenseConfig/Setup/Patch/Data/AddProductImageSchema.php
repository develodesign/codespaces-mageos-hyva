<?php
declare(strict_types=1);

namespace Develo\TypesenseConfig\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Registers thumbnail and small_image as additional Typesense product schema fields
 * so image URLs are indexed alongside every product document.
 */
class AddProductImageSchema implements DataPatchInterface
{
    private const CONFIG_PATH = 'typesense_products/settings/schema';

    private const IMAGE_FIELDS = [
        [
            'name'     => 'thumbnail',
            'type'     => 'string',
            'facet'    => '0',
            'optional' => '1',
            'index'    => '0',
            'infix'    => '0',
            'sort'     => '0',
        ],
        [
            'name'     => 'small_image',
            'type'     => 'string',
            'facet'    => '0',
            'optional' => '1',
            'index'    => '0',
            'infix'    => '0',
            'sort'     => '0',
        ],
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly WriterInterface          $configWriter
    ) {}

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        $this->configWriter->save(
            self::CONFIG_PATH,
            json_encode(self::IMAGE_FIELDS, JSON_THROW_ON_ERROR)
        );

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    public function revert(): void
    {
        $this->configWriter->delete(self::CONFIG_PATH);
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
