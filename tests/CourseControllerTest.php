<?php

namespace App\Tests;

class CourseControllerTest extends ApplicationWebTestCase
{
    public function testIndexReturnsSuccessfulResponseAndShowsAllFixtureCourses(): void
    {
        $crawler = $this->client->request('GET', '/courses');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Курсы');
        $this->assertCount(4, $this->courseRepository()->findAll());
        $this->assertSame(4, $crawler->filter('.card')->count());
        $this->assertSelectorTextContains('.card-title', 'Python для анализа данных');
    }

    public function testNewPageReturnsSuccessfulResponse(): void
    {
        $this->client->request('GET', '/courses/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Новый курс');
    }

    public function testShowReturnsSuccessfulResponseAndShowsExpectedLessonsCount(): void
    {
        $course = $this->findCourseByName('Python для анализа данных');
        $crawler = $this->client->request('GET', sprintf('/courses/%d', $course->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Python для анализа данных');
        $this->assertSame(4, $crawler->filter('.list-group-item-action')->count());
    }

    public function testShowReturns404ForUnknownCourse(): void
    {
        $this->client->request('GET', '/courses/999999');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testEditPageReturnsSuccessfulResponse(): void
    {
        $course = $this->findCourseByName('Python для анализа данных');
        $this->client->request('GET', sprintf('/courses/%d/edit', $course->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Редактирование курса');
    }

    public function testEditPageReturns404ForUnknownCourse(): void
    {
        $this->client->request('GET', '/courses/999999/edit');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCreateCourseShowsValidationErrorsForInvalidData(): void
    {
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

    public function testCreateCourseShowsValidationErrorForDuplicateSymbolicCode(): void
    {
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

    public function testCreateCourseFromIndexPersistsValidDataAndRedirects(): void
    {
        $this->client->request('GET', '/courses');
        $this->client->clickLink('Добавить курс');
        $this->client->submitForm('Создать курс', [
            'course[symbolic_code]' => 'php-testing-for-symfony',
            'course[name]' => 'Тестирование Symfony приложений',
            'course[description]' => 'Курс про функциональные и интеграционные тесты.',
        ]);

        $this->assertResponseRedirects('/courses');
        $this->client->followRedirect();

        $this->assertCount(5, $this->courseRepository()->findAll());
        $this->assertStringContainsString('Тестирование Symfony приложений', $this->client->getResponse()->getContent());
    }

    public function testEditCourseShowsValidationErrorsForInvalidData(): void
    {
        $course = $this->findCourseByName('Python для анализа данных');
        $this->client->request('GET', sprintf('/courses/%d', $course->getId()));
        $this->client->clickLink('Редактировать');
        $this->client->submitForm('Сохранить', [
            'course[symbolic_code]' => '',
            'course[name]' => '',
            'course[description]' => '',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('Укажите символьный код курса.', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Укажите название курса.', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Укажите описание курса.', $this->client->getResponse()->getContent());
    }

    public function testEditCourseUpdatesEntityAndRedirectsToIndex(): void
    {
        $course = $this->findCourseByName('Python для анализа данных');
        $this->client->request('GET', sprintf('/courses/%d', $course->getId()));
        $this->client->clickLink('Редактировать');
        $this->client->submitForm('Сохранить', [
            'course[symbolic_code]' => 'python-data-analysis-updated',
            'course[name]' => 'Python для анализа данных 2.0',
            'course[description]' => 'Обновленное описание курса.',
        ]);

        $this->assertResponseRedirects('/courses');
        $updatedCourse = $this->courseRepository()->find($course->getId());

        self::assertNotNull($updatedCourse);
        self::assertSame('python-data-analysis-updated', $updatedCourse->getSymbolicCode());
        self::assertSame('Python для анализа данных 2.0', $updatedCourse->getName());
    }

    public function testDeleteCourseRemovesEntityAndRedirectsToIndex(): void
    {
        $course = $this->findCourseByName('Основы UX-редактуры');
        $this->client->request('GET', sprintf('/courses/%d/edit', $course->getId()));
        $this->client->submitForm('Удалить курс');

        $this->assertResponseRedirects('/courses');
        $this->client->followRedirect();

        $this->assertCount(3, $this->courseRepository()->findAll());
        $this->assertNull($this->courseRepository()->find($course->getId()));
    }

    public function testDeleteUnknownCourseReturns404(): void
    {
        $this->client->request('POST', '/courses/999999', [
            '_token' => 'invalid',
        ]);

        $this->assertResponseStatusCodeSame(404);
    }
}
