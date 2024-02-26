<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Libs;

class StringFuncs
{
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
     * Cleans a landingPage e.g. /shop/Stoffe/Zweck/Moebelstoff/// => /shop/Stoffe/Zweck/Moebelstoff
     * @param string $landingPage
     * @return string
     */
    public static function cleanLandingPage(string $landingPage): string
    {
        $landingPage = str_replace(["'", '"'], '', $landingPage);
        while (true) {
            if (!$landingPage) {
                break;
            }
            if ('' === $landingPage) {
                break;
            }
            if ('/' !== $landingPage[0]) {
                break;
            }
            $landingPage = substr($landingPage, 1);
        }
        return '/' . $landingPage;
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
     * shortens text to avoid wrapping of long words
     * @param string $text
     * @param int $chars
     * @param int $maxLength
     * @param string $delimiter
     * @return string
     */
    public static function avoidWraps(string $text, int $chars, int $maxLength = 500, string $delimiter = ' '): string
    {
        $explodes = explode($delimiter, $text);
        $output = [];
        foreach ($explodes as $explode) {
            if (strlen($explode) > $chars) {
                $output[] = substr($explode, 0, $chars) . '&#x2026;';
            } else {
                $output[] = $explode;
            }
        }
        $output = implode(' ', $output);
        if (strlen($output) > $maxLength) {
            $output = substr($output, 0, $maxLength) . '&#x2026;';
        }
        return $output;
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
     * returns folder structure used by image outputter e.g. input 1789 ouotputs "/0/1/789
     * @param $id
     * @param $recursions
     * @return string path
     */
    public static function getCascadedFolderFromInt($id, $recursions = 3): string
    {
        // or: $dirPath = str_replace(array(',', '.'), '/', number_format($siteID));
        $dirsN = [];
        $dirPath = sprintf('%0' . ($recursions * 3) . 'd', $id);
        preg_match_all('#[0-9]{3}#', $dirPath, $dirs);
        foreach ($dirs [0] as $dir) {
            $dirsN [] = ( int )$dir; // only one 0 (eg: 001 => 1, 000 => 0)
        }
        return implode('/', $dirsN);
    }

    /**
     * Highlight a text
     *
     * @param string $text
     * @param array $markingElements
     * @return string
     */
    public static function highlightExtended(string $text, array $markingElements): string
    {
        if (isset($markingElements['keywords'], $markingElements['keywords_style'])) {
            foreach ($markingElements['keywords'] as $keyword) {
                $original_keyword = $keyword;
                $keyword = Datafilter::escapeRegexSpecialChars($keyword);
                $keyword = str_replace([' ', '\-'], '[ \-]{0,4}', $keyword);
                $text = preg_replace(
                    '#(\b' . $keyword . '\b)#iu',
                    '{{{{span style="' . $markingElements['keywords_style'] . '"}}}}$1{{{{/span}}}}',
                    $text,
                    -1
                );
                //we want to avoid replacements within already highlighted text: if we have full match, there should not be highlighted a partial match within it
                preg_match('/({{{{span.+\/span}}}})+?/iU', $text, $full_replacements);
                // we check for full matches co copy them into temp variable $keyword_match
                $keyword_match = null;
                if (count($full_replacements)) {
                    $keyword_match = $full_replacements[0];
                    $text = preg_replace(
                        '/({{{{span.+\/span}}}})+?/iU',
                        '#######',
                        $text
                    ); // we replace full matches with a pattern that won't be overwritten in partial matches
                }
                // highlight partial keywords
                if (/*$total_replacements == 0 && */
                isset($markingElements['partial_keywords_style'])) {
                    $keyword_segments = Datafilter::breakwords($original_keyword, 1);
                    //print_r($keyword_segments);die();
                    foreach ($keyword_segments as $keyword_segment) {
                        $keyword_segment = Datafilter::escapeRegexSpecialChars($keyword_segment);
                        $text = preg_replace(
                            '#(' . $keyword_segment . ')#i',
                            '{{{{span style="' . $markingElements['partial_keywords_style'] . '"}}}}$1{{{{/span}}}}',
                            $text
                        );
                    }
                }
                //replace back full matches, if there were some
                if ($keyword_match) {
                    $text = preg_replace('/#######/', $keyword_match, $text);
                }
            }
        }

        // replace aux brackets
        return str_replace([
            '{{{{',
            '}}}}'
        ], [
            '<',
            '>'
        ], $text);
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
     * @param string $text
     * @param int $chars
     * @return string
     */
    public static function shortenByChars(string $text, int $chars): string
    {
        if (strlen($text) > $chars) {
            return substr($text, 0, $chars) . '...';
        }
        return $text;
    }

    /**
     * Checks if the string is really a number
     * @param string $var
     * @return bool
     */
    public static function isNum(string $var): bool
    {
        $var = trim($var);
        $loopLength = strlen($var);
        for ($i = 0; $i < $loopLength; $i++) {
            $ascii_code = ord($var[$i]);
            if ($ascii_code >= 48 && $ascii_code <= 57) {
                continue;
            }
            return false;
        }
        return true;
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
        json_decode($string);
        return (json_last_error() === JSON_ERROR_NONE);
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
