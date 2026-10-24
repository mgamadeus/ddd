<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Libs;

class StringFuncs
{
    /**
     * Caps $text at $maxChars for a compact list rendering (e.g. toLlmMarkdown list rows), multibyte-safe, cut at a
     * word boundary, and appends an explicit remainder marker so the reader KNOWS content was cut and how much:
     * "Lorem ipsum dolor… [+1234 chars]". Returns $text unchanged when it fits. Unlike {@see self::shortenText}
     * (byte-based, HTML ellipsis, silent), this is for LLM/plain-text output where a silent cut would hide data.
     */
    public static function capTextWithRemainderMarker(string $text, int $maxChars): string
    {
        $text = trim($text);
        $textLength = mb_strlen($text);
        if ($textLength <= $maxChars) {
            return $text;
        }
        $cappedText = mb_substr($text, 0, $maxChars);
        $lastSpacePosition = mb_strrpos($cappedText, ' ');
        // Cut at the last word boundary unless that would sacrifice a large part of the budget (unbroken strings).
        if ($lastSpacePosition !== false && $lastSpacePosition > (int)($maxChars * 0.6)) {
            $cappedText = mb_substr($cappedText, 0, $lastSpacePosition);
        }
        $cappedText = rtrim($cappedText);
        return $cappedText . '… [+' . ($textLength - mb_strlen($cappedText)) . ' chars]';
    }

    /**
     * Shortens text without splitting the words up
     * @param string $text
     * @param int $chars
     * @return string
     */
    public static function shortenText(string $text, int $chars, $shortenSuffix = ' &#x2026;'): string
    {
        $textLength = strlen($text);
        if ($textLength <= $chars) {
            return $text;
        }
        $text = substr($text, 0, $chars);
        if (strrpos($text, ' ')) {
            $text = substr($text, 0, strrpos($text, ' '));
        }
        $text .= $shortenSuffix;
        return $text;
    }

    /**
     * removes consecutive highlighting made by lucene / solr highlighter
     * @param string $text
     * @param string $highlightElement
     * @param string $highlightElementClose
     * @return string
     */
    public static function removeConsecutiveHighlighting(
        string $text,
        string $highlightElement = '<b>',
        string $highlightElementClose = '<\/b>'
    ): string {
        $highlightElementClean = str_replace(['/', ' ', '"'], ['\/', '\s', '\"'], $highlightElement);
        $highlightElementCloseClean = str_replace(['/', ' ', '"'], ['\/', '\s', '\"'], $highlightElementClose);
        return preg_replace(
            '/' . $highlightElementClean . '(.+?)' . $highlightElementCloseClean . '\s+' . $highlightElementClean . '(.+?)' . $highlightElementCloseClean . '/i',
            $highlightElement . '$1 $2' . $highlightElementClose,
            $text
        );
    }

    /**
     * @param string $text
     * @param array $keywords
     * @param string $highlightElement
     * @param string $highlightElementClose
     * @param int $minLength
     * @return string
     */
    public static function highlight(
        string $text,
        array &$keywords,
        string $highlightElement = '<span class="highlight">',
        string $highlightElementClose = '</span>',
        int $minLength = 3
    ): string {
        foreach ($keywords as $keyword) {
            if (!is_numeric($keyword) && strlen($keyword) < $minLength) {
                continue;
            }
            $keyword = Datafilter::escapeRegexSpecialChars($keyword);
            $text = preg_replace("#($keyword)#iu", $highlightElement . '$1' . $highlightElementClose, $text);
        }
        return self::removeConsecutiveHighlighting($text, $highlightElement, $highlightElementClose);
    }

    /**
     * Converts snake_case to camelCase
     * @param string $string
     * @return string
     */
    public static function convertSnakeCaseToCamelCase(string $string): string
    {
        // Use regular expression to find underscores followed by a letter and capitalize the letter
        return lcfirst(str_replace('_', '', ucwords(strtolower($string), '_')));
    }

    /**
     * generates alias from Text
     * @param $text
     * @param string $space_delimiter
     * @param array $allowedDiacritics
     * @return string
     * @todo WHY not use Datafilter::filterSpecialChars() ?
     * \p{L} for ANY letter
     */
    public static function generateAlias(
        string $text,
        string $space_delimiter = '_',
        array $allowedDiacritics = []
    ): string {
        $alias = str_replace('&nbsp;', ' ', $text);
        $alias = trim($alias);
        $alias = str_replace('–', '-', $alias);
        $alias = str_replace(['-', '_'], [' ', ' '], $alias);
        $alias = trim($alias);
        $alias = str_replace(' ', $space_delimiter, $alias);
        $alias = Datafilter::filter_diacritics($alias, $allowedDiacritics);
        $alias = preg_replace('([^' . Datafilter::$special_chars . 'a-zA-Z0-9\-_\.])iu', '', $alias);
        $alias = preg_replace('/' . $space_delimiter . $space_delimiter . '+/', $space_delimiter, $alias);
        return trim($alias, '-');
    }

    /**
     * Removes unnecessary whitespaces, line breaks and tabs
     * @param string $input
     * @return string|null
     */
    public static function cleanText(string $input): string|null
    {
        $input = str_replace('&nbsp;', ' ', trim($input));
        return preg_replace('/\s\s+/', ' ', $input);
    }

    /**
     * @param int $length
     * @param bool $useSpecial
     * @return string
     */
    public static function generateRandomString(int $length = 10, bool $useSpecial = true): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        if ($useSpecial) {
            $chars .= '!@#$%^&*()_-=+;:,.?';
        }
        return substr(str_shuffle($chars), 0, $length);
    }

    /**
     * @param string $a
     * @param string $b
     * @return float|int
     */
    public static function levenshteinPercent(string $a, string $b): float|int
    {
        $a = metaphone(strtolower(trim($a)));
        $b = metaphone(strtolower(trim($b)));
        if (strlen($a) > 255 || strlen($b) > 255) {
            return -1;
        }
        return 1 - levenshtein($a, $b) / max(1, strlen($a), strlen($b));
    }

    /**
     * @param string $string
     * @return bool
     */
    public static function isJson(string $string): bool
    {
        return json_validate($string);
    }

    /**
     * Normalizes fileName, removes diacritics, file extension, non standard characters and double spaces
     * @param string $fileName
     * @return string
     */
    public static function normalizeFileName(string $fileName): string
    {
        // Convert the file name to lowercase and attempt to transliterate any characters to ASCII
        $fileNameNormalized = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $fileName));
        // Remove the file extension by looking for the last dot followed by any non-dot characters until the end of the string
        $fileNameNormalized = preg_replace('/\.[^.]+$/', '', $fileNameNormalized);
        // Replace any characters that are not a-z or A-Z with a space
        $fileNameNormalized = preg_replace('/[^a-zA-Z]/', ' ', $fileNameNormalized);
        // Replace multiple consecutive spaces with a single space
        $fileNameNormalized = preg_replace('/\s+/', ' ', $fileNameNormalized);
        // Trim off any leading or trailing spaces
        $fileNameNormalized = trim($fileNameNormalized);
        return $fileNameNormalized;
    }

    /**
     * Generates all possible combinations of words in string from $minLength to $maxLength items per combination
     * @param string $string
     * @param int $minLength
     * @param int $maxLength
     * @return array
     */
    public static function generateCombinationsOfWordsInString(
        string $string,
        int $minLength = 2,
        int $maxLength = 3
    ): array {
        $words = explode(' ', $string);
        $combinations = [];
        $count = count($words);

        // Function to generate permutations of a combination
        $permute = function ($items, $perms = []) use (&$combinations, &$permute, $minLength) {
            if (count($items) === 0 && count($perms) >= $minLength) {
                $combinations[] = implode(' ', $perms);
            } else {
                for ($i = 0; $i < count($items); ++$i) {
                    $newitems = $items;
                    $newperms = $perms;
                    [$foo] = array_splice($newitems, $i, 1);
                    array_unshift($newperms, $foo);
                    $permute($newitems, $newperms);
                }
            }
        };

        // Function to generate all combinations of a specific length
        $combinator = function ($start, $combination = []) use (
            $words,
            &$permute,
            $count,
            $minLength,
            $maxLength,
            &$combinator
        ) {
            if (count($combination) >= $minLength) {
                $permute($combination);
            }

            if (count($combination) < $maxLength) {
                for ($i = $start; $i < $count; $i++) {
                    $newCombination = array_merge($combination, [$words[$i]]);
                    $combinator($i + 1, $newCombination);
                }
            }
        };

        $combinator(0, []);

        return array_unique($combinations);
    }
}