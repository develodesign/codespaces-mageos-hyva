<?php
declare(strict_types=1);

namespace Develo\SocialLoginGraphQl\Model\Stub;

/**
 * Stub for Magento\Reward\Model\RewardFactory (Magento Commerce / EE only).
 * Never actually called since RewardData::isEnabledOnFront() returns false.
 */
class RewardFactory
{
    public function create(array $data = []): object
    {
        return new \stdClass();
    }
}
