<?php

namespace App\Service;

use App\Exception\BillingUnavailableException;

class BillingClient
{
    public function __construct(
        private readonly string $baseUri,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function request(string $method, string $path, array $data = [], array $headers = []): mixed
    {
        $curl = curl_init();

        if ($curl === false) {
            throw new BillingUnavailableException('Unable to initialize curl.');
        }

        $url = rtrim($this->baseUri, '/').'/'.ltrim($path, '/');
        $requestHeaders = array_merge([
            'Accept: application/json',
        ], $this->normalizeHeaders($headers));

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $requestHeaders,
        ];

        if ([] !== $data) {
            $payload = json_encode($data, JSON_THROW_ON_ERROR);
            $requestHeaders[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $requestHeaders;
            $options[CURLOPT_POSTFIELDS] = $payload;
        }

        curl_setopt_array($curl, $options);

        $response = curl_exec($curl);

        if ($response === false) {
            $message = curl_error($curl);
            curl_close($curl);

            throw new BillingUnavailableException($message !== '' ? $message : 'Billing service is unavailable.');
        }

        curl_close($curl);

        $decodedResponse = json_decode($response, true);

        return json_last_error() === JSON_ERROR_NONE ? $decodedResponse : $response;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function get(string $path, array $data = [], array $headers = []): mixed
    {
        if ([] !== $data) {
            $separator = str_contains($path, '?') ? '&' : '?';
            $path .= $separator.http_build_query($data);
        }

        return $this->request('GET', $path, [], $headers);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function post(string $path, array $data = [], array $headers = []): mixed
    {
        return $this->request('POST', $path, $data, $headers);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalizedHeaders = [];

        foreach ($headers as $name => $value) {
            $normalizedHeaders[] = sprintf('%s: %s', $name, $value);
        }

        return $normalizedHeaders;
    }
}
