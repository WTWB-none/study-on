<?php

namespace App\Controller;

use App\Repository\CourseRepository;
use App\Security\User;
use App\Service\BillingCourseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/transactions')]
final class TransactionController extends AbstractController
{
    #[Route(name: 'app_transaction_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request, BillingCourseService $billingCourseService, CourseRepository $courseRepository): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $filters = [
            'type' => $request->query->getString('type'),
            'course_code' => $request->query->getString('course_code'),
            'skip_expired' => $request->query->getBoolean('skip_expired'),
        ];

        $courseNames = [];

        foreach ($courseRepository->findAll() as $course) {
            $symbolicCode = $course->getSymbolicCode();

            if ($symbolicCode !== null) {
                $courseNames[$symbolicCode] = [
                    'name' => $course->getName() ?? $symbolicCode,
                    'id' => $course->getId(),
                ];
            }
        }

        try {
            $transactions = $billingCourseService->getTransactions($user, $filters);
        } catch (\App\Exception\BillingUnavailableException) {
            return $this->render('transaction/index.html.twig', [
                'transactions' => [],
                'transaction_filters' => $filters,
                'courses_by_code' => $courseNames,
                'billing_unavailable' => true,
            ]);
        }

        return $this->render('transaction/index.html.twig', [
            'transactions' => $transactions,
            'transaction_filters' => $filters,
            'courses_by_code' => $courseNames,
            'billing_unavailable' => false,
        ]);
    }
}
