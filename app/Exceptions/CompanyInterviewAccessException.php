<?php

namespace App\Exceptions;

use RuntimeException;

class CompanyInterviewAccessException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 400,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
