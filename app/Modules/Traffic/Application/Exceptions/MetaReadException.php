<?php

namespace App\Modules\Traffic\Application\Exceptions;

use RuntimeException;

final class MetaReadException extends RuntimeException
{
    public function __construct(public readonly string $status, string $message)
    {
        parent::__construct($message);
    }
}
