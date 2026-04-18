<?php
declare(strict_types=1);

namespace Magento\Reward\Helper;

/**
 * Stub for Magento Commerce Reward helper — always reports rewards as disabled on CE.
 */
class Data
{
    public function isEnabledOnFront(?int $websiteId = null): bool
    {
        return false;
    }

    public function getNotificationConfig(string $key, ?int $websiteId = null): mixed
    {
        return null;
    }
}
