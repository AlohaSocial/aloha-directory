<?php

declare(strict_types=1);

namespace App\Enum;

enum CommentVisibility: string
{
    /** Curators only. */
    case Internal = 'internal';
    /** Also shown to the server admin through the status API. */
    case Admin = 'admin';
}
