<?php

namespace App\Tests;

final class CourseControllerTest extends ApplicationWebTestCase
{
    public function testAnonymousUserCanSeeCourseListAndCoursePage(): void
    {
        $course = $this->findCourseByName('Python для анализа данных');

        $indexCrawler = $this->client->request('GET', '/courses');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Курсы');
        $this->assertSame(4, $indexCrawler->filter('.card')->count());
        $this->assertStringNotContainsString('Добавить курс', $this->client->getResponse()->getContent());

        $this->client->request('GET', sprintf('/courses/%d', $course->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Python для анализа данных');
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
        $this->assertStringNotContainsString('Добавить курс', $this->client->getResponse()->getContent());

        $this->client->request('GET', sprintf('/courses/%d', $course->getId()));
        $this->assertResponseIsSuccessful();
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

    public function testAdminCanOpenCourseCreationPage(): void
    {
        $this->loginAsUser('super-admin@example.com');

        $this->client->request('GET', '/courses/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Новый курс');
    }

    public function testAdminCreateCourseShowsValidationErrorsForInvalidData(): void
    {
        $this->loginAsUser('super-admin@example.com');

        $this->client->request('GET', '/courses/new');
        $this->client->submitForm('Создать курс', [
            'course[symbolic_code]' => '',
            'course[name]' => '',
            'course[description]' => '',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('Укажите символьный код курса.', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Укажите название курса.', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Укажите описание курса.', $this->client->getResponse()->getContent());
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
        ]);

        $this->assertResponseRedirects('/courses');
        $this->client->followRedirect();

        $this->clearEntityManager();
        $this->assertCount(5, $this->courseRepository()->findAll());
        $this->assertStringContainsString('Тестирование Symfony приложений', $this->client->getResponse()->getContent());
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
        ]);

        $this->assertResponseRedirects('/courses');

        $this->clearEntityManager();
        $updatedCourse = $this->courseRepository()->find($course->getId());

        self::assertNotNull($updatedCourse);
        self::assertSame('python-data-analysis-updated', $updatedCourse->getSymbolicCode());
        self::assertSame('Python для анализа данных 2.0', $updatedCourse->getName());
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
