<?php

namespace App\Exceptions;

use Exception;

class SolicitudFormatoException extends Exception
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        string $message,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }
}
