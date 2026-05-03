<?php

namespace App\Tests;

use App\Service\BillingClient;
use App\Tests\Mock\BillingClientMock;

final class SecurityControllerTest extends ApplicationWebTestCase
{
    private const REMEMBER_ME_COOKIE = 'REMEMBERME';

    public function testLoginPageIsSuccessful(): void
    {
        $this->client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Вход');
        $this->assertStringContainsString('Зарегистрироваться', $this->client->getResponse()->getContent());
    }

    public function testUserCanLoginAndSeeProfileDataFromBilling(): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Войти', [
            'email' => 'user@example.com',
            'password' => 'user123',
        ]);

        $this->assertResponseRedirects('/courses');

        $this->client->request('GET', '/profile');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('user@example.com', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Пользователь', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('120.50', $this->client->getResponse()->getContent());
    }

    public function testLoginShowsBillingErrorForInvalidCredentials(): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Войти', [
            'email' => 'user@example.com',
            'password' => 'wrong-password',
        ]);

        $this->assertResponseRedirects('/login');
        $this->client->followRedirect();
        $this->assertStringContainsString('Invalid credentials.', $this->client->getResponse()->getContent());
    }

    public function testLoginShowsServiceUnavailableMessageWhenBillingIsDown(): void
    {
        BillingClientMock::makeUnavailable('/api/v1/auth');

        $this->client->request('GET', '/login');
        $this->client->submitForm('Войти', [
            'email' => 'user@example.com',
            'password' => 'user123',
        ]);

        $this->assertResponseRedirects('/login');
        $this->client->followRedirect();
        $this->assertStringContainsString('Сервис временно недоступен. Попробуйте авторизоваться позднее', $this->client->getResponse()->getContent());
    }

    public function testProfileRequiresAuthentication(): void
    {
        $this->client->request('GET', '/profile');

        $this->assertResponseRedirects('/login');
    }

    public function testRememberMeRestoresBillingTokenAndProfileData(): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Войти', [
            'email' => 'user@example.com',
            'password' => 'user123',
            '_remember_me' => 'on',
        ]);

        $this->assertResponseRedirects('/courses');

        $rememberMeCookie = $this->client->getCookieJar()->get(self::REMEMBER_ME_COOKIE);
        self::assertNotNull($rememberMeCookie);

        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set(BillingClient::class, new BillingClientMock(''));
        $this->client->getCookieJar()->set($rememberMeCookie);

        $this->client->request('GET', '/profile');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('user@example.com', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('120.50', $this->client->getResponse()->getContent());
    }

    public function testExpiredBillingTokenIsRefreshedDuringUserRefresh(): void
    {
        $this->client->loginUser(
            (new \App\Security\User())
                ->setEmail('user@example.com')
                ->setApiToken(BillingClientMock::expiredTokenFor('user@example.com'))
                ->setRefreshToken(BillingClientMock::refreshTokenFor('user@example.com'))
                ->setRoles(BillingClientMock::rolesFor('user@example.com'))
                ->setBalance(BillingClientMock::balanceFor('user@example.com'))
        );

        $this->client->request('GET', '/profile');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('user@example.com', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('120.50', $this->client->getResponse()->getContent());
    }

    public function testProfileShowsTransactionHistory(): void
    {
        $course = $this->findCourseByName('Python для анализа данных');
        $this->loginAsUser();

        $this->client->request('GET', sprintf('/courses/%d/pay', $course->getId()));
        $this->client->followRedirect();

        $this->client->request('GET', '/profile');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('История транзакций', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('20.60', $this->client->getResponse()->getContent());

        $this->client->clickLink('История транзакций');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Пополнение', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Списание', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Python для анализа данных', $this->client->getResponse()->getContent());
    }
}
