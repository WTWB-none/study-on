<?php

namespace App\Tests;

use App\Tests\Mock\BillingClientMock;

final class CourseControllerTest extends ApplicationWebTestCase
{
    public function testAnonymousUserCanSeeCourseListAndCoursePage(): void
    {
        $course = $this->findCourseByName('Python для анализа данных');

        $indexCrawler = $this->client->request('GET', '/courses');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Курсы');
        $this->assertSame(4, $indexCrawler->filter('.card')->count());
        $this->assertStringNotContainsString('Аренда · 99.90', $this->client->getResponse()->getContent());
        $this->assertStringNotContainsString('Покупка · 159.00', $this->client->getResponse()->getContent());
        $this->assertStringNotContainsString('Добавить курс', $this->client->getResponse()->getContent());

        $this->client->request('GET', sprintf('/courses/%d', $course->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Python для анализа данных');
        $this->assertStringContainsString('Аренда за 99.90', $this->client->getResponse()->getContent());
        $this->assertStringNotContainsString('Редактировать', $this->client->getResponse()->getContent());
        $this->assertStringNotContainsString('Добавить урок', $this->client->getResponse()->getContent());
        $this->assertStringNotContainsString('Удалить курс', $this->client->getResponse()->getContent());
    }

    public function testAnonymousUserIsRedirectedFromAdminCourseRoutes(): void
    {
        $course = $this->findCourseByName('Python для анализа данных');

        $this->client->request('GET', '/courses/new');
        $this->assertResponseRedirects('/login');

        $this->client->request('GET', sprintf('/courses/%d/edit', $course->getId()));
        $this->assertResponseRedirects('/login');

        $this->client->request('POST', sprintf('/courses/%d', $course->getId()));
        $this->assertResponseRedirects('/login');
    }

    public function testRegularUserCannotSeeAdminCourseActionsAndGetsForbiddenByDirectLinks(): void
    {
        $course = $this->findCourseByName('Python для анализа данных');
        $this->loginAsUser();

        $this->client->request('GET', '/courses');
        $this->assertStringContainsString('Аренда · 99.90', $this->client->getResponse()->getContent());
        $this->assertStringNotContainsString('Добавить курс', $this->client->getResponse()->getContent());

        $this->client->request('GET', sprintf('/courses/%d', $course->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Арендовать', $this->client->getResponse()->getContent());
        $this->assertStringNotContainsString('Редактировать', $this->client->getResponse()->getContent());
        $this->assertStringNotContainsString('Добавить урок', $this->client->getResponse()->getContent());
        $this->assertStringNotContainsString('Удалить курс', $this->client->getResponse()->getContent());

        $this->client->request('GET', '/courses/new');
        $this->assertResponseStatusCodeSame(403);

        $this->client->request('GET', sprintf('/courses/%d/edit', $course->getId()));
        $this->assertResponseStatusCodeSame(403);

        $this->client->request('POST', sprintf('/courses/%d', $course->getId()));
        $this->assertResponseStatusCodeSame(403);
    }

    public function testUserCanPayForCourseAndSeeActiveAccessStatus(): void
    {
        $course = $this->findCourseByName('Python для анализа данных');
        $this->loginAsUser();

        $this->client->request('GET', sprintf('/courses/%d/pay', $course->getId()));

        $this->assertResponseRedirects(sprintf('/courses/%d', $course->getId()));
        $this->client->followRedirect();

        $this->assertStringContainsString('Курс успешно оплачен', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Арендовано до', $this->client->getResponse()->getContent());
        $this->assertStringNotContainsString('Арендовать', $this->client->getResponse()->getContent());
    }

    public function testUserSeesBillingErrorWhenCoursePaymentFails(): void
    {
        $course = $this->findCourseByName('SQL для продакт-менеджеров');
        $this->loginAsUser();

        $this->client->request('GET', sprintf('/courses/%d', $course->getId()));
        $this->assertSelectorExists('button[disabled]');

        $this->client->request('GET', sprintf('/courses/%d/pay', $course->getId()));

        $this->assertResponseRedirects(sprintf('/courses/%d', $course->getId()));
        $this->client->followRedirect();

        $this->assertStringContainsString('На вашем счету недостаточно средств', $this->client->getResponse()->getContent());
    }

    public function testCourseListShowsBoughtAndRentedStatusesForAuthorizedUser(): void
    {
        $this->loginAsUser('super-admin@example.com');

        $this->client->request('GET', '/courses');

        $this->assertStringContainsString('Бесплатно', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Покупка · 79.00', $this->client->getResponse()->getContent());
    }

    public function testAdminCanOpenCourseCreationPage(): void
    {
        $this->loginAsUser('super-admin@example.com');

        $this->client->request('GET', '/courses/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Новый курс');
    }

    public function testCourseEditingIsAvailableOnlyForSuperAdmin(): void
    {
        $course = $this->findCourseByName('Python для анализа данных');

        $this->loginAsUser();
        $this->client->request('GET', sprintf('/courses/%d/edit', $course->getId()));
        $this->assertResponseStatusCodeSame(403);

        $this->loginAsUser('super-admin@example.com');
        $this->client->request('GET', sprintf('/courses/%d/edit', $course->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Редактирование курса', $this->client->getResponse()->getContent());
    }

    public function testAdminCreateCourseShowsValidationErrorsForInvalidData(): void
    {
        $this->loginAsUser('super-admin@example.com');

        $this->client->request('GET', '/courses/new');
        $this->client->submitForm('Создать курс', [
            'course[symbolic_code]' => '',
            'course[name]' => '',
            'course[description]' => '',
            'course[type]' => '',
            'course[price]' => '',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('Укажите символьный код курса.', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Укажите название курса.', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Укажите описание курса.', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Укажите тип курса.', $this->client->getResponse()->getContent());
        $this->assertCount(4, $this->courseRepository()->findAll());
    }

    public function testAdminCreateCourseShowsValidationErrorForDuplicateSymbolicCode(): void
    {
        $this->loginAsUser('super-admin@example.com');

        $this->client->request('GET', '/courses/new');
        $this->client->submitForm('Создать курс', [
            'course[symbolic_code]' => 'python-data-analysis',
            'course[name]' => 'Дубликат курса',
            'course[description]' => 'Описание дубликата',
            'course[type]' => 'buy',
            'course[price]' => '99.90',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('Символьный код курса должен быть уникальным.', $this->client->getResponse()->getContent());
        $this->assertCount(4, $this->courseRepository()->findAll());
    }

    public function testAdminCanCreateCourse(): void
    {
        $this->loginAsUser('super-admin@example.com');

        $this->client->request('GET', '/courses/new');
        $this->client->submitForm('Создать курс', [
            'course[symbolic_code]' => 'php-testing-for-symfony',
            'course[name]' => 'Тестирование Symfony приложений',
            'course[description]' => 'Курс про функциональные и интеграционные тесты.',
            'course[type]' => 'buy',
            'course[price]' => '199.00',
        ]);

        $this->assertResponseRedirects('/courses');
        $this->client->followRedirect();

        $this->clearEntityManager();
        $this->assertCount(5, $this->courseRepository()->findAll());
        $this->assertStringContainsString('Тестирование Symfony приложений', $this->client->getResponse()->getContent());

        self::assertSame([
            'code' => 'php-testing-for-symfony',
            'type' => 'buy',
            'price' => '199.00',
        ], BillingClientMock::courseByCode('php-testing-for-symfony'));
    }

    public function testAdminCanEditCourse(): void
    {
        $this->loginAsUser('super-admin@example.com');
        $course = $this->findCourseByName('Python для анализа данных');

        $this->client->request('GET', sprintf('/courses/%d/edit', $course->getId()));
        $this->client->submitForm('Сохранить', [
            'course[symbolic_code]' => 'python-data-analysis-updated',
            'course[name]' => 'Python для анализа данных 2.0',
            'course[description]' => 'Обновленное описание курса.',
            'course[type]' => 'buy',
            'course[price]' => '129.00',
        ]);

        $this->assertResponseRedirects('/courses');

        $this->clearEntityManager();
        $updatedCourse = $this->courseRepository()->find($course->getId());

        self::assertNotNull($updatedCourse);
        self::assertSame('python-data-analysis-updated', $updatedCourse->getSymbolicCode());
        self::assertSame('Python для анализа данных 2.0', $updatedCourse->getName());
        self::assertSame('buy', $updatedCourse->getType());
        self::assertSame(129.0, $updatedCourse->getPrice());
        self::assertNull(BillingClientMock::courseByCode('python-data-analysis'));
        self::assertSame([
            'code' => 'python-data-analysis-updated',
            'type' => 'buy',
            'price' => '129.00',
        ], BillingClientMock::courseByCode('python-data-analysis-updated'));
    }

    public function testAdminCreateCourseShowsValidationErrorForRemoteDuplicateSymbolicCode(): void
    {
        $this->loginAsUser('super-admin@example.com');
        BillingClientMock::seedCourse([
            'code' => 'remote-only-course',
            'type' => 'rent',
            'price' => '49.00',
        ]);

        $this->client->request('GET', '/courses/new');
        $this->client->submitForm('Создать курс', [
            'course[symbolic_code]' => 'remote-only-course',
            'course[name]' => 'Новый локальный курс',
            'course[description]' => 'Описание нового локального курса.',
            'course[type]' => 'rent',
            'course[price]' => '49.00',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('Символьный код курса должен быть уникальным.', $this->client->getResponse()->getContent());
        $this->assertCount(4, $this->courseRepository()->findAll());
    }

    public function testAdminEditCourseShowsValidationErrorForRemoteDuplicateSymbolicCode(): void
    {
        $this->loginAsUser('super-admin@example.com');
        $course = $this->findCourseByName('Python для анализа данных');
        BillingClientMock::seedCourse([
            'code' => 'remote-only-course',
            'type' => 'buy',
            'price' => '299.00',
        ]);

        $this->client->request('GET', sprintf('/courses/%d/edit', $course->getId()));
        $this->client->submitForm('Сохранить', [
            'course[symbolic_code]' => 'remote-only-course',
            'course[name]' => 'Python для анализа данных 2.0',
            'course[description]' => 'Обновленное описание курса.',
            'course[type]' => 'buy',
            'course[price]' => '299.00',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('Символьный код курса должен быть уникальным.', $this->client->getResponse()->getContent());

        $this->clearEntityManager();
        $notUpdatedCourse = $this->courseRepository()->find($course->getId());
        self::assertNotNull($notUpdatedCourse);
        self::assertSame('python-data-analysis', $notUpdatedCourse->getSymbolicCode());
        self::assertSame([
            'code' => 'python-data-analysis',
            'type' => 'rent',
            'price' => '99.90',
        ], BillingClientMock::courseByCode('python-data-analysis'));
    }

    public function testAdminCanDeleteCourse(): void
    {
        $this->loginAsUser('super-admin@example.com');
        $course = $this->findCourseByName('Основы UX-редактуры');

        $this->client->request('GET', sprintf('/courses/%d/edit', $course->getId()));
        $this->client->submitForm('Удалить курс');

        $this->assertResponseRedirects('/courses');

        $this->clearEntityManager();
        $this->assertCount(3, $this->courseRepository()->findAll());
        $this->assertNull($this->courseRepository()->find($course->getId()));
    }
}
