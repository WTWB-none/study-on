<?php

namespace App\Tests;

final class LessonControllerTest extends ApplicationWebTestCase
{
    public function testAnonymousUserCanSeeLessonListButCannotOpenLessonContent(): void
    {
        $lesson = $this->findLessonByName('Подготовка окружения аналитика');

        $this->client->request('GET', '/lessons');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Уроки');
        $this->assertStringNotContainsString('Добавить урок', $this->client->getResponse()->getContent());
        $this->assertStringNotContainsString('Изменить', $this->client->getResponse()->getContent());

        $this->client->request('GET', sprintf('/lessons/%d', $lesson->getId()));

        $this->assertResponseRedirects('/login');
    }

    public function testRegularUserCanOpenLessonContentButCannotManageLessons(): void
    {
        $lesson = $this->findLessonByName('Подготовка окружения аналитика');
        $this->loginAsUser();

        $this->client->request('GET', sprintf('/lessons/%d', $lesson->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Подготовка окружения аналитика');
        $this->assertStringContainsString('настроить виртуальное окружение', $this->client->getResponse()->getContent());
        $this->assertStringNotContainsString('Редактировать', $this->client->getResponse()->getContent());
        $this->assertStringNotContainsString('Удалить урок', $this->client->getResponse()->getContent());

        $this->client->request('GET', '/lessons/new');
        $this->assertResponseStatusCodeSame(403);

        $this->client->request('GET', sprintf('/lessons/%d/edit', $lesson->getId()));
        $this->assertResponseStatusCodeSame(403);

        $this->client->request('POST', sprintf('/lessons/%d', $lesson->getId()));
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCanOpenLessonCreationPage(): void
    {
        $this->loginAsUser('super-admin@example.com');

        $this->client->request('GET', '/lessons/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Новый урок');
    }

    public function testAdminCreateLessonShowsValidationErrorsForInvalidData(): void
    {
        $course = $this->findCourseByName('Python для анализа данных');
        $this->loginAsUser('super-admin@example.com');

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

    public function testAdminCanCreateLesson(): void
    {
        $course = $this->findCourseByName('Python для анализа данных');
        $this->loginAsUser('super-admin@example.com');

        $this->client->request('GET', sprintf('/courses/%d', $course->getId()));
        $this->client->clickLink('Добавить урок');
        $this->client->submitForm('Создать урок', [
            'lesson[name]' => 'Автоматизация отчетов',
            'lesson[lesson_content]' => 'Учимся собирать ежедневные отчеты без ручной рутины.',
            'lesson[lesson_num]' => 5,
        ]);

        $this->assertResponseRedirects(sprintf('/courses/%d', $course->getId()));
        $this->client->followRedirect();

        $this->clearEntityManager();
        $updatedCourse = $this->courseRepository()->find($course->getId());
        self::assertNotNull($updatedCourse);
        self::assertCount(5, $updatedCourse->getLessons());
        $this->assertStringContainsString('Автоматизация отчетов', $this->client->getResponse()->getContent());
    }

    public function testAdminCanEditLesson(): void
    {
        $lesson = $this->findLessonByName('Подготовка окружения аналитика');
        $this->loginAsUser('super-admin@example.com');

        $this->client->request('GET', sprintf('/lessons/%d', $lesson->getId()));
        $this->client->clickLink('Редактировать');
        $this->client->submitForm('Сохранить', [
            'lesson[name]' => 'Подготовка окружения аналитика Pro',
            'lesson[lesson_content]' => 'Обновленный контент урока.',
            'lesson[lesson_num]' => 10,
            'lesson[course]' => (string) $lesson->getCourse()?->getId(),
        ]);

        $this->assertResponseRedirects(sprintf('/lessons/%d', $lesson->getId()));

        $this->clearEntityManager();
        $updatedLesson = $this->lessonRepository()->find($lesson->getId());

        self::assertNotNull($updatedLesson);
        self::assertSame('Подготовка окружения аналитика Pro', $updatedLesson->getName());
        self::assertSame(10, $updatedLesson->getLessonNum());
    }

    public function testAdminCanDeleteLesson(): void
    {
        $lesson = $this->findLessonByName('Что делает текст в интерфейсе полезным');
        $courseId = $lesson->getCourse()?->getId();
        $this->loginAsUser('super-admin@example.com');

        self::assertNotNull($courseId);

        $this->client->request('GET', sprintf('/lessons/%d', $lesson->getId()));
        $this->client->submitForm('Удалить урок');

        $this->assertResponseRedirects(sprintf('/courses/%d', $courseId));
        $this->client->followRedirect();

        $this->clearEntityManager();
        $this->assertCount(16, $this->lessonRepository()->findAll());
        $this->assertNull($this->lessonRepository()->find($lesson->getId()));
        $this->assertSame(4, $this->client->getCrawler()->filter('.list-group-item-action')->count());
    }
}
