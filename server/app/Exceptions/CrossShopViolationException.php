<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class CrossShopViolationException extends RuntimeException
{
    public function __construct(string $message = 'Cross-shop data access violation detected.')
    {
        parent::__construct($message);
    }
}
