<?php

namespace App\Tests\Mock;

use App\Exception\BillingUnavailableException;
use App\Service\BillingClient;

class BillingClientMock extends BillingClient
{
    private const INTERNAL_AUTH_SECRET = 'study-on-internal-auth-secret';
    private const ACCESS_TOKEN_TTL = 3600;
    private const RENT_DURATION_DAYS = 7;

    /**
     * @var array<string, array{password: string, roles: list<string>, balance: float}>
     */
    private static array $users = [];

    /**
     * @var array<string, array{code: string, type: string, price?: string}>
     */
    private static array $courses = [];

    /**
     * @var array<string, list<array{id: int, created_at: string, type: string, amount: string, course_code?: string, expires_at?: string}>>
     */
    private static array $transactions = [];

    private static int $nextTransactionId = 1;

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
        self::$courses = [
            'python-data-analysis' => [
                'code' => 'python-data-analysis',
                'type' => 'rent',
                'price' => '99.90',
            ],
            'ux-writing-basics' => [
                'code' => 'ux-writing-basics',
                'type' => 'free',
            ],
            'sql-for-product-managers' => [
                'code' => 'sql-for-product-managers',
                'type' => 'buy',
                'price' => '159.00',
            ],
            'project-management-essentials' => [
                'code' => 'project-management-essentials',
                'type' => 'buy',
                'price' => '79.00',
            ],
        ];
        self::$transactions = [];
        self::$nextTransactionId = 1;

        self::$transactions['user@example.com'] = [
            self::createTransaction('deposit', '299.40', null, null, '-30 days'),
            self::createTransaction('payment', '79.00', 'project-management-essentials', null, '-20 days'),
            self::createTransaction(
                'payment',
                '99.90',
                'python-data-analysis',
                (new \DateTimeImmutable('-3 days'))->format(DATE_ATOM),
                '-10 days'
            ),
        ];
        self::$transactions['super-admin@example.com'] = [
            self::createTransaction('deposit', '999.99', null, null, '-30 days'),
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
        return self::tokenForWithTtl($email, self::ACCESS_TOKEN_TTL);
    }

    public static function expiredTokenFor(string $email): string
    {
        return self::tokenForWithTtl($email, -60);
    }

    public static function refreshTokenFor(string $email): string
    {
        return 'refresh_'.md5($email);
    }

    public static function makeUnavailable(string $path): void
    {
        self::$unavailablePaths[$path] = true;
    }

    /**
     * @param array{code: string, type: string, price?: string} $course
     */
    public static function seedCourse(array $course): void
    {
        self::$courses[$course['code']] = $course;
    }

    /**
     * @return array{code: string, type: string, price?: string}|null
     */
    public static function courseByCode(string $code): ?array
    {
        return self::$courses[$code] ?? null;
    }

    public function get(string $path, array $data = [], array $headers = []): mixed
    {
        if ([] !== $data) {
            $separator = str_contains($path, '?') ? '&' : '?';
            $path .= $separator.http_build_query($data);
        }

        $this->guardAvailability($path);

        $pathInfo = $this->parsePath($path);

        return match (true) {
            $pathInfo['path'] === '/api/v1/users/current' => $this->currentUser($headers),
            $pathInfo['path'] === '/api/v1/courses' => array_values(self::$courses),
            preg_match('#^/api/v1/courses/([^/]+)$#', $pathInfo['path'], $matches) === 1 => self::$courses[$matches[1]] ?? ['message' => 'Course not found.'],
            $pathInfo['path'] === '/api/v1/transactions' => $this->transactions($headers, $pathInfo['query']),
            default => ['message' => 'Unknown billing endpoint.'],
        };
    }

    public function post(string $path, array $data = [], array $headers = []): mixed
    {
        $this->guardAvailability($path);

        $pathInfo = $this->parsePath($path);

        return match (true) {
            $pathInfo['path'] === '/api/v1/auth' => $this->authenticate($data),
            $pathInfo['path'] === '/api/v1/auth/remember' => $this->authenticateRememberMe($data, $headers),
            $pathInfo['path'] === '/api/v1/token/refresh' => $this->refreshAccessToken($data),
            $pathInfo['path'] === '/api/v1/register' => $this->register($data),
            $pathInfo['path'] === '/api/v1/courses' => $this->createCourse($data, $headers),
            preg_match('#^/api/v1/courses/([^/]+)$#', $pathInfo['path'], $matches) === 1 => $this->updateCourse($matches[1], $data, $headers),
            preg_match('#^/api/v1/courses/([^/]+)/pay$#', $pathInfo['path'], $matches) === 1 => $this->payCourse($matches[1], $headers),
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
            'refresh_token' => self::refreshTokenFor($email),
            'roles' => self::$users[$email]['roles'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function authenticateRememberMe(array $data, array $headers): array
    {
        if (($headers['X-Internal-Auth-Secret'] ?? '') !== self::INTERNAL_AUTH_SECRET) {
            return ['message' => 'Invalid internal auth secret.'];
        }

        $email = (string) ($data['username'] ?? '');

        if (!isset(self::$users[$email])) {
            return ['message' => 'User not found.'];
        }

        return [
            'username' => $email,
            'token' => self::tokenFor($email),
            'refresh_token' => self::refreshTokenFor($email),
            'roles' => self::$users[$email]['roles'],
            'balance' => self::$users[$email]['balance'],
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
            'refresh_token' => self::refreshTokenFor($email),
            'roles' => ['ROLE_USER'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function refreshAccessToken(array $data): array
    {
        $refreshToken = (string) ($data['refresh_token'] ?? '');
        $email = $this->emailByRefreshToken($refreshToken);

        if ($email === null) {
            return ['message' => 'Invalid refresh token.'];
        }

        return [
            'token' => self::tokenFor($email),
            'refresh_token' => self::refreshTokenFor($email),
            'roles' => self::$users[$email]['roles'],
            'username' => $email,
            'balance' => self::$users[$email]['balance'],
        ];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>|list<array{id: int, created_at: string, type: string, amount: string, course_code?: string, expires_at?: string}>
     */
    private function transactions(array $headers, array $query): array
    {
        $email = $this->emailFromHeaders($headers);

        if ($email === null) {
            return ['message' => 'Invalid token.'];
        }

        $transactions = self::$transactions[$email] ?? [];
        $filters = $query['filter'] ?? [];

        if (!is_array($filters)) {
            $filters = [];
        }

        if (isset($filters['type']) && is_string($filters['type']) && $filters['type'] !== '') {
            $transactions = array_values(array_filter(
                $transactions,
                static fn (array $transaction): bool => $transaction['type'] === $filters['type']
            ));
        }

        if (isset($filters['course_code']) && is_string($filters['course_code']) && $filters['course_code'] !== '') {
            $transactions = array_values(array_filter(
                $transactions,
                static fn (array $transaction): bool => ($transaction['course_code'] ?? null) === $filters['course_code']
            ));
        }

        if (!empty($filters['skip_expired'])) {
            $now = new \DateTimeImmutable();
            $transactions = array_values(array_filter($transactions, static function (array $transaction) use ($now): bool {
                if (!isset($transaction['expires_at'])) {
                    return true;
                }

                return new \DateTimeImmutable($transaction['expires_at']) > $now;
            }));
        }

        usort($transactions, static fn (array $left, array $right): int => $right['id'] <=> $left['id']);

        return $transactions;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function currentUser(array $headers): array
    {
        $email = $this->emailFromHeaders($headers);

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

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function payCourse(string $code, array $headers): array
    {
        $email = $this->emailFromHeaders($headers);

        if ($email === null) {
            return ['message' => 'Invalid token.'];
        }

        $course = self::$courses[$code] ?? null;

        if ($course === null) {
            return ['message' => 'Course not found.'];
        }

        if ($course['type'] === 'free') {
            return [
                'success' => true,
                'course_type' => 'free',
            ];
        }

        $price = (float) ($course['price'] ?? 0.0);

        if (self::$users[$email]['balance'] < $price) {
            return [
                'code' => 406,
                'message' => 'На вашем счету недостаточно средств',
            ];
        }

        self::$users[$email]['balance'] -= $price;

        $transaction = self::createTransaction(
            'payment',
            number_format($price, 2, '.', ''),
            $code,
            $course['type'] === 'rent'
                ? (new \DateTimeImmutable(sprintf('+%d days', self::RENT_DURATION_DAYS)))->format(DATE_ATOM)
                : null
        );

        self::$transactions[$email][] = $transaction;

        return [
            'success' => true,
            'course_type' => $course['type'],
            'expires_at' => $transaction['expires_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function createCourse(array $data, array $headers): array
    {
        if (!$this->isAdmin($headers)) {
            return ['message' => 'Administrator role required.'];
        }

        $validationErrors = $this->validateCoursePayload($data);
        if ($validationErrors !== []) {
            return [
                'message' => 'Validation failed.',
                'errors' => $validationErrors,
            ];
        }

        $code = (string) $data['code'];

        if (isset(self::$courses[$code])) {
            return $this->courseCodeConflict();
        }

        self::$courses[$code] = $this->normalizeCoursePayload($data);

        return ['success' => true];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function updateCourse(string $currentCode, array $data, array $headers): array
    {
        if (!$this->isAdmin($headers)) {
            return ['message' => 'Administrator role required.'];
        }

        if (!isset(self::$courses[$currentCode])) {
            return ['message' => 'Course not found.'];
        }

        $validationErrors = $this->validateCoursePayload($data);
        if ($validationErrors !== []) {
            return [
                'message' => 'Validation failed.',
                'errors' => $validationErrors,
            ];
        }

        $newCode = (string) $data['code'];

        if ($newCode !== $currentCode && isset(self::$courses[$newCode])) {
            return $this->courseCodeConflict();
        }

        unset(self::$courses[$currentCode]);
        self::$courses[$newCode] = $this->normalizeCoursePayload($data);

        return ['success' => true];
    }

    private function guardAvailability(string $path): void
    {
        if (isset(self::$unavailablePaths[$path])) {
            throw new BillingUnavailableException('Billing service is unavailable.');
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function isAdmin(array $headers): bool
    {
        $email = $this->emailFromHeaders($headers);

        if ($email === null) {
            return false;
        }

        return in_array('ROLE_SUPER_ADMIN', self::$users[$email]['roles'] ?? [], true);
    }

    private function emailByToken(string $token): ?string
    {
        foreach (array_keys(self::$users) as $email) {
            $payload = $this->decodeTokenPayload($token);

            if ($payload !== null && ($payload['username'] ?? null) === $email) {
                return $email;
            }
        }

        return null;
    }

    private function emailByRefreshToken(string $refreshToken): ?string
    {
        foreach (array_keys(self::$users) as $email) {
            if (self::refreshTokenFor($email) === $refreshToken) {
                return $email;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $headers
     */
    private function emailFromHeaders(array $headers): ?string
    {
        $authorization = $headers['Authorization'] ?? '';
        $token = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';

        return $this->emailByToken($token);
    }

    /**
     * @return array{path: string, query: array<string, mixed>}
     */
    private function parsePath(string $path): array
    {
        $parts = parse_url($path);
        $query = [];

        if (isset($parts['query']) && is_string($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        return [
            'path' => $parts['path'] ?? $path,
            'query' => $query,
        ];
    }

    private static function tokenForWithTtl(string $email, int $ttl): string
    {
        $header = self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::base64UrlEncode(json_encode([
            'iat' => time(),
            'exp' => time() + $ttl,
            'roles' => self::rolesFor($email),
            'username' => $email,
        ], JSON_THROW_ON_ERROR));

        return sprintf('%s.%s.%s', $header, $payload, md5($email.$ttl));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeTokenPayload(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);

        if (!is_string($payload)) {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function createTransaction(
        string $type,
        string $amount,
        ?string $courseCode = null,
        ?string $expiresAt = null,
        string $createdAtModifier = 'now',
    ): array
    {
        $createdAt = new \DateTimeImmutable($createdAtModifier);

        $transaction = [
            'id' => self::$nextTransactionId++,
            'created_at' => $createdAt->format(DATE_ATOM),
            'type' => $type,
            'amount' => $amount,
        ];

        if ($courseCode !== null) {
            $transaction['course_code'] = $courseCode;
        }

        if ($expiresAt !== null) {
            $transaction['expires_at'] = $expiresAt;
        }

        return $transaction;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array{field: string, message: string}>
     */
    private function validateCoursePayload(array $data): array
    {
        $errors = [];
        $type = $data['type'] ?? null;
        $title = $data['title'] ?? null;
        $code = $data['code'] ?? null;
        $price = $data['price'] ?? null;

        if (!is_string($type) || !in_array($type, ['free', 'rent', 'buy'], true)) {
            $errors[] = [
                'field' => 'type',
                'message' => 'Course type must be one of: free, rent, buy.',
            ];
        }

        if (!is_string($title) || $title === '') {
            $errors[] = [
                'field' => 'title',
                'message' => 'Course title should not be blank.',
            ];
        }

        if (!is_string($code) || $code === '') {
            $errors[] = [
                'field' => 'code',
                'message' => 'Course code should not be blank.',
            ];
        }

        if (is_string($type) && $type !== 'free') {
            if (!is_float($price) && !is_int($price)) {
                $errors[] = [
                    'field' => 'price',
                    'message' => 'Course price should not be blank for paid courses.',
                ];
            } elseif ((float) $price <= 0) {
                $errors[] = [
                    'field' => 'price',
                    'message' => 'Course price should be greater than 0.',
                ];
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{code: string, type: string, price?: string}
     */
    private function normalizeCoursePayload(array $data): array
    {
        $course = [
            'code' => (string) $data['code'],
            'type' => (string) $data['type'],
        ];

        if ($course['type'] !== 'free') {
            $course['price'] = number_format((float) $data['price'], 2, '.', '');
        }

        return $course;
    }

    /**
     * @return array<string, mixed>
     */
    private function courseCodeConflict(): array
    {
        return [
            'message' => 'Course with this code already exists.',
            'errors' => [
                [
                    'field' => 'code',
                    'message' => 'Course with this code already exists.',
                ],
            ],
        ];
    }
}
