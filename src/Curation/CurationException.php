<?php

declare(strict_types=1);

namespace App\Curation;

/**
 * A curator action that is not allowed; the message is shown to the curator.
 */
final class CurationException extends \RuntimeException
{
}
