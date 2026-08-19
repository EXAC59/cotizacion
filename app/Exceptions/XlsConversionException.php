<?php

namespace App\Exceptions;

use Exception;

class XlsConversionException extends Exception
{
    public function userMessage(): string
    {
        $msg = $this->getMessage();

        return $msg !== ''
            ? $msg
            : 'No se pudo convertir el Excel .xls. Guárdalo como .xlsx en Excel o usa el script scripts/convert-xls-to-xlsx.ps1.';
    }
}
