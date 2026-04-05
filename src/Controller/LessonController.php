<?php

namespace App\Controller;

use App\Entity\Course;
use App\Entity\Lesson;
use App\Form\LessonType;
use App\Repository\CourseRepository;
use App\Repository\LessonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/lessons')]
final class LessonController extends AbstractController
{
    #[Route(name: 'app_lesson_index', methods: ['GET'])]
    public function index(LessonRepository $lessonRepository): Response
    {
        return $this->render('lesson/index.html.twig', [
            'lessons' => $lessonRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_lesson_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, CourseRepository $courseRepository, LessonRepository $lessonRepository): Response
    {
        $lesson = new Lesson();
        $courseId = $request->query->get('course_id');

        if ($courseId !== null) {
            /** @var Course|null $course */
            $course = $courseRepository->find($courseId);

            if ($course !== null) {
                $lesson->setCourse($course);
            }
        }

        $form = $this->createForm(LessonType::class, $lesson);
        $form->handleRequest($request);

        if ($lesson->getCourse() === null && $courseId !== null) {
            /** @var Course|null $course */
            $course = $courseRepository->find($courseId);

            if ($course !== null) {
                $lesson->setCourse($course);
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($lesson);
            $entityManager->flush();

            return $this->redirectToRoute('app_course_show', ['id' => $lesson->getCourse()?->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('lesson/new.html.twig', [
            'lesson' => $lesson,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_lesson_show', methods: ['GET'])]
    public function show(Lesson $lesson): Response
    {
        return $this->render('lesson/show.html.twig', [
            'lesson' => $lesson,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_lesson_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Lesson $lesson, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(LessonType::class, $lesson);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_lesson_show', ['id' => $lesson->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('lesson/edit.html.twig', [
            'lesson' => $lesson,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_lesson_delete', methods: ['POST'])]
    public function delete(Request $request, Lesson $lesson, EntityManagerInterface $entityManager): Response
    {
        $courseId = $lesson->getCourse()?->getId();

        if ($this->isCsrfTokenValid('delete'.$lesson->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($lesson);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_course_show', ['id' => $courseId], Response::HTTP_SEE_OTHER);
    }
}
