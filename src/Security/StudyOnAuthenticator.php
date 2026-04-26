<?php

namespace App\Security;

use App\Exception\BillingUnavailableException;
use App\Service\BillingClient;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class StudyOnAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private BillingClient $billingClient,
    ) {}

    public function authenticate(Request $request): Passport
    {
        $email = $request->getPayload()->getString('email');
        $password = $request->getPayload()->getString('password');

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        $loadUser = function (string $identifier) use ($password): User {
            try {
                $payload = $this->billingClient->post('/api/v1/auth', [
                    'username' => $identifier,
                    'password' => $password,
                ]);
            } catch (BillingUnavailableException $exception) {
                throw new CustomUserMessageAuthenticationException('Сервис временно недоступен. Попробуйте авторизоваться позднее');
            }

            if (!is_array($payload)) {
                throw new CustomUserMessageAuthenticationException('Некорректный ответ сервиса авторизации.');
            }

            if (isset($payload['message']) && is_string($payload['message']) && $payload['message'] !== '') {
                throw new CustomUserMessageAuthenticationException($payload['message']);
            }

            if (!isset($payload['token']) || !is_string($payload['token']) || $payload['token'] === '') {
                throw new CustomUserMessageAuthenticationException('Некорректный ответ сервиса авторизации.');
            }

            $roles = $payload['roles'] ?? [];

            if (!is_array($roles)) {
                $roles = [];
            }

            return (new User())
                ->setEmail($identifier)
                ->setApiToken($payload['token'])
                ->setRoles(array_values(array_filter($roles, 'is_string')));
        };

        return new Passport(
            new UserBadge($email, $loadUser),
            new CustomCredentials(static function (): bool {
                return true;
            }, $email),
            [
                new CsrfTokenBadge('authenticate', $request->getPayload()->getString('_csrf_token')),
                new RememberMeBadge(),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate('app_course_index'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}
