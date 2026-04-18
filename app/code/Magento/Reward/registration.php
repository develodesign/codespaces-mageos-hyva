<?php
/**
 * Stub implementation of Magento_Reward (Commerce / EE module) for Community Edition.
 * Satisfies DI compilation of third-party modules that optionally depend on Magento Rewards.
 */
use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Magento_Reward',
    __DIR__
);
