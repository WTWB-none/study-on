<?php

namespace App\Tests;

use App\DataFixtures\CourseFixtures;
use App\Entity\Course;
use App\Entity\Lesson;
use App\Repository\CourseRepository;
use App\Repository\LessonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ApplicationWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    protected function courseRepository(): CourseRepository
    {
        return static::getContainer()->get(CourseRepository::class);
    }

    protected function lessonRepository(): LessonRepository
    {
        return static::getContainer()->get(LessonRepository::class);
    }

    protected function findCourseByName(string $name): Course
    {
        $course = $this->courseRepository()->findOneBy(['name' => $name]);
        self::assertInstanceOf(Course::class, $course);

        return $course;
    }

    protected function findLessonByName(string $name): Lesson
    {
        $lesson = $this->lessonRepository()->findOneBy(['name' => $name]);
        self::assertInstanceOf(Lesson::class, $lesson);

        return $lesson;
    }

    private function resetDatabase(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($entityManager);

        if ($metadata !== []) {
            try {
                $schemaTool->dropSchema($metadata);
            } catch (\Throwable) {
            }

            $schemaTool->createSchema($metadata);
        }

        (new CourseFixtures())->load($entityManager);
        $entityManager->clear();
    }
}
