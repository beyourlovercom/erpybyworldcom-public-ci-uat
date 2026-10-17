<?php

namespace App\Modules\Content\Application\Exceptions;

use RuntimeException;

final class DocumentImportException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
