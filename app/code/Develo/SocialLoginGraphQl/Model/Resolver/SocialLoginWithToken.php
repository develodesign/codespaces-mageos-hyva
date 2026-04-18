<?php
declare(strict_types=1);

namespace Develo\SocialLoginGraphQl\Model\Resolver;

use Develo\SocialLoginGraphQl\Model\SocialAuthService;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

class SocialLoginWithToken implements ResolverInterface
{
    public function __construct(
        private readonly SocialAuthService $socialAuthService,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ): array {
        $provider = trim((string) ($args['provider'] ?? ''));
        $token    = trim((string) ($args['token'] ?? ''));

        if ($provider === '') {
            throw new GraphQlInputException(__('provider is required.'));
        }
        if ($token === '') {
            throw new GraphQlInputException(__('token is required.'));
        }

        return $this->socialAuthService->authenticateWithToken($provider, $token);
    }
}
