<?php

namespace App\Modules\Identity\Application;

/** New and reset internal ERP passwords only; existing login secrets remain valid. */
final class InternalErpPasswordPolicy
{
    public const MIN_LENGTH = 6;
    public const MAX_LENGTH = 15;

    /** @return list<string> */
    public static function rules(): array
    {
        return ['required', 'string', 'min:6', 'max:15', 'confirmed'];
    }
}
