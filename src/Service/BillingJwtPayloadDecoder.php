<?php

namespace App\Service;

final class BillingJwtPayloadDecoder
{
    /**
     * @return array<string, mixed>|null
     */
    public function decode(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        $payload = $this->decodeBase64Url($parts[1]);

        if ($payload === null) {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function decodeBase64Url(string $value): ?string
    {
        $remainder = strlen($value) % 4;

        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }
}
