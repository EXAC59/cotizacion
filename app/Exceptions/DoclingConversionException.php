<?php

namespace App\Exceptions;

use Exception;

class DoclingConversionException extends Exception
{
    public function userMessage(): string
    {
        $msg = $this->getMessage();

        if (str_contains(strtolower($msg), 'file format not allowed')) {
            return 'Formato no soportado por Docling. Si es Excel .xls, el servidor debería convertirlo automáticamente; comprueba que Excel esté instalado en Windows.';
        }

        return $msg !== '' ? $msg : 'Docling no pudo leer el archivo.';
    }
}
