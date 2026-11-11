<?php

namespace App\Services\Integrations;

/** Normalised provider result returned by every adapter (simulated or live). */
final class ProviderResponse
{
    public function __construct(
        public string $status,                  // success|failure|pending|timeout|error
        public array $data = [],
        public ?string $errorCode = null,
        public ?string $message = null,
        public ?string $providerRef = null,
        public bool $simulated = true,
        public ?int $logId = null,
    ) {}

    public function successful(): bool
    {
        return $this->status === 'success';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }
}
