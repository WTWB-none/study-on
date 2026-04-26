<?php

namespace App\Security;

use App\Exception\BillingUnavailableException;
use App\Service\BillingClient;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class UserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(
        private readonly BillingClient $billingClient,
    ) {
    }

    /**
     * Symfony calls this method if you use features like switch_user
     * or remember_me.
     *
     * If you're not using these features, you do not need to implement
     * this method.
     *
     * @throws UserNotFoundException if the user is not found
     */
    public function loadUserByIdentifier($identifier): UserInterface
    {
        if (!is_string($identifier) || $identifier === '') {
            $exception = new UserNotFoundException('User identifier is empty.');
            $exception->setUserIdentifier((string) $identifier);

            throw $exception;
        }

        return (new User())->setEmail($identifier);
    }

    /**
     * @deprecated since Symfony 5.3, loadUserByIdentifier() is used instead
     */
    public function loadUserByUsername($username): UserInterface
    {
        return $this->loadUserByIdentifier($username);
    }

    /**
     * Refreshes the user after being reloaded from the session.
     *
     * When a user is logged in, at the beginning of each request, the
     * User object is loaded from the session and then this method is
     * called. Your job is to make sure the user's data is still fresh by,
     * for example, re-querying for fresh User data.
     *
     * If your firewall is "stateless: true" (for a pure API), this
     * method is not called.
     */
    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Invalid user class "%s".', $user::class));
        }

        $token = $user->getApiToken();

        if ($token === '') {
            return (new User())
                ->setEmail($user->getUserIdentifier())
                ->setRoles($user->getRoles())
                ->setBalance($user->getBalance());
        }

        try {
            $payload = $this->billingClient->get('/api/v1/users/current', [], [
                'Authorization' => sprintf('Bearer %s', $token),
            ]);
        } catch (BillingUnavailableException $exception) {
            throw new UserNotFoundException('Unable to refresh user from billing service.', previous: $exception);
        }

        if (!is_array($payload) || !isset($payload['username']) || !is_string($payload['username']) || $payload['username'] === '') {
            $exception = new UserNotFoundException('Billing service returned an invalid user payload.');
            $exception->setUserIdentifier($user->getUserIdentifier());

            throw $exception;
        }

        $roles = $payload['roles'] ?? [];
        $balance = $payload['balance'] ?? 0.0;

        if (!is_array($roles)) {
            $roles = [];
        }

        if (!is_numeric($balance)) {
            $balance = 0.0;
        }

        return (new User())
            ->setEmail($payload['username'])
            ->setApiToken($token)
            ->setRoles(array_values(array_filter($roles, 'is_string')))
            ->setBalance((float) $balance);
    }

    /**
     * Tells Symfony to use this provider for this User class.
     */
    public function supportsClass(string $class): bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }

    /**
     * Upgrades the hashed password of a user, typically for using a better hash algorithm.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        // TODO: when hashed passwords are in use, this method should:
        // 1. persist the new password in the user storage
        // 2. update the $user object with $user->setPassword($newHashedPassword);
    }
}
