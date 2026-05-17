<?php

namespace App\Service;

use App\Entity\Course;
use App\Exception\BillingCourseSyncException;
use App\Exception\BillingUnavailableException;
use App\Security\User;

final class BillingCourseService
{
    public const COURSE_TYPE_FREE = 'free';
    public const COURSE_TYPE_BUY = 'buy';
    public const COURSE_TYPE_RENT = 'rent';

    public function __construct(
        private readonly BillingClient $billingClient,
    ) {
    }

    /**
     * @return array<string, array{code: string, type: string, price: ?string}>
     */
    public function getCourseCatalogIndexed(): array
    {
        try {
            $payload = $this->billingClient->get('/api/v1/courses');
        } catch (BillingUnavailableException $exception) {
            throw new BillingUnavailableException('Billing service is unavailable.', previous: $exception);
        }

        if (!is_array($payload)) {
            throw new BillingUnavailableException('Billing service returned an invalid course catalog.');
        }

        $courses = [];

        foreach ($payload as $item) {
            $course = $this->normalizeCourse($item);

            if ($course === null) {
                throw new BillingUnavailableException('Billing service returned an invalid course catalog.');
            }

            $courses[$course['code']] = $course;
        }

        return $courses;
    }

    /**
     * @return array{code: string, type: string, price: ?string}|null
     */
    public function getCourse(string $code): ?array
    {
        try {
            $payload = $this->billingClient->get('/api/v1/courses/'.$code);
        } catch (BillingUnavailableException $exception) {
            throw new BillingUnavailableException('Billing service is unavailable.', previous: $exception);
        }

        if (is_array($payload) && isset($payload['message']) && is_string($payload['message']) && $payload['message'] !== '') {
            return null;
        }

        $course = $this->normalizeCourse($payload);

        if ($course === null) {
            throw new BillingUnavailableException('Billing service returned an invalid course.');
        }

        return $course;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return list<array{id: int, created_at: string, type: string, amount: string, course_code: ?string, expires_at: ?string}>
     */
    public function getTransactions(User $user, array $filters = []): array
    {
        try {
            $payload = $this->billingClient->get('/api/v1/transactions', $this->normalizeTransactionFilters($filters), $this->authorizationHeaders($user));
        } catch (BillingUnavailableException $exception) {
            throw new BillingUnavailableException('Billing service is unavailable.', previous: $exception);
        }

        if (!is_array($payload)) {
            throw new BillingUnavailableException('Billing service returned an invalid transaction list.');
        }

        $transactions = [];

        foreach ($payload as $item) {
            $transaction = $this->normalizeTransaction($item);

            if ($transaction === null) {
                throw new BillingUnavailableException('Billing service returned an invalid transaction list.');
            }

            $transactions[] = $transaction;
        }

        return $transactions;
    }

    /**
     * @param array{code: string, type: string, price: ?string}|null $course
     *
     * @return array{has_access: bool, transaction: array{id: int, created_at: string, type: string, amount: string, course_code: ?string, expires_at: ?string}|null}
     */
    public function getCourseAccessInfo(User $user, string $code, ?array $course = null): array
    {
        $course ??= $this->getCourse($code);

        if ($course === null || $course['type'] === self::COURSE_TYPE_FREE) {
            return [
                'has_access' => true,
                'transaction' => null,
            ];
        }

        $transactions = $this->getTransactions($user, [
            'type' => 'payment',
            'course_code' => $code,
            'skip_expired' => true,
        ]);

        return [
            'has_access' => $transactions !== [],
            'transaction' => $transactions[0] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payCourse(User $user, string $code): array
    {
        try {
            $payload = $this->billingClient->post('/api/v1/courses/'.$code.'/pay', [], $this->authorizationHeaders($user));
        } catch (BillingUnavailableException $exception) {
            throw new BillingUnavailableException('Billing service is unavailable.', previous: $exception);
        }

        if (!is_array($payload)) {
            throw new BillingUnavailableException('Billing service returned an invalid payment response.');
        }

        return $payload;
    }

    public function createCourse(User $user, Course $course): void
    {
        $payload = $this->sendCourseUpsertRequest(
            fn (): mixed => $this->billingClient->post(
                '/api/v1/courses',
                $this->coursePayload($course),
                $this->authorizationHeaders($user)
            ),
        );

        if (($payload['success'] ?? false) !== true) {
            throw new BillingCourseSyncException('Не удалось создать курс в биллинге.');
        }
    }

    public function updateCourse(User $user, string $currentCode, Course $course): void
    {
        $payload = $this->sendCourseUpsertRequest(
            fn (): mixed => $this->billingClient->post(
                '/api/v1/courses/'.$currentCode,
                $this->coursePayload($course),
                $this->authorizationHeaders($user)
            ),
        );

        if (($payload['success'] ?? false) !== true) {
            throw new BillingCourseSyncException('Не удалось обновить курс в биллинге.');
        }
    }

    /**
     * @return array<string, array{id: int, created_at: string, type: string, amount: string, course_code: ?string, expires_at: ?string}>
     */
    public function getActiveCourseAccessMap(User $user): array
    {
        $transactions = $this->getTransactions($user, [
            'type' => 'payment',
            'skip_expired' => true,
        ]);

        $accessMap = [];

        foreach ($transactions as $transaction) {
            $courseCode = $transaction['course_code'];

            if ($courseCode === null || isset($accessMap[$courseCode])) {
                continue;
            }

            $accessMap[$courseCode] = $transaction;
        }

        return $accessMap;
    }

    /**
     * @param mixed $payload
     *
     * @return array{code: string, type: string, price: ?string}|null
     */
    private function normalizeCourse(mixed $payload): ?array
    {
        if (
            !is_array($payload)
            || !isset($payload['code'], $payload['type'])
            || !is_string($payload['code'])
            || $payload['code'] === ''
            || !is_string($payload['type'])
            || !in_array($payload['type'], [self::COURSE_TYPE_FREE, self::COURSE_TYPE_BUY, self::COURSE_TYPE_RENT], true)
        ) {
            return null;
        }

        $price = $payload['price'] ?? null;

        if ($price !== null && !is_string($price)) {
            $price = null;
        }

        return [
            'code' => $payload['code'],
            'type' => $payload['type'],
            'price' => $price,
        ];
    }

    /**
     * @param mixed $payload
     *
     * @return array{id: int, created_at: string, type: string, amount: string, course_code: ?string, expires_at: ?string}|null
     */
    private function normalizeTransaction(mixed $payload): ?array
    {
        if (
            !is_array($payload)
            || !isset($payload['id'], $payload['created_at'], $payload['type'], $payload['amount'])
            || !is_int($payload['id'])
            || !is_string($payload['created_at'])
            || $payload['created_at'] === ''
            || !is_string($payload['type'])
            || !in_array($payload['type'], ['payment', 'deposit'], true)
            || !is_string($payload['amount'])
            || $payload['amount'] === ''
        ) {
            return null;
        }

        $courseCode = $payload['course_code'] ?? null;
        $expiresAt = $payload['expires_at'] ?? null;

        if ($courseCode !== null && !is_string($courseCode)) {
            $courseCode = null;
        }

        if ($expiresAt !== null && !is_string($expiresAt)) {
            $expiresAt = null;
        }

        return [
            'id' => $payload['id'],
            'created_at' => $payload['created_at'],
            'type' => $payload['type'],
            'amount' => $payload['amount'],
            'course_code' => $courseCode,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, array<string, scalar>>
     */
    private function normalizeTransactionFilters(array $filters): array
    {
        $query = [];

        if (isset($filters['type']) && is_string($filters['type']) && in_array($filters['type'], ['payment', 'deposit'], true)) {
            $query['filter']['type'] = $filters['type'];
        }

        if (isset($filters['course_code']) && is_string($filters['course_code']) && $filters['course_code'] !== '') {
            $query['filter']['course_code'] = $filters['course_code'];
        }

        if (!empty($filters['skip_expired'])) {
            $query['filter']['skip_expired'] = 1;
        }

        return $query;
    }

    /**
     * @return array{type: string, title: string, code: string, price: ?float}
     */
    private function coursePayload(Course $course): array
    {
        $type = $course->getType() ?? self::COURSE_TYPE_FREE;

        return [
            'type' => $type,
            'title' => $course->getName() ?? '',
            'code' => $course->getSymbolicCode() ?? '',
            'price' => $type === self::COURSE_TYPE_FREE ? null : $course->getPrice(),
        ];
    }

    /**
     * @param \Closure(): mixed $request
     *
     * @return array<string, mixed>
     */
    private function sendCourseUpsertRequest(\Closure $request): array
    {
        try {
            $payload = $request();
        } catch (BillingUnavailableException $exception) {
            throw new BillingUnavailableException('Billing service is unavailable.', previous: $exception);
        }

        if (!is_array($payload)) {
            throw new BillingUnavailableException('Billing service returned an invalid course upsert response.');
        }

        if (isset($payload['errors']) && is_array($payload['errors'])) {
            throw new BillingCourseSyncException(
                isset($payload['message']) && is_string($payload['message']) && $payload['message'] !== ''
                    ? $payload['message']
                    : 'Не удалось синхронизировать курс с биллингом.',
                $this->normalizeCourseSyncErrors($payload['errors']),
            );
        }

        if (isset($payload['message']) && is_string($payload['message']) && $payload['message'] !== '') {
            throw new BillingCourseSyncException($payload['message']);
        }

        return $payload;
    }

    /**
     * @param mixed $errors
     *
     * @return array<string, list<string>>
     */
    private function normalizeCourseSyncErrors(mixed $errors): array
    {
        if (!is_array($errors)) {
            return [];
        }

        $normalizedErrors = [];

        foreach ($errors as $error) {
            if (
                !is_array($error)
                || !isset($error['field'], $error['message'])
                || !is_string($error['field'])
                || !is_string($error['message'])
                || $error['message'] === ''
            ) {
                continue;
            }

            $field = match ($error['field']) {
                'code' => 'symbolic_code',
                'title' => 'name',
                default => $error['field'],
            };

            $message = $field === 'symbolic_code' && $error['field'] === 'code'
                ? 'Символьный код курса должен быть уникальным.'
                : $error['message'];

            $normalizedErrors[$field] ??= [];
            $normalizedErrors[$field][] = $message;
        }

        return $normalizedErrors;
    }

    /**
     * @return array<string, string>
     */
    private function authorizationHeaders(User $user): array
    {
        return [
            'Authorization' => sprintf('Bearer %s', $user->getApiToken()),
        ];
    }
}
