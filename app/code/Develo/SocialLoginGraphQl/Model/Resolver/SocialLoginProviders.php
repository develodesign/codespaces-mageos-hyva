<?php
declare(strict_types=1);

namespace Develo\SocialLoginGraphQl\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Techyouknow\SocialLogin\Helper\Social as SocialHelper;

class SocialLoginProviders implements ResolverInterface
{
    /** Providers whose JS SDK issues a token directly (no redirect needed). */
    private const TOKEN_FLOW_PROVIDERS = ['google', 'facebook', 'github', 'apple'];

    /** Map from techyouknow lowercase adapter ID → GraphQL enum value */
    private const ENUM_MAP = [
        'google'    => 'GOOGLE',
        'facebook'  => 'FACEBOOK',
        'apple'     => 'APPLE',
        'github'    => 'GITHUB',
        'twitter'   => 'TWITTER',
        'linkedin'  => 'LINKEDIN',
        'amazon'    => 'AMAZON',
        'yahoo'     => 'YAHOO',
        'instagram' => 'INSTAGRAM',
    ];

    public function __construct(
        private readonly SocialHelper $socialHelper,
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
        $providers = [];

        foreach ($this->socialHelper->getSocialNetworksList() as $adapterId => $label) {
            if (!$this->socialHelper->isAdapterEnable($adapterId)) {
                continue;
            }

            $clientId = (string) $this->socialHelper->getAdapterConfigValue($adapterId, SocialHelper::CONFIG_APP_ID);

            $providers[] = [
                'provider'      => self::ENUM_MAP[$adapterId] ?? strtoupper($adapterId),
                'client_id'     => $clientId,
                'is_token_flow' => in_array($adapterId, self::TOKEN_FLOW_PROVIDERS, true),
            ];
        }

        return ['providers' => $providers];
    }
}
