<?php

namespace App\Exceptions;

use RuntimeException;

class TenantIsolationViolation extends RuntimeException
{
    public function __construct(string $model, mixed $id)
    {
        parent::__construct("Cross-tenant write blocked on {$model} #{$id}.");
    }
}
