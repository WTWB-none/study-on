<?php

namespace App\Tests\Mock;

use App\Exception\BillingUnavailableException;
use App\Service\BillingClient;

class BillingClientMock extends BillingClient
{
    /**
     * @var array<string, array{password: string, roles: list<string>, balance: float}>
     */
    private static array $users = [];

    /**
     * @var array<string, bool>
     */
    private static array $unavailablePaths = [];

    public static function reset(): void
    {
        self::$users = [
            'user@example.com' => [
                'password' => 'user123',
                'roles' => ['ROLE_USER'],
                'balance' => 120.5,
            ],
            'super-admin@example.com' => [
                'password' => 'super-admin123',
                'roles' => ['ROLE_SUPER_ADMIN'],
                'balance' => 999.99,
            ],
        ];
        self::$unavailablePaths = [];
    }

    public static function rolesFor(string $email): array
    {
        return self::$users[$email]['roles'] ?? ['ROLE_USER'];
    }

    public static function balanceFor(string $email): float
    {
        return self::$users[$email]['balance'] ?? 0.0;
    }

    public static function tokenFor(string $email): string
    {
        return 'token_'.md5($email);
    }

    public static function makeUnavailable(string $path): void
    {
        self::$unavailablePaths[$path] = true;
    }

    public function get(string $path, array $data = [], array $headers = []): mixed
    {
        $this->guardAvailability($path);

        if ($path !== '/api/v1/users/current') {
            return ['message' => 'Unknown billing endpoint.'];
        }

        $authorization = $headers['Authorization'] ?? '';
        $token = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
        $email = $this->emailByToken($token);

        if ($email === null) {
            return ['message' => 'Invalid token.'];
        }

        $user = self::$users[$email];

        return [
            'username' => $email,
            'roles' => $user['roles'],
            'balance' => $user['balance'],
        ];
    }

    public function post(string $path, array $data = [], array $headers = []): mixed
    {
        $this->guardAvailability($path);

        return match ($path) {
            '/api/v1/auth' => $this->authenticate($data),
            '/api/v1/register' => $this->register($data),
            default => ['message' => 'Unknown billing endpoint.'],
        };
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function authenticate(array $data): array
    {
        $email = (string) ($data['username'] ?? '');
        $password = (string) ($data['password'] ?? '');

        if (!isset(self::$users[$email]) || self::$users[$email]['password'] !== $password) {
            return ['message' => 'Invalid credentials.'];
        }

        return [
            'token' => self::tokenFor($email),
            'roles' => self::$users[$email]['roles'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function register(array $data): array
    {
        $email = (string) ($data['email'] ?? '');
        $password = (string) ($data['password'] ?? '');

        if (isset(self::$users[$email])) {
            return [
                'message' => 'User with this email already exists.',
                'errors' => [
                    [
                        'field' => 'email',
                        'message' => 'User with this email already exists.',
                    ],
                ],
            ];
        }

        self::$users[$email] = [
            'password' => $password,
            'roles' => ['ROLE_USER'],
            'balance' => 0.0,
        ];

        return [
            'token' => self::tokenFor($email),
            'roles' => ['ROLE_USER'],
        ];
    }

    private function guardAvailability(string $path): void
    {
        if (isset(self::$unavailablePaths[$path])) {
            throw new BillingUnavailableException('Billing service is unavailable.');
        }
    }

    private function emailByToken(string $token): ?string
    {
        foreach (array_keys(self::$users) as $email) {
            if (self::tokenFor($email) === $token) {
                return $email;
            }
        }

        return null;
    }
}
