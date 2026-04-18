<?php
declare(strict_types=1);

namespace Develo\SocialLoginGraphQl\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Model\EmailNotificationInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Math\Random;
use Magento\Integration\Api\TokenManager;
use Magento\Integration\Model\CustomUserContext;
use Magento\Store\Model\StoreManagerInterface;
use Techyouknow\SocialLogin\Api\Data\SocialNetworkCustomerFactory;
use Techyouknow\SocialLogin\Helper\Social as SocialHelper;
use Techyouknow\SocialLogin\Model\Repository\SocialLoginCustomerRepository;
use Techyouknow\SocialLogin\Model\ResourceModel\SocialLoginCustomer\CollectionFactory as SocialLinkCollectionFactory;

class SocialAuthService
{
    /** GraphQL enum value → techyouknow adapter ID (lowercase) */
    private const PROVIDER_ID_MAP = [
        'GOOGLE'    => 'google',
        'FACEBOOK'  => 'facebook',
        'APPLE'     => 'apple',
        'GITHUB'    => 'github',
        'TWITTER'   => 'twitter',
        'LINKEDIN'  => 'linkedin',
        'AMAZON'    => 'amazon',
        'YAHOO'     => 'yahoo',
        'INSTAGRAM' => 'instagram',
    ];

    /** Providers that use a JS SDK to obtain a token on the frontend */
    private const TOKEN_FLOW_PROVIDERS = ['GOOGLE', 'FACEBOOK', 'GITHUB', 'APPLE'];

    public function __construct(
        private readonly SocialLoginCustomerRepository $socialLinkRepository,
        private readonly SocialLinkCollectionFactory $socialLinkCollectionFactory,
        private readonly SocialNetworkCustomerFactory $socialNetworkCustomerFactory,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerInterfaceFactory $customerFactory,
        private readonly AccountManagementInterface $accountManagement,
        private readonly EmailNotificationInterface $emailNotification,
        private readonly TokenManager $tokenManager,
        private readonly SocialHelper $socialHelper,
        private readonly StoreManagerInterface $storeManager,
        private readonly Random $random,
        private readonly Curl $curl,
    ) {
    }

    /**
     * Authenticate via a provider-issued token (Google id_token, Facebook/GitHub access_token, Apple id_token).
     *
     * @param string $provider  GraphQL enum string, e.g. "GOOGLE"
     * @param string $token     Token from the provider JS SDK
     * @return array{token: string, is_new_customer: bool, customer_email: string}
     * @throws GraphQlInputException
     * @throws GraphQlAuthorizationException
     */
    public function authenticateWithToken(string $provider, string $token): array
    {
        if (!in_array($provider, self::TOKEN_FLOW_PROVIDERS, true)) {
            throw new GraphQlInputException(
                __('Provider %1 does not support token flow. Use socialLoginWithCode instead.', $provider)
            );
        }

        $adapterId = $this->resolveAdapterId($provider);
        $profile   = $this->verifyProviderToken($provider, $token, $adapterId);

        return $this->findOrCreateCustomer($profile, $adapterId);
    }

    /**
     * Authenticate via an OAuth 2.0 authorisation code (Twitter, LinkedIn, Amazon, Yahoo, Instagram).
     *
     * @param string $provider    GraphQL enum string, e.g. "LINKEDIN"
     * @param string $code        Authorisation code from the provider redirect
     * @param string $redirectUri Redirect URI used in the original auth request
     * @return array{token: string, is_new_customer: bool, customer_email: string}
     * @throws GraphQlInputException
     * @throws GraphQlAuthorizationException
     */
    public function authenticateWithCode(string $provider, string $code, string $redirectUri): array
    {
        $adapterId = $this->resolveAdapterId($provider);
        $profile   = $this->exchangeCodeForProfile($provider, $code, $redirectUri, $adapterId);

        return $this->findOrCreateCustomer($profile, $adapterId);
    }

    /**
     * Core find-or-create logic — mirrors the original controller flow but without Commerce dependencies.
     *
     * @param array{identifier: string, email: string, firstname: string, lastname: string} $profile
     * @param string $adapterId  Lowercase provider ID used by Techyouknow module
     * @return array{token: string, is_new_customer: bool, customer_email: string}
     */
    private function findOrCreateCustomer(array $profile, string $adapterId): array
    {
        $isNew = false;

        // 1. Check if this social ID is already linked to a Magento customer.
        $existingCustomerId = $this->getCustomerIdBySocialLink($profile['identifier'], $adapterId);
        if ($existingCustomerId !== null) {
            try {
                $customer = $this->customerRepository->getById($existingCustomerId);
            } catch (NoSuchEntityException) {
                $existingCustomerId = null; // Orphaned link — fall through.
            }
        }

        if ($existingCustomerId === null) {
            try {
                // 2. Try to find by email (handles pre-existing accounts).
                $customer = $this->customerRepository->get(
                    $profile['email'],
                    (int) $this->storeManager->getStore()->getWebsiteId()
                );

                // Link the social account if not yet linked.
                if (!$this->socialLinkRepository->socialNetworkCustomerExists($profile, $adapterId)) {
                    $this->createSocialLink($profile['identifier'], (int) $customer->getId(), $adapterId);
                }
            } catch (NoSuchEntityException) {
                // 3. Brand new customer.
                $customer = $this->createCustomerAccount($profile, $adapterId);
                $isNew    = true;
            }
        }

        return [
            'token'           => $this->generateToken((int) $customer->getId()),
            'is_new_customer' => $isNew,
            'customer_email'  => (string) $customer->getEmail(),
        ];
    }

    /**
     * Create a new Magento customer and link the social account.
     * Mirrors Social::createCustomerAccount() but without Commerce (Reward) dependencies.
     *
     * @throws LocalizedException
     */
    private function createCustomerAccount(array $profile, string $adapterId): CustomerInterface
    {
        $store    = $this->storeManager->getStore();
        $customer = $this->customerFactory->create();
        $customer
            ->setFirstname($profile['firstname'])
            ->setLastname($profile['lastname'])
            ->setEmail($profile['email'])
            ->setStoreId((int) $store->getId())
            ->setWebsiteId((int) $store->getWebsiteId())
            ->setCreatedIn($store->getName());

        $customer = $this->customerRepository->save($customer);
        $this->createSocialLink($profile['identifier'], (int) $customer->getId(), $adapterId);

        // Generate a reset-password token so the customer can set a password later if desired.
        $this->accountManagement->changeResetPasswordLinkToken($customer, $this->random->getUniqueHash());

        // Send the "registered without password" welcome email.
        try {
            $this->emailNotification->newAccount(
                $customer,
                EmailNotificationInterface::NEW_ACCOUNT_EMAIL_REGISTERED_NO_PASSWORD
            );
        } catch (\Exception) {
            // Non-fatal — do not block login if email delivery fails.
        }

        return $customer;
    }

    /**
     * Persist the social_id → customer_id mapping in tyk_sociallogin_customer.
     */
    private function createSocialLink(string $socialId, int $customerId, string $adapterId): void
    {
        $link = $this->socialNetworkCustomerFactory->create();
        $link
            ->setSocialId($socialId)
            ->setCustomerId($customerId)
            ->setSocialType($adapterId);

        $this->socialLinkRepository->save($link);
    }

    // ─── Token-flow: direct provider verification ────────────────────────────

    private function verifyProviderToken(string $provider, string $token, string $adapterId): array
    {
        return match ($provider) {
            'GOOGLE'   => $this->verifyGoogleToken($token, $adapterId),
            'FACEBOOK' => $this->verifyFacebookToken($token),
            'GITHUB'   => $this->verifyGithubToken($token),
            'APPLE'    => $this->verifyAppleToken($token, $adapterId),
            default    => throw new GraphQlInputException(__('Unsupported token-flow provider: %1', $provider)),
        };
    }

    private function verifyGoogleToken(string $idToken, string $adapterId): array
    {
        $response = $this->httpGet('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));

        if (isset($response['error_description'])) {
            throw new GraphQlAuthorizationException(
                __('Google token validation failed: %1', $response['error_description'])
            );
        }

        $configuredClientId = $this->socialHelper->getAdapterConfigValue($adapterId, 'app_id');
        if ($configuredClientId && ($response['aud'] ?? '') !== $configuredClientId) {
            throw new GraphQlAuthorizationException(__('Google token audience mismatch.'));
        }

        if (empty($response['sub'])) {
            throw new GraphQlAuthorizationException(__('Google token did not return a user identifier.'));
        }

        return $this->normaliseProfile(
            identifier: $response['sub'],
            email: $response['email'] ?? null,
            firstName: $response['given_name'] ?? null,
            lastName: $response['family_name'] ?? null,
            displayName: $response['name'] ?? null,
            adapterId: $adapterId
        );
    }

    private function verifyFacebookToken(string $accessToken): array
    {
        $fields   = 'id,first_name,last_name,email,name';
        $response = $this->httpGet(
            'https://graph.facebook.com/me?fields=' . $fields . '&access_token=' . urlencode($accessToken)
        );

        if (isset($response['error'])) {
            throw new GraphQlAuthorizationException(
                __('Facebook token validation failed: %1', $response['error']['message'] ?? 'unknown error')
            );
        }

        if (empty($response['id'])) {
            throw new GraphQlAuthorizationException(__('Facebook token did not return a user identifier.'));
        }

        return $this->normaliseProfile(
            identifier: $response['id'],
            email: $response['email'] ?? null,
            firstName: $response['first_name'] ?? null,
            lastName: $response['last_name'] ?? null,
            displayName: $response['name'] ?? null,
            adapterId: 'facebook'
        );
    }

    private function verifyGithubToken(string $accessToken): array
    {
        $this->curl->setHeaders([
            'Authorization' => 'token ' . $accessToken,
            'Accept'        => 'application/vnd.github.v3+json',
            'User-Agent'    => 'Magento-SocialLogin',
        ]);
        $this->curl->get('https://api.github.com/user');
        $response = json_decode($this->curl->getBody(), true) ?? [];

        if (isset($response['message'])) {
            throw new GraphQlAuthorizationException(__('GitHub token validation failed: %1', $response['message']));
        }

        if (empty($response['id'])) {
            throw new GraphQlAuthorizationException(__('GitHub token did not return a user identifier.'));
        }

        $email     = $response['email'] ?? $this->fetchGithubPrimaryEmail($accessToken);
        $nameParts = explode(' ', $response['name'] ?? '', 2);

        return $this->normaliseProfile(
            identifier: (string) $response['id'],
            email: $email,
            firstName: $nameParts[0] ?? null,
            lastName: $nameParts[1] ?? null,
            displayName: $response['login'] ?? null,
            adapterId: 'github'
        );
    }

    private function fetchGithubPrimaryEmail(string $accessToken): ?string
    {
        $this->curl->setHeaders([
            'Authorization' => 'token ' . $accessToken,
            'Accept'        => 'application/vnd.github.v3+json',
            'User-Agent'    => 'Magento-SocialLogin',
        ]);
        $this->curl->get('https://api.github.com/user/emails');
        $emails = json_decode($this->curl->getBody(), true) ?? [];

        foreach ($emails as $entry) {
            if (!empty($entry['primary']) && !empty($entry['verified'])) {
                return $entry['email'];
            }
        }

        return null;
    }

    private function verifyAppleToken(string $idToken, string $adapterId): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new GraphQlAuthorizationException(__('Invalid Apple ID token format.'));
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) ?? [];

        if (($payload['iss'] ?? '') !== 'https://appleid.apple.com') {
            throw new GraphQlAuthorizationException(__('Apple token issuer is invalid.'));
        }

        $configuredClientId = $this->socialHelper->getAdapterConfigValue($adapterId, 'app_id');
        if ($configuredClientId && ($payload['aud'] ?? '') !== $configuredClientId) {
            throw new GraphQlAuthorizationException(__('Apple token audience mismatch.'));
        }

        if (($payload['exp'] ?? 0) < time()) {
            throw new GraphQlAuthorizationException(__('Apple token has expired.'));
        }

        if (empty($payload['sub'])) {
            throw new GraphQlAuthorizationException(__('Apple token did not return a user identifier.'));
        }

        $nameParts = explode(' ', $payload['name'] ?? '', 2);

        return $this->normaliseProfile(
            identifier: $payload['sub'],
            email: $payload['email'] ?? null,
            firstName: $payload['given_name'] ?? ($nameParts[0] ?? null),
            lastName: $payload['family_name'] ?? ($nameParts[1] ?? null),
            displayName: null,
            adapterId: $adapterId
        );
    }

    // ─── Code-flow: OAuth 2.0 authorisation code exchange ────────────────────

    private function exchangeCodeForProfile(
        string $provider,
        string $code,
        string $redirectUri,
        string $adapterId
    ): array {
        return match ($provider) {
            'TWITTER'   => $this->exchangeTwitterCode($code, $redirectUri, $adapterId),
            'LINKEDIN'  => $this->exchangeLinkedInCode($code, $redirectUri, $adapterId),
            'AMAZON'    => $this->exchangeAmazonCode($code, $redirectUri, $adapterId),
            'YAHOO'     => $this->exchangeYahooCode($code, $redirectUri, $adapterId),
            'INSTAGRAM' => $this->exchangeInstagramCode($code, $redirectUri, $adapterId),
            default     => throw new GraphQlInputException(__('Unsupported code-flow provider: %1', $provider)),
        };
    }

    private function exchangeLinkedInCode(string $code, string $redirectUri, string $adapterId): array
    {
        $accessToken = $this->exchangeOAuth2Code(
            tokenEndpoint: 'https://www.linkedin.com/oauth/v2/accessToken',
            code: $code,
            redirectUri: $redirectUri,
            adapterId: $adapterId
        );

        $response = $this->httpGetWithBearer('https://api.linkedin.com/v2/userinfo', $accessToken);

        if (empty($response['sub'])) {
            throw new GraphQlAuthorizationException(__('LinkedIn did not return a user identifier.'));
        }

        return $this->normaliseProfile(
            identifier: $response['sub'],
            email: $response['email'] ?? null,
            firstName: $response['given_name'] ?? null,
            lastName: $response['family_name'] ?? null,
            displayName: $response['name'] ?? null,
            adapterId: $adapterId
        );
    }

    private function exchangeAmazonCode(string $code, string $redirectUri, string $adapterId): array
    {
        $accessToken = $this->exchangeOAuth2Code(
            tokenEndpoint: 'https://api.amazon.com/auth/o2/token',
            code: $code,
            redirectUri: $redirectUri,
            adapterId: $adapterId
        );

        $response    = $this->httpGetWithBearer('https://api.amazon.com/user/profile', $accessToken);
        $nameParts   = explode(' ', $response['name'] ?? '', 2);

        if (empty($response['user_id'])) {
            throw new GraphQlAuthorizationException(__('Amazon did not return a user identifier.'));
        }

        return $this->normaliseProfile(
            identifier: $response['user_id'],
            email: $response['email'] ?? null,
            firstName: $nameParts[0] ?? null,
            lastName: $nameParts[1] ?? null,
            displayName: $response['name'] ?? null,
            adapterId: $adapterId
        );
    }

    private function exchangeYahooCode(string $code, string $redirectUri, string $adapterId): array
    {
        $accessToken = $this->exchangeOAuth2Code(
            tokenEndpoint: 'https://api.login.yahoo.com/oauth2/get_token',
            code: $code,
            redirectUri: $redirectUri,
            adapterId: $adapterId
        );

        $response = $this->httpGetWithBearer('https://api.login.yahoo.com/openid/v1/userinfo', $accessToken);

        if (empty($response['sub'])) {
            throw new GraphQlAuthorizationException(__('Yahoo did not return a user identifier.'));
        }

        return $this->normaliseProfile(
            identifier: $response['sub'],
            email: $response['email'] ?? null,
            firstName: $response['given_name'] ?? null,
            lastName: $response['family_name'] ?? null,
            displayName: $response['name'] ?? null,
            adapterId: $adapterId
        );
    }

    private function exchangeInstagramCode(string $code, string $redirectUri, string $adapterId): array
    {
        $clientId     = $this->socialHelper->getAdapterConfigValue($adapterId, 'app_id');
        $clientSecret = $this->socialHelper->getAdapterConfigValue($adapterId, 'app_secret');

        $this->curl->setHeaders(['Content-Type' => 'application/x-www-form-urlencoded']);
        $this->curl->post('https://api.instagram.com/oauth/access_token', http_build_query([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $redirectUri,
            'code'          => $code,
        ]));

        $tokenData = json_decode($this->curl->getBody(), true) ?? [];
        if (empty($tokenData['access_token'])) {
            throw new GraphQlAuthorizationException(
                __('Instagram code exchange failed: %1', $tokenData['error_message'] ?? 'unknown error')
            );
        }

        $userId   = (string) ($tokenData['user_id'] ?? '');
        $response = $this->httpGet(
            'https://graph.instagram.com/' . $userId . '?fields=id,username&access_token=' . urlencode($tokenData['access_token'])
        );

        return $this->normaliseProfile(
            identifier: $response['id'] ?? $userId,
            email: null, // Instagram Basic Display does not expose email
            firstName: $response['username'] ?? null,
            lastName: null,
            displayName: $response['username'] ?? null,
            adapterId: $adapterId
        );
    }

    private function exchangeTwitterCode(string $code, string $redirectUri, string $adapterId): array
    {
        $clientId     = $this->socialHelper->getAdapterConfigValue($adapterId, 'app_id');
        $clientSecret = $this->socialHelper->getAdapterConfigValue($adapterId, 'app_secret');

        $this->curl->setHeaders([
            'Content-Type'  => 'application/x-www-form-urlencoded',
            'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
        ]);
        $this->curl->post('https://api.twitter.com/2/oauth2/token', http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'code_verifier' => 'challenge', // must match the code_challenge sent by the Angular app
        ]));

        $tokenData = json_decode($this->curl->getBody(), true) ?? [];
        if (empty($tokenData['access_token'])) {
            throw new GraphQlAuthorizationException(
                __('Twitter code exchange failed: %1', $tokenData['error_description'] ?? 'unknown error')
            );
        }

        $response  = $this->httpGetWithBearer(
            'https://api.twitter.com/2/users/me?user.fields=name,username',
            $tokenData['access_token']
        );

        $data      = $response['data'] ?? $response;
        $nameParts = explode(' ', $data['name'] ?? '', 2);

        return $this->normaliseProfile(
            identifier: (string) ($data['id'] ?? ''),
            email: null, // Twitter OAuth 2.0 /users/me does not expose email
            firstName: $nameParts[0] ?? ($data['username'] ?? null),
            lastName: $nameParts[1] ?? null,
            displayName: $data['username'] ?? null,
            adapterId: $adapterId
        );
    }

    // ─── Shared helpers ───────────────────────────────────────────────────────

    /**
     * Standard OAuth 2.0 code → access_token exchange using client credentials in POST body.
     */
    private function exchangeOAuth2Code(
        string $tokenEndpoint,
        string $code,
        string $redirectUri,
        string $adapterId
    ): string {
        $this->curl->setHeaders(['Content-Type' => 'application/x-www-form-urlencoded']);
        $this->curl->post($tokenEndpoint, http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'client_id'     => $this->socialHelper->getAdapterConfigValue($adapterId, 'app_id'),
            'client_secret' => $this->socialHelper->getAdapterConfigValue($adapterId, 'app_secret'),
        ]));

        $data = json_decode($this->curl->getBody(), true) ?? [];

        if (empty($data['access_token'])) {
            throw new GraphQlAuthorizationException(
                __('OAuth code exchange failed for %1: %2', $adapterId, $data['error_description'] ?? $data['error'] ?? 'unknown error')
            );
        }

        return $data['access_token'];
    }

    private function httpGet(string $url): array
    {
        $this->curl->get($url);
        return json_decode($this->curl->getBody(), true) ?? [];
    }

    private function httpGetWithBearer(string $url, string $accessToken): array
    {
        $this->curl->setHeaders(['Authorization' => 'Bearer ' . $accessToken]);
        $this->curl->get($url);
        return json_decode($this->curl->getBody(), true) ?? [];
    }

    /**
     * Normalise provider-specific data into the array shape used by the social link repository.
     *
     * @return array{identifier: string, email: string, firstname: string, lastname: string, type: string, password: null}
     */
    private function normaliseProfile(
        string $identifier,
        ?string $email,
        ?string $firstName,
        ?string $lastName,
        ?string $displayName,
        string $adapterId
    ): array {
        if ($identifier === '') {
            throw new GraphQlAuthorizationException(__('Provider did not return a user identifier.'));
        }

        // Fallback email — matches the pattern used by the original Social model.
        $resolvedEmail = $email ?: ($identifier . '@' . $adapterId . '.com');

        $nameFallback = $displayName ?: $identifier;
        $nameParts    = explode(' ', $nameFallback, 2);

        return [
            'identifier' => $identifier,
            'email'      => $resolvedEmail,
            'firstname'  => $firstName ?: ($nameParts[0] ?? $identifier),
            'lastname'   => $lastName  ?: ($nameParts[1] ?? $identifier),
            'type'       => $adapterId,
            'password'   => null,
        ];
    }

    /**
     * Query the social link table to find an existing customer_id for a given social identity.
     */
    private function getCustomerIdBySocialLink(string $socialId, string $adapterId): ?int
    {
        $collection = $this->socialLinkCollectionFactory->create()
            ->addFieldToFilter('social_id', $socialId)
            ->addFieldToFilter('social_type', $adapterId)
            ->setPageSize(1);

        if ($collection->count() === 0) {
            return null;
        }

        $customerId = $collection->getFirstItem()->getCustomerId();
        return $customerId ? (int) $customerId : null;
    }

    /**
     * Generate a Magento customer bearer token without requiring a password.
     * Same internal path as CustomerTokenService but skips credential validation.
     */
    private function generateToken(int $customerId): string
    {
        $context = new CustomUserContext($customerId, UserContextInterface::USER_TYPE_CUSTOMER);
        $params  = $this->tokenManager->createUserTokenParameters();
        return $this->tokenManager->create($context, $params);
    }

    private function resolveAdapterId(string $provider): string
    {
        $id = self::PROVIDER_ID_MAP[$provider] ?? null;
        if ($id === null) {
            throw new GraphQlInputException(__('Unsupported social login provider: %1', $provider));
        }
        return $id;
    }
}
