<?php
declare(strict_types=1);

namespace Magento\Reward\Model;

/**
 * Stub factory for Magento Commerce Reward model — no-op on Community Edition.
 */
class RewardFactory
{
    public function create(array $data = []): Reward
    {
        return new Reward();
    }
}
