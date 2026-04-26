<?php

namespace App\Tests;

use App\Tests\Mock\BillingClientMock;

final class SecurityControllerTest extends ApplicationWebTestCase
{
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
}
