<?php
declare(strict_types=1);

namespace Magento\Reward\Model;

/**
 * Stub for Magento Commerce Reward model — no-op on Community Edition.
 */
class Reward
{
    public const REWARD_ACTION_REGISTER = 1;

    public function setCustomer(mixed $customer): static { return $this; }
    public function setActionEntity(mixed $entity): static { return $this; }
    public function setStore(mixed $store): static { return $this; }
    public function setAction(int $action): static { return $this; }
    public function updateRewardPoints(): void {}
}
