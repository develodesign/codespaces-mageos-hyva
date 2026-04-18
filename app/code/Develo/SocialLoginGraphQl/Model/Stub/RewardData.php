<?php
declare(strict_types=1);

namespace Develo\SocialLoginGraphQl\Model\Stub;

/**
 * Stub for Magento\Reward\Helper\Data (Magento Commerce / EE only).
 * Satisfies the DI dependency in Techyouknow\SocialLogin\Model\Social on Community Edition.
 * isEnabledOnFront() returns false so assignRewardPoints() is a no-op.
 */
class RewardData
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
