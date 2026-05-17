<?php

namespace App\Tests;

final class TransactionControllerTest extends ApplicationWebTestCase
{
    public function testTransactionHistoryRequiresAuthentication(): void
    {
        $this->client->request('GET', '/transactions');

        $this->assertResponseRedirects('/login');
    }

    public function testTransactionHistoryShowsSeededBoundaryCases(): void
    {
        $this->loginAsUser();

        $this->client->request('GET', '/transactions');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Пополнение', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Списание', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Управление проектами: базовый курс', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Python для анализа данных', $this->client->getResponse()->getContent());
    }

    public function testTransactionHistoryFilterByCourseShowsExpiredRental(): void
    {
        $this->loginAsUser();

        $this->client->request('GET', '/transactions?course_code=python-data-analysis');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Python для анализа данных', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('99.90', $this->client->getResponse()->getContent());
        $this->assertStringNotContainsString('Управление проектами: базовый курс', $this->client->getResponse()->getContent());
    }

    public function testTransactionHistorySkipExpiredHidesExpiredRentals(): void
    {
        $this->loginAsUser();

        $this->client->request('GET', '/transactions?type=payment&skip_expired=1');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Управление проектами: базовый курс', $this->client->getResponse()->getContent());
        $this->assertStringNotContainsString('Python для анализа данных', $this->client->getResponse()->getContent());
    }

    public function testTransactionHistoryShowsNoOperationsForCourseWithoutPurchases(): void
    {
        $this->loginAsUser();

        $this->client->request('GET', '/transactions?course_code=sql-for-product-managers');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Операций не найдено.', $this->client->getResponse()->getContent());
    }
}
