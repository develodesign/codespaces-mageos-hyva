<?php

declare(strict_types=1);

namespace Develo\SocialLoginGraphQl\Test\Unit\Model;

use Develo\SocialLoginGraphQl\Model\SocialAuthService;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Model\EmailNotificationInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Math\Random;
use Magento\Integration\Api\TokenManager;
use Magento\Integration\Model\CustomUserContext;
use Magento\Integration\Model\UserToken\UserTokenParameters;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Techyouknow\SocialLogin\Api\Data\SocialNetworkCustomer;
use Techyouknow\SocialLogin\Api\Data\SocialNetworkCustomerFactory;
use Techyouknow\SocialLogin\Helper\Social as SocialHelper;
use Techyouknow\SocialLogin\Model\Repository\SocialLoginCustomerRepository;
use Techyouknow\SocialLogin\Model\ResourceModel\SocialLoginCustomer\Collection as SocialLinkCollection;
use Techyouknow\SocialLogin\Model\ResourceModel\SocialLoginCustomer\CollectionFactory as SocialLinkCollectionFactory;

class SocialAuthServiceTest extends TestCase
{
    private SocialLoginCustomerRepository|MockObject $socialLinkRepository;
    private SocialLinkCollectionFactory|MockObject $socialLinkCollectionFactory;
    private SocialNetworkCustomerFactory|MockObject $socialNetworkCustomerFactory;
    private CustomerRepositoryInterface|MockObject $customerRepository;
    private CustomerInterfaceFactory|MockObject $customerFactory;
    private AccountManagementInterface|MockObject $accountManagement;
    private EmailNotificationInterface|MockObject $emailNotification;
    private TokenManager|MockObject $tokenManager;
    private SocialHelper|MockObject $socialHelper;
    private StoreManagerInterface|MockObject $storeManager;
    private Random|MockObject $random;
    private Curl|MockObject $curl;

    private SocialAuthService $service;

    protected function setUp(): void
    {
        $this->socialLinkRepository        = $this->createMock(SocialLoginCustomerRepository::class);
        $this->socialLinkCollectionFactory = $this->createMock(SocialLinkCollectionFactory::class);
        $this->socialNetworkCustomerFactory = $this->createMock(SocialNetworkCustomerFactory::class);
        $this->customerRepository          = $this->createMock(CustomerRepositoryInterface::class);
        $this->customerFactory             = $this->createMock(CustomerInterfaceFactory::class);
        $this->accountManagement = $this->getMockBuilder(AccountManagementInterface::class)
            ->addMethods(['changeResetPasswordLinkToken'])
            ->getMockForAbstractClass();
        $this->emailNotification           = $this->createMock(EmailNotificationInterface::class);
        $this->tokenManager                = $this->createMock(TokenManager::class);
        $this->socialHelper                = $this->createMock(SocialHelper::class);
        $this->storeManager               = $this->createMock(StoreManagerInterface::class);
        $this->random                      = $this->createMock(Random::class);
        $this->curl                        = $this->createMock(Curl::class);

        $this->service = new SocialAuthService(
            $this->socialLinkRepository,
            $this->socialLinkCollectionFactory,
            $this->socialNetworkCustomerFactory,
            $this->customerRepository,
            $this->customerFactory,
            $this->accountManagement,
            $this->emailNotification,
            $this->tokenManager,
            $this->socialHelper,
            $this->storeManager,
            $this->random,
            $this->curl,
        );
    }

    // ─── authenticateWithToken: provider validation ───────────────────────────

    public function testTokenFlowRejectsCodeOnlyProvider(): void
    {
        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('does not support token flow');

        $this->service->authenticateWithToken('LINKEDIN', 'any-token');
    }

    public function testTokenFlowRejectsUnknownProvider(): void
    {
        $this->expectException(GraphQlInputException::class);

        $this->service->authenticateWithToken('UNKNOWN_PROVIDER', 'any-token');
    }

    // ─── authenticateWithCode: provider validation ────────────────────────────

    public function testCodeFlowRejectsTokenOnlyProvider(): void
    {
        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Unsupported code-flow provider');

        $this->curl->method('post')->willReturn(null);
        $this->curl->method('getBody')->willReturn('{}');

        $this->service->authenticateWithCode('GOOGLE', 'any-code', 'https://example.com/callback');
    }

    // ─── Google token verification ────────────────────────────────────────────

    public function testGoogleTokenVerificationFailsOnErrorResponse(): void
    {
        $this->expectException(GraphQlAuthorizationException::class);
        $this->expectExceptionMessage('Google token validation failed');

        $this->curl->method('get')->willReturn(null);
        $this->curl->method('getBody')->willReturn(
            json_encode(['error_description' => 'Token has been expired or revoked.'])
        );

        $this->service->authenticateWithToken('GOOGLE', 'expired-token');
    }

    public function testGoogleTokenVerificationFailsOnMissingSub(): void
    {
        $this->expectException(GraphQlAuthorizationException::class);
        $this->expectExceptionMessage('did not return a user identifier');

        $this->curl->method('get')->willReturn(null);
        $this->curl->method('getBody')->willReturn(
            json_encode(['email' => 'user@example.com', 'aud' => 'client-id'])
        );
        $this->socialHelper->method('getAdapterConfigValue')->willReturn('');

        $this->service->authenticateWithToken('GOOGLE', 'token-without-sub');
    }

    public function testGoogleTokenVerificationFailsOnAudienceMismatch(): void
    {
        $this->expectException(GraphQlAuthorizationException::class);
        $this->expectExceptionMessage('audience mismatch');

        $this->curl->method('get')->willReturn(null);
        $this->curl->method('getBody')->willReturn(
            json_encode(['sub' => 'google-uid-123', 'aud' => 'wrong-client-id', 'email' => 'u@example.com'])
        );
        $this->socialHelper->method('getAdapterConfigValue')->willReturn('correct-client-id');

        $this->service->authenticateWithToken('GOOGLE', 'mismatched-token');
    }

    public function testGoogleTokenAuthenticatesExistingCustomer(): void
    {
        $customerId = 42;
        $bearerToken = 'bearer-xyz';

        $this->curl->method('get')->willReturn(null);
        $this->curl->method('getBody')->willReturn(json_encode([
            'sub'         => 'google-uid-123',
            'email'       => 'user@example.com',
            'given_name'  => 'Jane',
            'family_name' => 'Doe',
            'aud'         => 'client-id',
        ]));
        $this->socialHelper->method('getAdapterConfigValue')->willReturn('');

        $this->mockExistingSocialLink('google-uid-123', 'google', $customerId);
        $this->mockCustomerById($customerId, 'user@example.com');
        $this->mockTokenGeneration($bearerToken);

        $result = $this->service->authenticateWithToken('GOOGLE', 'valid-google-token');

        $this->assertSame($bearerToken, $result['token']);
        $this->assertFalse($result['is_new_customer']);
        $this->assertSame('user@example.com', $result['customer_email']);
    }

    // ─── find-or-create: email match (no prior social link) ──────────────────

    public function testLinksExistingCustomerFoundByEmail(): void
    {
        $customerId = 7;
        $bearerToken = 'bearer-email-match';

        $this->curl->method('get')->willReturn(null);
        $this->curl->method('getBody')->willReturn(json_encode([
            'sub'         => 'google-new-uid',
            'email'       => 'existing@example.com',
            'given_name'  => 'Existing',
            'family_name' => 'User',
        ]));
        $this->socialHelper->method('getAdapterConfigValue')->willReturn('');

        // No social link exists yet.
        $this->mockNoSocialLink();
        $this->mockStore();
        $this->socialLinkRepository->method('socialNetworkCustomerExists')->willReturn(false);
        $this->mockCustomerByEmail('existing@example.com', $customerId);
        $this->mockSocialLinkCreation();
        $this->mockTokenGeneration($bearerToken);

        $result = $this->service->authenticateWithToken('GOOGLE', 'valid-token');

        $this->assertSame($bearerToken, $result['token']);
        $this->assertFalse($result['is_new_customer']);
        $this->assertSame('existing@example.com', $result['customer_email']);
    }

    // ─── find-or-create: new customer created ────────────────────────────────

    public function testCreatesNewCustomerWhenNoMatchFound(): void
    {
        $newCustomerId = 99;
        $bearerToken   = 'bearer-new-customer';

        $this->curl->method('get')->willReturn(null);
        $this->curl->method('getBody')->willReturn(json_encode([
            'sub'         => 'google-brand-new',
            'email'       => 'newuser@example.com',
            'given_name'  => 'New',
            'family_name' => 'User',
        ]));
        $this->socialHelper->method('getAdapterConfigValue')->willReturn('');

        $this->mockNoSocialLink();

        // No existing customer with this email.
        $this->customerRepository
            ->method('get')
            ->willThrowException(new NoSuchEntityException());

        $this->mockNewCustomerCreation($newCustomerId, 'newuser@example.com');
        $this->mockSocialLinkCreation();
        $this->random->method('getUniqueHash')->willReturn('reset-token');
        $this->emailNotification->method('newAccount')->willReturn(null);
        $this->mockTokenGeneration($bearerToken);

        $result = $this->service->authenticateWithToken('GOOGLE', 'valid-token');

        $this->assertSame($bearerToken, $result['token']);
        $this->assertTrue($result['is_new_customer']);
        $this->assertSame('newuser@example.com', $result['customer_email']);
    }

    // ─── GitHub token flow ────────────────────────────────────────────────────

    public function testGithubTokenFailsWhenApiReturnsError(): void
    {
        $this->expectException(GraphQlAuthorizationException::class);
        $this->expectExceptionMessage('GitHub token validation failed');

        $this->curl->method('setHeaders')->willReturn(null);
        $this->curl->method('get')->willReturn(null);
        $this->curl->method('getBody')->willReturn(
            json_encode(['message' => 'Bad credentials'])
        );

        $this->service->authenticateWithToken('GITHUB', 'bad-token');
    }

    // ─── Apple token flow ─────────────────────────────────────────────────────

    public function testAppleTokenFailsOnInvalidFormat(): void
    {
        $this->expectException(GraphQlAuthorizationException::class);
        $this->expectExceptionMessage('Invalid Apple ID token format');

        $this->service->authenticateWithToken('APPLE', 'not.a.jwt.with.too.many.parts.here.invalid');
    }

    public function testAppleTokenFailsOnWrongIssuer(): void
    {
        $this->expectException(GraphQlAuthorizationException::class);
        $this->expectExceptionMessage('issuer is invalid');

        $payload = base64_encode(json_encode([
            'iss' => 'https://evil.com',
            'sub' => 'apple-uid',
            'aud' => 'client',
            'exp' => time() + 3600,
        ]));

        $this->service->authenticateWithToken('APPLE', "header.$payload.sig");
    }

    public function testAppleTokenFailsWhenExpired(): void
    {
        $this->expectException(GraphQlAuthorizationException::class);
        $this->expectExceptionMessage('expired');

        $payload = base64_encode(json_encode([
            'iss' => 'https://appleid.apple.com',
            'sub' => 'apple-uid',
            'aud' => 'client',
            'exp' => time() - 100,
        ]));
        $this->socialHelper->method('getAdapterConfigValue')->willReturn('');

        $this->service->authenticateWithToken('APPLE', "header.$payload.sig");
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function mockExistingSocialLink(string $socialId, string $type, int $customerId): void
    {
        $item = $this->createMock(SocialNetworkCustomer::class);
        $item->method('getCustomerId')->willReturn($customerId);

        $collection = $this->createMock(SocialLinkCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('count')->willReturn(1);
        $collection->method('getFirstItem')->willReturn($item);

        $this->socialLinkCollectionFactory->method('create')->willReturn($collection);
    }

    private function mockNoSocialLink(): void
    {
        $collection = $this->createMock(SocialLinkCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('count')->willReturn(0);

        $this->socialLinkCollectionFactory->method('create')->willReturn($collection);
    }

    private function mockCustomerById(int $customerId, string $email): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn($customerId);
        $customer->method('getEmail')->willReturn($email);

        $this->customerRepository->method('getById')->willReturn($customer);
    }

    private function mockCustomerByEmail(string $email, int $customerId): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn($customerId);
        $customer->method('getEmail')->willReturn($email);

        $this->customerRepository->method('get')->willReturn($customer);
    }

    private function mockStore(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $store->method('getWebsiteId')->willReturn(1);
        $store->method('getName')->willReturn('Default Store');
        $this->storeManager->method('getStore')->willReturn($store);
    }

    private function mockNewCustomerCreation(int $newId, string $email): void
    {
        $this->mockStore();

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('setFirstname')->willReturnSelf();
        $customer->method('setLastname')->willReturnSelf();
        $customer->method('setEmail')->willReturnSelf();
        $customer->method('setStoreId')->willReturnSelf();
        $customer->method('setWebsiteId')->willReturnSelf();
        $customer->method('setCreatedIn')->willReturnSelf();
        $customer->method('getId')->willReturn($newId);
        $customer->method('getEmail')->willReturn($email);

        $this->customerFactory->method('create')->willReturn($customer);
        $this->customerRepository->method('save')->willReturn($customer);
    }

    private function mockSocialLinkCreation(): void
    {
        $link = $this->createMock(SocialNetworkCustomer::class);
        $link->method('setSocialId')->willReturnSelf();
        $link->method('setCustomerId')->willReturnSelf();
        $link->method('setSocialType')->willReturnSelf();

        $this->socialNetworkCustomerFactory->method('create')->willReturn($link);
        $this->socialLinkRepository->method('save')->willReturn($link);
    }

    private function mockTokenGeneration(string $token): void
    {
        $params = $this->createMock(UserTokenParameters::class);
        $this->tokenManager->method('createUserTokenParameters')->willReturn($params);
        $this->tokenManager->method('create')->willReturn($token);
    }
}
