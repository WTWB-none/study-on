<?php

namespace App\Tests;

use App\Tests\Mock\BillingClientMock;

final class RegisterControllerTest extends ApplicationWebTestCase
{
    public function testRegisterPageIsSuccessfulForGuest(): void
    {
        $this->client->request('GET', '/register');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Регистрация');
    }

    public function testAuthenticatedUserIsRedirectedFromRegisterToProfile(): void
    {
        $this->loginAsUser();

        $this->client->request('GET', '/register');

        $this->assertResponseRedirects('/profile');
    }

    public function testRegisterShowsValidationErrorsForShortPasswordAndMismatch(): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Зарегистрироваться', [
            'register_user[email]' => 'new-user@example.com',
            'register_user[password][first]' => '123',
            'register_user[password][second]' => '123',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('Пароль должен быть не короче 6 символов.', $this->client->getResponse()->getContent());

        $this->client->request('GET', '/register');
        $this->client->submitForm('Зарегистрироваться', [
            'register_user[email]' => 'new-user@example.com',
            'register_user[password][first]' => '123',
            'register_user[password][second]' => '456',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('Пароли должны совпадать.', $this->client->getResponse()->getContent());
    }

    public function testRegisterShowsBillingDuplicateEmailError(): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Зарегистрироваться', [
            'register_user[email]' => 'user@example.com',
            'register_user[password][first]' => 'secret123',
            'register_user[password][second]' => 'secret123',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('User with this email already exists.', $this->client->getResponse()->getContent());
    }

    public function testRegisterShowsServiceUnavailableMessageWhenBillingIsDown(): void
    {
        BillingClientMock::makeUnavailable('/api/v1/register');

        $this->client->request('GET', '/register');
        $this->client->submitForm('Зарегистрироваться', [
            'register_user[email]' => 'new-user@example.com',
            'register_user[password][first]' => 'secret123',
            'register_user[password][second]' => 'secret123',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Сервис временно недоступен. Попробуйте зарегистрироваться позднее', $this->client->getResponse()->getContent());
    }

    public function testRegisterCreatesAccountAndAuthenticatesUser(): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Зарегистрироваться', [
            'register_user[email]' => 'new-user@example.com',
            'register_user[password][first]' => 'secret123',
            'register_user[password][second]' => 'secret123',
        ]);

        $this->assertResponseRedirects('/courses');

        $this->client->request('GET', '/profile');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('new-user@example.com', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Пользователь', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('0.00', $this->client->getResponse()->getContent());
    }
}
