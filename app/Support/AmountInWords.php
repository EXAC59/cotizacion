<?php

namespace App\Support;

class AmountInWords
{
    private const UNITS = [
        '', 'UN', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE',
        'DIECIOCHO', 'DIECINUEVE', 'VEINTE', 'VEINTIUN', 'VEINTIDÓS', 'VEINTITRÉS',
        'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS', 'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE',
    ];

    public static function pesosMx(float $amount): string
    {
        $pesos = (int) floor(abs($amount));
        $centavos = (int) round((abs($amount) - $pesos) * 100);

        if ($centavos === 100) {
            $pesos++;
            $centavos = 0;
        }

        $words = self::convertInteger($pesos);

        if ($words === '') {
            $words = 'CERO';
        }

        return sprintf(
            '%s PESOS %s/100 M.N.',
            $words,
            str_pad((string) $centavos, 2, '0', STR_PAD_LEFT),
        );
    }

    private static function convertInteger(int $number): string
    {
        if ($number === 0) {
            return '';
        }

        if ($number < 30) {
            return self::UNITS[$number];
        }

        if ($number < 100) {
            $tens = (int) floor($number / 10) * 10;
            $units = $number % 10;

            $tensWord = match ($tens) {
                30 => 'TREINTA',
                40 => 'CUARENTA',
                50 => 'CINCUENTA',
                60 => 'SESENTA',
                70 => 'SETENTA',
                80 => 'OCHENTA',
                90 => 'NOVENTA',
                default => '',
            };

            if ($units === 0) {
                return $tensWord;
            }

            return $tensWord.' Y '.self::UNITS[$units];
        }

        if ($number < 1000) {
            $hundreds = (int) floor($number / 100);
            $rest = $number % 100;

            $hundredWord = match ($hundreds) {
                1 => $rest === 0 ? 'CIEN' : 'CIENTO',
                2 => 'DOSCIENTOS',
                3 => 'TRESCIENTOS',
                4 => 'CUATROCIENTOS',
                5 => 'QUINIENTOS',
                6 => 'SEISCIENTOS',
                7 => 'SETECIENTOS',
                8 => 'OCHOCIENTOS',
                9 => 'NOVECIENTOS',
                default => '',
            };

            return trim($hundredWord.' '.self::convertInteger($rest));
        }

        if ($number < 1_000_000) {
            $thousands = (int) floor($number / 1000);
            $rest = $number % 1000;
            $thWord = $thousands === 1 ? 'MIL' : self::convertInteger($thousands).' MIL';

            return trim($thWord.' '.self::convertInteger($rest));
        }

        $millions = (int) floor($number / 1_000_000);
        $rest = $number % 1_000_000;
        $milWord = $millions === 1 ? 'UN MILLÓN' : self::convertInteger($millions).' MILLONES';

        return trim($milWord.' '.self::convertInteger($rest));
    }
}
