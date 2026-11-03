<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class MissingShopContextException extends RuntimeException
{
    public function __construct(string $message = 'No active shop context established for this operation.')
    {
        parent::__construct($message);
    }
}
