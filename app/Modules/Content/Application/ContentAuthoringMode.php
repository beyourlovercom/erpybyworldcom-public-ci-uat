<?php

namespace App\Modules\Content\Application;

enum ContentAuthoringMode: string
{
    case Ai = 'ai';
    case Human = 'human';
}
