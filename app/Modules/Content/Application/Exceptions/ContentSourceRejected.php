<?php

namespace App\Modules\Content\Application\Exceptions;

use DomainException;

final class ContentSourceRejected extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Content source rejected [{$reason}].");
    }
}
