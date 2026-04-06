<?php

namespace App\Tests;

class LessonControllerTest extends ApplicationWebTestCase
{
    public function testIndexReturnsSuccessfulResponseAndShowsAllFixtureLessons(): void
    {
        $crawler = $this->client->request('GET', '/lessons');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Уроки');
        $this->assertCount(17, $this->lessonRepository()->findAll());
        $this->assertSame(17, $crawler->filter('tbody tr')->count());
    }

    public function testNewPageReturnsSuccessfulResponse(): void
    {
        $this->client->request('GET', '/lessons/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Новый урок');
    }

    public function testShowReturnsSuccessfulResponseAndDisplaysLessonContent(): void
    {
        $lesson = $this->findLessonByName('Подготовка окружения аналитика');
        $this->client->request('GET', sprintf('/lessons/%d', $lesson->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Подготовка окружения аналитика');
        $this->assertStringContainsString('настроить виртуальное окружение', $this->client->getResponse()->getContent());
    }

    public function testShowReturns404ForUnknownLesson(): void
    {
        $this->client->request('GET', '/lessons/999999');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testEditPageReturnsSuccessfulResponse(): void
    {
        $lesson = $this->findLessonByName('Подготовка окружения аналитика');
        $this->client->request('GET', sprintf('/lessons/%d/edit', $lesson->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Редактирование урока');
    }

    public function testEditPageReturns404ForUnknownLesson(): void
    {
        $this->client->request('GET', '/lessons/999999/edit');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCreateLessonFromCoursePageShowsValidationErrorsForInvalidData(): void
    {
        $course = $this->findCourseByName('Python для анализа данных');
        $this->client->request('GET', sprintf('/courses/%d', $course->getId()));
        $this->client->clickLink('Добавить урок');
        $this->client->submitForm('Создать урок', [
            'lesson[name]' => '',
            'lesson[lesson_content]' => '',
            'lesson[lesson_num]' => 0,
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('Укажите название урока.', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Укажите контент урока.', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Номер урока должен быть в диапазоне от 1 до 10000.', $this->client->getResponse()->getContent());
        $this->assertCount(17, $this->lessonRepository()->findAll());
    }

    public function testCreateLessonFromCoursePagePersistsValidDataAndRedirectsToCourse(): void
    {
        $course = $this->findCourseByName('Python для анализа данных');
        $this->client->request('GET', sprintf('/courses/%d', $course->getId()));
        $this->client->clickLink('Добавить урок');
        $this->client->submitForm('Создать урок', [
            'lesson[name]' => 'Автоматизация отчетов',
            'lesson[lesson_content]' => 'Учимся собирать ежедневные отчеты без ручной рутины.',
            'lesson[lesson_num]' => 5,
        ]);

        $this->assertResponseRedirects(sprintf('/courses/%d', $course->getId()));
        $this->client->followRedirect();

        $updatedCourse = $this->courseRepository()->find($course->getId());
        self::assertNotNull($updatedCourse);
        self::assertCount(5, $updatedCourse->getLessons());
        $this->assertStringContainsString('Автоматизация отчетов', $this->client->getResponse()->getContent());
    }

    public function testEditLessonShowsValidationErrorsForInvalidData(): void
    {
        $lesson = $this->findLessonByName('Подготовка окружения аналитика');
        $this->client->request('GET', sprintf('/lessons/%d', $lesson->getId()));
        $this->client->clickLink('Редактировать');
        $this->client->submitForm('Сохранить', [
            'lesson[name]' => '',
            'lesson[lesson_content]' => '',
            'lesson[lesson_num]' => 0,
            'lesson[course]' => (string) $lesson->getCourse()?->getId(),
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('Укажите название урока.', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Укажите контент урока.', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Номер урока должен быть в диапазоне от 1 до 10000.', $this->client->getResponse()->getContent());
    }

    public function testEditLessonUpdatesEntityAndRedirectsToShowPage(): void
    {
        $lesson = $this->findLessonByName('Подготовка окружения аналитика');
        $this->client->request('GET', sprintf('/lessons/%d', $lesson->getId()));
        $this->client->clickLink('Редактировать');
        $this->client->submitForm('Сохранить', [
            'lesson[name]' => 'Подготовка окружения аналитика Pro',
            'lesson[lesson_content]' => 'Обновленный контент урока.',
            'lesson[lesson_num]' => 10,
            'lesson[course]' => (string) $lesson->getCourse()?->getId(),
        ]);

        $this->assertResponseRedirects(sprintf('/lessons/%d', $lesson->getId()));
        $updatedLesson = $this->lessonRepository()->find($lesson->getId());

        self::assertNotNull($updatedLesson);
        self::assertSame('Подготовка окружения аналитика Pro', $updatedLesson->getName());
        self::assertSame(10, $updatedLesson->getLessonNum());
    }

    public function testDeleteLessonRemovesEntityAndRedirectsToCourse(): void
    {
        $lesson = $this->findLessonByName('Что делает текст в интерфейсе полезным');
        $courseId = $lesson->getCourse()?->getId();

        self::assertNotNull($courseId);

        $this->client->request('GET', sprintf('/lessons/%d', $lesson->getId()));
        $this->client->submitForm('Удалить урок');

        $this->assertResponseRedirects(sprintf('/courses/%d', $courseId));
        $this->client->followRedirect();

        $this->assertCount(16, $this->lessonRepository()->findAll());
        $this->assertNull($this->lessonRepository()->find($lesson->getId()));
        $this->assertSame(4, $this->client->getCrawler()->filter('.list-group-item-action')->count());
    }

    public function testDeleteUnknownLessonReturns404(): void
    {
        $this->client->request('POST', '/lessons/999999', [
            '_token' => 'invalid',
        ]);

        $this->assertResponseStatusCodeSame(404);
    }
}
