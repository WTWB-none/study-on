<?php

namespace App\Controller;

use App\Dto\RegisterUserDto;
use App\Exception\BillingUnavailableException;
use App\Form\RegisterUserType;
use App\Security\StudyOnAuthenticator;
use App\Security\User;
use App\Service\BillingClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RegisterController extends AbstractController
{
    public function __construct(
        private readonly BillingClient $billingClient,
        private readonly Security $security,
    ) {
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        if ($this->getUser() instanceof User) {
            return $this->redirectToRoute('app_profile');
        }

        $dto = new RegisterUserDto();
        $form = $this->createForm(RegisterUserType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $payload = $this->billingClient->post('/api/v1/register', [
                    'email' => $dto->email,
                    'password' => $dto->password,
                ]);
            } catch (BillingUnavailableException) {
                $this->addFlash('register_error', 'Сервис временно недоступен. Попробуйте зарегистрироваться позднее');

                return $this->render('security/register.html.twig', [
                    'registrationForm' => $form,
                ]);
            }

            if (!is_array($payload)) {
                $this->addFlash('register_error', 'Некорректный ответ сервиса регистрации.');

                return $this->render('security/register.html.twig', [
                    'registrationForm' => $form,
                ]);
            }

            if (isset($payload['errors']) && is_array($payload['errors'])) {
                foreach ($payload['errors'] as $error) {
                    if (!is_array($error)) {
                        continue;
                    }

                    $message = $error['message'] ?? null;
                    $field = $error['field'] ?? null;

                    if (!is_string($message) || $message === '') {
                        continue;
                    }

                    if (is_string($field) && $form->has($field)) {
                        $form->get($field)->addError(new \Symfony\Component\Form\FormError($message));
                    } else {
                        $form->addError(new \Symfony\Component\Form\FormError($message));
                    }
                }

                return $this->render('security/register.html.twig', [
                    'registrationForm' => $form,
                ]);
            }

            if (isset($payload['message']) && is_string($payload['message']) && $payload['message'] !== '' && !isset($payload['token'])) {
                $form->addError(new \Symfony\Component\Form\FormError($payload['message']));

                return $this->render('security/register.html.twig', [
                    'registrationForm' => $form,
                ]);
            }

            if (!isset($payload['token']) || !is_string($payload['token']) || $payload['token'] === '') {
                $form->addError(new \Symfony\Component\Form\FormError('Некорректный ответ сервиса регистрации.'));

                return $this->render('security/register.html.twig', [
                    'registrationForm' => $form,
                ]);
            }

            $roles = $payload['roles'] ?? [];

            if (!is_array($roles)) {
                $roles = [];
            }

            $user = (new User())
                ->setEmail($dto->email)
                ->setApiToken($payload['token'])
                ->setRoles(array_values(array_filter($roles, 'is_string')))
                ->setBalance(0.0);

            return $this->security->login($user, StudyOnAuthenticator::class);
        }

        return $this->render('security/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }
}
