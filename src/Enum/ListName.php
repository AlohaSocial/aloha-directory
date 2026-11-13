<?php

declare(strict_types=1);

namespace App\Enum;

enum ListName: string
{
    case Servers = 'servers';
    case Search = 'search';
    case Keys = 'keys';

    public function fileName(): string
    {
        return $this->value.'.json';
    }
}
