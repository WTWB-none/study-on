<?php

namespace App\Exception;

final class BillingCourseSyncException extends \RuntimeException
{
    /**
     * @param array<string, list<string>> $fieldErrors
     */
    public function __construct(
        string $message,
        private readonly array $fieldErrors = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, list<string>>
     */
    public function getFieldErrors(): array
    {
        return $this->fieldErrors;
    }
}
