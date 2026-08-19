<?php

namespace App\Support;

class CompanyBranchesParser
{
    /**
     * Parse branch lines from settings textarea.
     *
     * Format (one branch per line, pipe-separated):
     * Label|Address|Phone
     *
     * Example:
     * Matriz|Colima Esq. Guillermo Prieto #415 La Paz, B.C.S|Tels. (612) 125 6111
     *
     * @return list<array{label: string, address: string, phone: string}>
     */
    public static function parse(string $text): array
    {
        $branches = [];

        foreach (preg_split('/\R/', trim($text)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = array_map('trim', explode('|', $line, 3));
            if (count($parts) >= 2 && $parts[0] !== '' && $parts[1] !== '') {
                $branches[] = [
                    'label' => rtrim($parts[0], ':'),
                    'address' => $parts[1],
                    'phone' => $parts[2] ?? '',
                ];

                continue;
            }

            if (str_contains($line, ':')) {
                [$label, $rest] = array_map('trim', explode(':', $line, 2));
                $phone = '';
                if (preg_match('/\b(Tels?\.[^—\-]*)/iu', $rest, $matches)) {
                    $phone = trim($matches[1]);
                    $rest = trim(str_replace($matches[0], '', $rest));
                    $rest = trim($rest, " \t\n\r\0\x0B—-");
                }

                $branches[] = [
                    'label' => $label,
                    'address' => $rest,
                    'phone' => $phone,
                ];

                continue;
            }

            $branches[] = [
                'label' => '',
                'address' => $line,
                'phone' => '',
            ];
        }

        return $branches;
    }
}
