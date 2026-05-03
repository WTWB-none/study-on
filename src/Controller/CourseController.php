<?php

namespace App\Controller;

use App\Entity\Course;
use App\Form\CourseType;
use App\Repository\CourseRepository;
use App\Security\User;
use App\Service\BillingCourseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/courses')]
final class CourseController extends AbstractController
{
    #[Route(name: 'app_course_index', methods: ['GET'])]
    public function index(CourseRepository $courseRepository, BillingCourseService $billingCourseService): Response
    {
        $user = $this->getUser();
        $billingUnavailable = false;
        $billingCourses = [];
        $courseAccessMap = [];

        try {
            $billingCourses = $billingCourseService->getCourseCatalogIndexed();

            if ($user instanceof User && !$this->isGranted('ROLE_SUPER_ADMIN')) {
                $courseAccessMap = $billingCourseService->getActiveCourseAccessMap($user);
            }
        } catch (\App\Exception\BillingUnavailableException) {
            $billingUnavailable = true;
        }

        return $this->render('course/index.html.twig', [
            'courses' => $courseRepository->findAll(),
            'billing_courses' => $billingCourses,
            'course_access_map' => $courseAccessMap,
            'billing_unavailable' => $billingUnavailable,
        ]);
    }

    #[Route('/new', name: 'app_course_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $course = new Course();
        $form = $this->createForm(CourseType::class, $course);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($course);
            $entityManager->flush();

            return $this->redirectToRoute('app_course_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('course/new.html.twig', [
            'course' => $course,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_course_show', methods: ['GET'])]
    public function show(Course $course, BillingCourseService $billingCourseService): Response
    {
        $user = $this->getUser();
        $billingUnavailable = false;
        $billingCourse = [
            'code' => $course->getSymbolicCode() ?? '',
            'type' => BillingCourseService::COURSE_TYPE_FREE,
            'price' => null,
        ];
        $courseAccess = null;
        $canAffordCourse = false;

        try {
            $billingCourse = $billingCourseService->getCourse($course->getSymbolicCode() ?? '') ?? $billingCourse;

            if ($user instanceof User && !$this->isGranted('ROLE_SUPER_ADMIN')) {
                $courseAccess = $billingCourseService->getCourseAccessInfo($user, $course->getSymbolicCode() ?? '', $billingCourse);
                $canAffordCourse = $billingCourse['price'] !== null && (float) $billingCourse['price'] <= $user->getBalance();
            }
        } catch (\App\Exception\BillingUnavailableException) {
            $billingUnavailable = true;
        }

        return $this->render('course/show.html.twig', [
            'course' => $course,
            'billing_course' => $billingCourse,
            'course_access' => $courseAccess,
            'can_afford_course' => $canAffordCourse,
            'billing_unavailable' => $billingUnavailable,
        ]);
    }

    #[Route('/{id}/pay', name: 'app_course_pay', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function pay(Course $course, BillingCourseService $billingCourseService): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $billingCourse = $billingCourseService->getCourse($course->getSymbolicCode() ?? '') ?? [
                'code' => $course->getSymbolicCode() ?? '',
                'type' => BillingCourseService::COURSE_TYPE_FREE,
                'price' => null,
            ];
            $courseAccess = $billingCourseService->getCourseAccessInfo($user, $course->getSymbolicCode() ?? '', $billingCourse);

            if ($billingCourse['type'] === BillingCourseService::COURSE_TYPE_FREE || $courseAccess['has_access']) {
                return $this->redirectToRoute('app_course_show', ['id' => $course->getId()]);
            }

            $payload = $billingCourseService->payCourse($user, $course->getSymbolicCode() ?? '');
        } catch (\App\Exception\BillingUnavailableException) {
            $this->addFlash('course_error', 'Сервис временно недоступен');

            return $this->redirectToRoute('app_course_show', ['id' => $course->getId()]);
        }

        if (($payload['success'] ?? false) === true) {
            $this->addFlash('course_success', 'Курс успешно оплачен');
        } elseif (isset($payload['message']) && is_string($payload['message']) && $payload['message'] !== '') {
            $this->addFlash('course_error', $payload['message']);
        } else {
            $this->addFlash('course_error', 'Сервис временно недоступен');
        }

        return $this->redirectToRoute('app_course_show', ['id' => $course->getId()]);
    }

    #[Route('/{id}/edit', name: 'app_course_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function edit(Request $request, Course $course, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(CourseType::class, $course);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_course_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('course/edit.html.twig', [
            'course' => $course,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_course_delete', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function delete(Request $request, Course $course, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$course->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($course);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_course_index', [], Response::HTTP_SEE_OTHER);
    }
}
