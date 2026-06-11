<?php

declare(strict_types=1);

namespace App\Helpers;

class SlugHelper
{
    /**
     * Convert text to an SEO-friendly slug suitable for URLs.
     */
    public static function slugify(string $text): string
    {
        // Convert to lowercase and trim
        $text = mb_strtolower(trim($text), 'UTF-8');

        // Replace Spanish character mappings
        $replacements = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n', 'ç' => 'c',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u'
        ];
        $text = strtr($text, $replacements);

        // Replace any character that is not a letter or number with a dash
        $text = preg_replace('/[^a-z0-9]+/u', '-', $text);

        // Remove duplicate dashes and trim from beginning/end
        $text = preg_replace('/-+/', '-', $text);
        $text = trim($text, '-');

        if (empty($text)) {
            return 'profesional';
        }

        return $text;
    }
}
