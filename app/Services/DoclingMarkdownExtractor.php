<?php

namespace App\Services;

class DoclingMarkdownExtractor
{
    /**
     * @param  array<string, mixed>  $result
     */
    public static function extract(array $result): string
    {
        if (isset($result['document']['md_content']) && is_string($result['document']['md_content'])) {
            return $result['document']['md_content'];
        }

        if (isset($result['md_content']) && is_string($result['md_content'])) {
            return $result['md_content'];
        }

        $documents = $result['documents'] ?? null;
        if (is_array($documents)) {
            foreach ($documents as $doc) {
                if (is_array($doc) && isset($doc['md_content']) && is_string($doc['md_content'])) {
                    return $doc['md_content'];
                }
            }
        }

        return '';
    }
}
