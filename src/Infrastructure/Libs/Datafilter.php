<?php
/** @noinspection PhpArithmeticTypeCheckInspection */

/**
 *  Some functions to be used for data manipulation.
 * !!! ATENTION, DO NOT MODIFY/SAVE THIS FILE IN OTHER ENCODING. USED ZEND UTF-8.
 *
 * @project  companysearchengine, ampel, seolyzer, addresscrawler
 */

namespace DDD\Infrastructure\Libs;

use DDD\Infrastructure\Services\AuthService;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

define('_is_utf8_split', 5000);

class Datafilter
{
    public static $htmlPurifier = null;

    public static $locale = null;
    public static $currency = null;
    public static $debug_url = null;
    // first we put in utf8 then in hex
    // Strange, functions doesnt work OK if using hexa. weird char? @todo research. \xC4\xE4\xD6\xF6\xDC\xFC\xDF\x20AC\xC0\xC2\xC8\xC9\xCA\xCB\xCE\xCF\xD4\x152\xD9\xDB\x178\xE0\xE2\xE8\xE9\xEA\xEB\xEE\xEF\xF4\x153\xF9\xFB\xFF\xC1\xCD\xD3\xDA\xD1\xE1\xED\xF3\xFA\xF1\xCC\xD2\xEC\xF2\x103\x15F\x163\x102\x15E\x162
    public static $special_charsOLD = 'ÄäÖöÜüß€ÀÂÄÈÉÊËÎÏÔŒÙÛÜŸàâäèéêëîïôœùûüÿÁÉÍÓÚÑÜáéíóúñüÀÈÉÌÒÓÙàèéìòóùăîâşţĂÎÂŞŢșțΑαΒβΓγΔδΕεΖζΗηΘθΙιΚκΛλΜμΝνΞξΟοΠπΡρΣσΣσςΤτΥυΦφΧχΨψΩωĄąĘęĆćŁłŃńŚśŹźŻżÓóόώ';
    // ok, NO need to list all unicode LETTERS. use this.
    public static $special_chars = '\p{L}\p{N}';
    // delimiter characters to exclude from breakwords function.
    public static $exclude_chars = '';
    // regex special chars. those need to be escaped
    public static $regex_special_chars = [
        '\\',
        '.',
        '[',
        ']',
        '{',
        '}',
        '(',
        ')',
        '*',
        '+',
        '-',
        '?',
        '^',
        '$',
        '|',
        '#'
    ];
    // greek: ΑαΒβΓγΔδΕεΖζΗηΘθΙιΚκΛλΜμΝνΞξΟοΠπΡρΣσΣσςΤτΥυΦφΧχΨψΩω
    // polish: ĄąĘęĆćŁłŃńŚśŹźŻżÓó
    // india (hindi): ऑऒऊऔउबभचछडढफफ़गघग़घग़हजझकखख़लळऌऴॡमनङञणऩॐपक़रऋॠऱसशषटतठदथधड़ढ़वयय़ज़
    // italian: ÀÈÉÌÒÙàèéìòù
    public static $umlauts = [
        'ß',
        'œ',
        'é',
        'è',
        'ë',
        'ę',
        'á',
        'à',
        'ä',
        'â',
        'ã',
        'ö',
        'ô',
        'ó',
        'ò',
        'ø',
        'ï',
        'í',
        'î',
        'ł',
        'ş',
        'ș',
        'ś',
        'ţ',
        'ț',
        'ü',
        'ù',
        'ú',
        'û',
        'ñ',
        'ÿ',
        'ê',
        'ż',
        'ç',
        '¿',
        'ą',
        'ć',
        'ł',
        'ń',
        'ź',
        'α',
        'β',
        'γ',
        'δ',
        'ε',
        'ζ',
        'η',
        'θ',
        'ι',
        'κ',
        'λ',
        'μ',
        'ν',
        'ξ',
        'ο',
        'π',
        'ρ',
        'σ',
        'ς',
        'τ',
        'υ',
        'φ',
        'χ',
        'ψ',
        'ω',
        'ό',
        'ώ'
    ];
    public static $special = [
        "'",  // - http://jira.rankingcoach.com/browse/RAN-1223271
        //'"', - http://jira.rankingcoach.com/browse/RAN-1120871 ,
        ' ',
        '.',
        ',',
        ':',
        ';',
        '-',
        '+',
        '=',
        '^',
        '*',
        '/',
        '_',
        '&',
        '%',
        '$',
        '€',
        '§',
        '@',
        '<',
        '>',
        '=',
        '!',
        '?',
        '|',
        '#',
        '(',
        ')',
        '[',
        ']',
        '{',
        '}'
    ];

    public static function decodeAmpersand(string $input): string
    {
        return preg_replace_callback("/([a-zA-Z0-9]*\s*)&amp;(\s*[a-zA-Z0-9]*)/", function ($matches) {
            return $matches[1] . '&' . $matches[2];
        }, $input);
    }

    /**
     * @param string|array|int|float|bool|null $input
     *
     * @return string|array|int|float|bool|null
     */
    public static function sanitizeInput(string|array|int|float|bool|null $input): string|array|int|float|bool|null
    {
        if (is_int($input) || is_float($input) || is_bool($input) || is_null($input)) {
            return $input;
        }

        if (!self::$htmlPurifier) {
            self::initializePurifier();
        }

        if (is_string($input)) {
            return html_entity_decode(self::$htmlPurifier->purify($input));
        }
        if (is_array($input)) {
            return array_map([self::class, 'sanitize'], $input);
        }

        return $input;
    }

    /**
     * @param array $customDefinitions
     *
     * Example of $customDefinitions structure:
     * $customConfig = [
     *      'definition_id' => 'my-custom-definition',
     *      'definition_rev' => 1,
     *      'elements' => [
     *          'name' => [
     *              'type' => 'Inline',
     *              'contents' => 'Flow',
     *              'attr_collections' => 'Common',
     *              'attributes' => [
     *                  'href' => 'URI',
     *                  'target' => 'Enum#_blank,_self',
     *              ]
     *          ],
     *      ],
     * ];
     *
     * @return \HTMLPurifier
     */
    public static function initializePurifier(array $customDefinitions = []): \HTMLPurifier
    {
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.DefinitionID', $customDefinitions['definition_id'] ?? 'default');
        $config->set('HTML.DefinitionRev', $customDefinitions['definition_rev'] ?? 1);

        if (!empty($customDefinitions['elements']) && ($def = $config->maybeGetRawHTMLDefinition())) {
            foreach ($customDefinitions['elements'] as $elementName => $elementConfig) {
                // Add the custom element
                $def->addElement(
                    $elementName,
                    $elementConfig['type'],
                    $elementConfig['contents'],
                    $elementConfig['attr_collections'],
                    $elementConfig['attributes'] ?? [],
                );
            }
        }

        self::$htmlPurifier = new \HTMLPurifier($config);
        return self::$htmlPurifier;
    }

    /**
     * Check if string is UTF8 valid.
     * By rodrigo at overflow dot biz
     * http://php.net/manual/en/function.utf8-encode.php
     * @param     $string
     * @return
     */
    public static function is_utf8(string $string): bool
    { // v1.01
        if (strlen($string) > _is_utf8_split) {
            // Based on: http://mobile-website.mobi/php-utf8-vs-iso-8859-1-59
            for (
                $i = 0, $s = _is_utf8_split, $j = ceil(
                strlen($string) / _is_utf8_split
            ); $i < $j; $i++, $s += _is_utf8_split
            ) {
                if (self::is_utf8(substr($string, $s, _is_utf8_split))) {
                    return true;
                }
            }
            return false;
        } else {
            // From http://w3.org/International/questions/qa-forms-utf-8.html
            return preg_match(
                '%^(?:
                [\x09\x0A\x0D\x20-\x7E]            # ASCII
            | [\xC2-\xDF][\x80-\xBF]             # non-overlong 2-byte
            |  \xE0[\xA0-\xBF][\x80-\xBF]        # excluding overlongs
            | [\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}  # straight 3-byte
            |  \xED[\x80-\x9F][\x80-\xBF]        # excluding surrogates
            |  \xF0[\x90-\xBF][\x80-\xBF]{2}     # planes 1-3
            | [\xF1-\xF3][\x80-\xBF]{3}          # planes 4-15
            |  \xF4[\x80-\x8F][\x80-\xBF]{2}     # plane 16
        )*$%xs',
                $string
            );
        }
    }

    /**
     * Checks if the given string contains a domain name.
     * This function looks for patterns that match a domain within a string. It can detect domains
     * that may start with 'http://' or 'https://', optionally preceded by 'www.', and checks for
     * a valid domain format including top-level domains (TLDs) that are two or more characters long.
     *
     * @param string $string The string to check for the presence of a domain.
     * @return bool True if a domain is found in the string, false otherwise.
     */
    public static function containsDomain(string $string): bool
    {
        $pattern = '/\b(?:https?:\/\/)?(?:www\.)?([\p{L}\p{N}-]+\.[\p{L}]{2,})(\/\S*)?\b/u';
        return preg_match($pattern, $string) > 0;
    }

    public static function encode_utf8v2(string $text, bool $with_htmldecoding = true): string
    {
        $charset = mb_detect_encoding($text, 'EUC-JP, UTF-8', true);
        // if we have a meta charset="utf-8" header, then set that to curent encoding (this will fix sites such as: http://www.anwalt-oberursel.de/)
        preg_match(
            '#<meta[^<>]+charset=(?P<encoding>.+?)("|\')[^<>]*>#i',
            $text,
            $match
        ); // notice, no u, since it may break everything on wrongly encoded docs. //eg: www.love-remedies-shop.de
        if (!empty($match['encoding'])) {
            $charset = strtoupper($match['encoding']);
        }
        preg_match('#<meta charset=("|\')(?P<encoding>[^>]+)("|\')>#i', $text, $match);
        if (!empty($match['encoding'])) {
            $charset = strtoupper($match['encoding']);
        }
        $isValidUTF8 = $charset == 'UTF-8';
        //var_dump($charset);
        if (!$isValidUTF8) {
            $text = @mb_convert_encoding($text, 'UTF-8', $charset);
        }
        if ($with_htmldecoding) {
            $text = self::entity_decode($text);
        }

        return $text;
    }

    // accents, diacritcs
    // public static $specialChars = '\x27\x41-\x5a\x5f\x61-\x7a\xc0-\xd6\xd8-\xf6\xf8-\xff\x100\x103\x17e\x180';

    /**
     * Entity decode
     *
     * @param     $text
     * @return
     */
    public static function entity_decode(string $text): string
    {
        $text = html_entity_decode(str_replace('&nbsp;', ' ', $text), ENT_QUOTES, 'utf-8');
        return $text;
    }

    /**
     * Take care of extra spaces or add spaces between [.,!?]
     * @param $text
     */
    public static function nice_text(string $text): string
    {
        $replace_array = [
            ', ',
            '. ',
            '! ',
            '? ',
            ': '
        ];
        // add space
        $text = str_replace([
            ',',
            '.',
            '!',
            '?',
            ':'
        ], $replace_array, $text);
        // remove dobule space
        $text = str_replace([
            ',  ',
            '.  ',
            '!  ',
            '?  ',
            ':  '
        ], $replace_array, $text);
        return $text;
    }

    public static function removeNonAscii(string $string): string
    {
        return preg_replace('/[^(\x20-\x7F)]*/', '', $string);
    }

    /**
     * Clean keywords.
     *
     * @param     $text
     * @return
     *
     */
    public static function cleanAndFilterKeyword(string $text): string
    {
        $text = self::cleanText($text);
        $text_without_spaces = str_replace(' ', '', $text);
        if (is_numeric($text_without_spaces)) {
            return '';
        }
        if (strlen($text_without_spaces) < 3) {
            return '';
        }
        return $text;
    }

    /**
     * Clean data. Remove some special chars. Whitelist of allowed chars
     *
     * @param string $data
     * @return  string
     */
    public static function cleanText(
        mixed $data,
        bool $utfdecode = false,
        bool $utfencode = true,
        bool $extrachars = false
    ): string {
        if (!is_string($data)) {
            return '';
        }
        if ($extrachars) {
            $chars = '\'"';
        } else {
            $chars = '';
        }
        if ($utfdecode) {
            $data = utf8_decode($data);
        }
        if ($utfencode) {
            $data = self::encode_utf8($data);
        }
        //$data = self::encode_utf8($data);
        $data = preg_replace(
            '#[^~\r\n\ta-z ' . self::$special_chars . '90-9\.:,;=\-_\+\*&\?!/{}\(\)\[\]%$€<>' . $chars . '\|\\\]#isu',
            ' ',
            $data
        );
        $data = preg_replace('#[\r\n\t]#s', ' ', $data);
        $data = preg_replace('/\s+/', ' ', $data);
        /*
            $from = array("#'#", '#"#');
            $to = array('\u0027', '\u0022');
            $data =  preg_replace($from,$to,$data);
         *
         */
        $data = trim($data);
        return $data;
    }

    /**
     * Encode data to utf8.
     *
     * @param string $data
     * @return  string
     */
    public static function encode_utf8(string $data): string
    {
        /*
         *  Used before:
         * http://us3.php.net/manual/en/function.mb-detect-encoding.php (by chris AT w3style.co DOT uk)
         * http://www.w3.org/International/questions/qa-forms-utf-8.en.php
         * but aren't reliable if a document is using MULTIPLE encoding sets.
         *
         * sites with encoding troubleshots
         * http://7d-agentur.de/content/impressum.html
         * http://www.medi-prax.de/conditions.php
         * http://www.toshiba.de/impressum/index.html
         *
         * not reliable. ex for: 4290 www.butznwirt.de
         * if (mb_detect_encoding($data . 'a' , 'UTF-8, ASCII,JIS,EUC-JP,SJIS, ISO-8859-1, ISO-8859-2, GBK') != 'UTF-8') {
         * 	$data = utf8_encode($data);
         * }
         */

        /* Very USEFULL when problem with a char.
         * So we comment it, may it be usefull later.
            $dataE = preg_split('//', $data, -1);
            foreach ($dataE as $key =>$char) {
            //if ($key == 7674)
            //echo $char . '<Br/>';
            if (ord($char) > 150) echo  ord($char) . ' = ' .$char . '<Br/>';
            }
         */

        $data = utf8_encode($data);

        //return $data;
        // This will take care of <>> character that generates a lot of proble (eg: mysql text truncation)
        //  @todo: it seemns that those eliminates text from some google results. ex: '"aus Polypropylen, Polyethylen und anderen Thermoplasten, welche in kompakter, geschäumter und/oder coextrudierter Version angeboten"'
        //$data = str_replace(array(chr(195), chr(197), chr(196), chr(162), chr(163), chr(159)), '', $data);
        //$data = preg_replace('#\xC5[\.,a-z0-9 ]#u', '', $data);

        /*
         * Now  reverse the process..
         * We need to run the test for each word, damn some sites such as:
         * http://mydealz.de/250/so-habe-ich-mein-iphone-ohne-vertrag-fuer-450e-gekauft
         */

        // remove trailing spaces
        $data = preg_replace('#\s{2,}#', ' ', $data);
        $dataExp = explode(' ', $data);
        $wordArr = [];

        foreach ($dataExp as $word) {
            // from DE, RO, jpn charset ..
            if (preg_match(
                '#Â©|Ã¤|Ã¼|Ã¶|Ã®|ÄÅ|[^\w\s\d\x00-\x7F]|â¬|Â»|â¢|â€|€™|Â|Å|â|Å£|Ã¢|Ã|Ã§|°Ñ|Ð|Ä|ã|¼|å|¤|¾|é#su',
                $word
            )) {
                //if (!self::is_utf8($word)) {
                $wordArr[] = utf8_decode($word);
                //echo "$word -^gt;  " . utf8_encode($word) . "<br/>";
            } else {
                $wordArr[] = $word;
            }
        }

        $data = implode(' ', $wordArr);

        // convert entities into chars, fix white space. BUGGY with SOLR.
        $data = str_replace('&ndash;', '-', $data);
        $data = self::entity_decode($data);

        // fix some weird chars.
        //$data = preg_replace('#[\x00-\x09\x0b-\x1f]#su', '', $data);
        //echo $data;
        //die();
        return $data;
    }

    public static function cleanUrl(string $url): string
    {
        return 'http://' . str_replace([
                'http://',
                'https://'
            ], '', $url);
    }

    public static function cleanTextTest($data, $utfdecode = 0)
    {
        $data = utf8_decode($data);
        $data = preg_replace(
            '#[^~\r\n\ta-z \xDC\xFC\xF6\xD6\xC4\xE4\xDF0-9\.:,;=\-_\+\*&\?!/{}\(\)\[\]%$€<>\|\\\]#isu',
            ' ',
            $data
        );
        $data = preg_replace('#[\r\n\t]#s', '', $data);
        $from = [
            "#'#",
            '#"#'
        ];
        $to = [
            '\u0027',
            '\u0022'
        ];
        $data = preg_replace($from, $to, $data);
        $data = trim($data);
        return $data;
    }

    /**
     * Generate alias for a given string
     *
     * @param string $string
     * @return  string
     */
    public static function alias(string $string, string $spaceDelimiter = '-'): string
    {
        $string = trim(mb_strtolower($string));
        /*
            $umlaute = Array("/ä/", "/ö/", "/ü/", "/Ä/", "/Ö/", "/Ü/", "/ß/");
            $replace = Array("ae", "oe", "ue", "ae", "oe", "ue", "ss");
            $string = preg_replace($umlaute, $replace, $string);
         */
        $string = str_replace([
            '&',
            ',',
            '.',
            '!',
            '?'
        ], '-', $string);
        $string = str_replace(' ', $spaceDelimiter, $string);
        // replace “ ”
        $string = str_replace([
            '“',
            '”'
        ], '"', $string);
        $string = preg_replace('/[^@0-9' . $spaceDelimiter . '\-_\p{L}\'"’]/iu', '', $string);
        $string = preg_replace('/[-]{2,}/', '-', $string);
        //$string = preg_replace('#^-(.*)-$#', '\\1', $string);
        return $string;
    }

    /**
     * Generate a strict URL-safe slug.
     *
     * Notes:
     * - Normalizes diacritics (e.g. München -> muenchen)
     * - Lowercases
     * - Replaces any non [a-z0-9-_] characters with the delimiter
     * - Collapses repeated delimiters and trims them from both ends
     */
    public static function slug(string $string, string $delimiter = '-'): string
    {
        $string = trim($string);
        if ($string === '') {
            return '';
        }

        // Normalize diacritics to ASCII-ish equivalents (e.g. ü -> ue)
        $string = self::normalizeDiacritics($string);
        $string = mb_strtolower($string);

        // Normalize common punctuation/spaces to delimiter
        $string = str_replace([
            '&',
            ',',
            '.',
            '!',
            '?',
            ':',
            ';',
            '/',
            '\\',
            "'",
            '"',
            '’',
            '“',
            '”',
            '(',
            ')',
            '[',
            ']',
            '{',
            '}',
        ], $delimiter, $string);

        // Replace any remaining invalid chars with delimiter
        $string = preg_replace('/[^a-z0-9\-_]+/u', $delimiter, $string);

        // Collapse repeated delimiters
        $delim = preg_quote($delimiter, '/');
        $string = preg_replace('/' . $delim . '{2,}/', $delimiter, $string);

        // Trim delimiters from both ends
        $string = trim($string, $delimiter);

        return $string;
    }

    /**
     * Alias function for keywords..
     * @param     $string
     * @return
     */
    public static function alias_keyword(string $string): string
    {
        $string = trim($string);
        //$string = preg_replace('#[\r\n\t]#u', '', $string);
        $string = preg_replace('#([^a-z0-9_\-& ])#iu', '_$1_', $string);
        // cos there is é & è
        $string = preg_replace('#é#iu', 'ée', $string);
        $string = str_replace('__', '', $string); // WHAT IS THIS?!
        return $string;
    }

    /**
     * @param $keyword
     * @return mixed
     */
    public static function quoteKeyword(string $keyword): string
    {
        return str_replace([
            //'&apos;',
            "'",
            '"'
        ], [
            //"&rsquo;",
            "\'",
            '&quot;'
        ], $keyword);
    }

    /**
     * Clean keywords array
     * @param     $string
     * @return
     */
    public static function clean_keywords(array $array, bool $removeDuplicates = false): ?array
    {
        $auxarray = [];
        foreach ($array as $keyword) {
            if ($removeDuplicates) {
                $keyword = self::remove_consecutive_duplicate($keyword);
            }
            $auxarray[] = self::clean_keyword($keyword);
        }
        return self::array_unique($auxarray);
    }

    /**
     * Removes consecutive duplicates from an string
     * eg: seo for seo => seo for seo
     *     seo for seo seo => seo for seo
     * @param $text
     * @return array
     */
    public static function remove_consecutive_duplicate(string $text): string
    {
        $arr = explode(' ', $text);

        $countArr = count($arr ?? []);
        for ($i = 0; $i < $countArr; $i++) {
            if (isset($arr[$i - 1]) && $arr[$i] == $arr[$i - 1]) {
                unset($arr[$i]);
            }
        }
        return implode(' ', $arr);
    }

    /**
     * Clean keyword
     *
     * @param string $string
     * @param bool   $useWhitelist
     *
     * @return string
     */
    public static function clean_keyword(string $string, bool $useWhitelist = false): string
    {
        //@todo why don't use \p{L} for a-z AND utf8 diacrtics? why use umlauts array?
        //lowercase for normalization
        $string = mb_strtolower($string);
        //replace html entities to normal characters
        $string = html_entity_decode($string);
        // fast patch for keywords sent by RC ui.js (eg: children's clothes)
        $string = str_replace([
            '&#39;',
            '’'
        ], "'", $string);
        //replace unicode string representation with coresponding utf8 char
        $string = str_replace([
            'u00df',
            'u0153',
            'u00e9',
            'u00e8',
            'u00eb',
            'u00e1',
            'u00e0',
            'u00e4',
            'u00e2',
            'u00e3',
            'u00f6',
            'u00f4',
            'u00f3',
            'u00f2',
            'u00ef',
            'u00ed',
            'u00ee',
            'u015f',
            'u0219',
            'u0163',
            'u021b',
            'u00fc',
            'u00f9',
            'u00fb',
            'u00f1',
            'u00ff'
        ], Datafilter::$umlauts, $string);
        $string = str_replace([
            'ã£â¶',
            'ã£â¤',
            'ã£â¼',
            'ã£â'
        ], [
            'ö',
            'ä',
            'ü',
            'ß'
        ], $string);
        //error fron json_decode( json_encode( utf8_encode( $string)))   string already encoded UTF-8
        //$string = str_replace(array("ã", "å", "ã©", "ã¨", "ã«", "ã¡", "ã ", "ã¤", "ã¢", "ã£", "ã¶", "ã´", "ã³", "ã²", "ã¯", "ã", "ã®", "å", "è", "å£", "è", "ã¼", "ã¹", "ã»", "ã±", "ã¿"), Datafilter::$umlauts, $string);
        //remove " from not <number>" or <space>" e.g.: 3.2" tft touchscreen 19 " 1/4" we3/4" telephone pda3" "mobile" p05-i92" test " => 3.2" tft touchscreen 19 " 1/4" we3/4 telephone pda3 mobile p05-i92 test
        //$string = preg_replace(array("/\"([\w])/u", "/([a-z\p{L}][\/\d\.\-]* *)\"/u"), array('$1', '$1'), $string);
        //convert to space everything that is not allowed
        $special = implode("\\", self::$special);
        //$umlauts = implode('', self::$umlauts);
        // replace “ ”
        $string = str_replace([
            '“',
            '”'
        ], '"', $string);
        $string = str_replace([
            ',',
        ], ' ', $string);
        //$regex = '[^\'"’a-z ' . $special . self::$special_chars . '0-9]';
        $regex = '[^a-z "' . $special . self::$special_chars . '0-9]';
        // var_dump($regex);
        $string = preg_replace("#$regex#isu", '', $string);
        //compress multiple spaces to one
        $string = preg_replace('#[\s]{2,}#', ' ', $string);
        //trim left quotes, hypertable problem
        //$string = ltrim($string, " '\"!:,.;/_|])}?");
        //$string = rtrim($string, " :,.;/_|[({?");
        return trim($string);
    }

    /**
     * Clean keyword
     * @param     $string
     * @return
     */
    /*
        public static function clean_keyword($string, $useWhitelist = false) {
        //                $string = utf8_encode($string);
        //                $string = Encoding::toUTF8($string);  ß$%+"-
        $string = mb_strtolower(trim($string, " \t\n\r\0\x0B.,!@#^&*<>=~|_"));
        if ($useWhitelist)
        $string = preg_replace('#[^a-z ' . self::$special_chars . '0-9\.\-_&%$Ä\'"/]#isu', ' ', $string);
        else {
        $string = str_replace('&amp;', '&', $string);
        $string = str_replace(array(';', ')', '(', ']', '[', '{', '}', '?', ',', '!', '#'), '', $string);
        }
        $string = preg_replace('#[\s]{2,}#', ' ', $string);
        return $string;
        } */

    /**
     * Alow only unique values, takes cares of extra spaces and it's case InSenSiTiVe
     *
     * @param    $array
     */
    public static function array_unique(array $array): array
    {
        $aux = [];
        foreach ($array as $element) {
            // takes care of diacritics, replace into standard format, eg: ä => ae => a
            $element_key = self::filter_diacritics($element);
            // skip empty elements
            if (!$element_key) {
                continue;
            }
            if (!isset($aux[$element_key])) {
                $aux[$element_key] = trim($element);
            }
        }
        return array_values($aux);
    }

    /**
     * Filter diacritics.
     * This function is useful for comparing same word written in different styles.
     * Eg: Koln, Koeln, Köln
     * @param    $text
     */
    public static function filter_diacritics(string $text, array $allowedDiacritics = []): string|bool
    {
        $text = mb_strtolower(trim($text));
        if (!$text) {
            return false;
        }
        // replace into standard format, eg: ä => ae
        $umlaute = [];
        if (!isset(self::$allowedDiacriticsCache)) {
            $umlaute = [
                '/ä/' => 'ae',
                '/ö/' => 'oe',
                '/ü/' => 'ue',
                '/Ä/' => 'AE',
                '/Ö/' => 'OE',
                '/Ü/' => 'UE',
                '/ß/' => 'ss',
                '/é/' => 'e',
                '/è/' => 'e',
                '/ê/' => 'e',
                '/Ě/' => 'E',
                '/È/' => 'E',
                '/É/' => 'E',
                '/â/' => 'a',
                '/Â/' => 'A',
                '/í/' => 'i'
            ];
            if ($allowedDiacritics === null) {
                return $text;
            }
            foreach ($allowedDiacritics as $key => $value) {
                if (isset($umlaute['/' . $value . '/'])) {
                    unset($umlaute['/' . $value . '/']);
                }
                //$allowedDiacritics[$key] = "/".$number."/";
            }
        }
        $text = preg_replace(array_keys($umlaute), array_values($umlaute), $text);
        // replace from ae to a
        //$text = str_replace(array ("ae", "oe", "ue", "ss" ), array('a', 'o', 'u', 's'), $text);
        //
        return $text;
    }

    /**
     * Extract the domain and path from a URL
     *
     * @param string $url
     * @param bool|false $asString
     * @param bool|false $removewww
     * @param bool|true $skipFileExclusion
     * @return object|string
     */
    public static function getDomainAndPath(
        string $url,
        bool $asString = false,
        bool $removewww = false,
        bool $skipFileExclusion = true
    ): ?\stdClass {
        $domain = self::domain($url, false, false, $removewww);
        $path = '';

        $url = preg_replace('#[/]{2,}#', '/', $url);
        $pathPos = strripos($url, $domain . '/');

        if ($domain && $pathPos !== false) {
            $path = substr($url, strripos($url, $domain . '/') + strlen($domain) + 1);

            // ok, if we got a beginning # (as in ajax-based sites, like wix) than this is not a valid subfolder..
            if (substr($path, 0, 1) == '#') {
                $path = '';
            }

            // @TODO Maybe split the path into more than 2 parts and validate them individually
            // (e.g., paths like domain/{path_1}/{path_2}/..../{path_n})
            $pathExploded = preg_split(
                '#[/\?\#]+#',
                $path
            ); // also take care of url such as omnomn.wix.com/hotel#!book-a-room
            // @TODO The pattern can be improved to allow more character types
            if (!preg_match('/^(?!\.)(?!.*\.$)(?!.*?\.\.)[a-z\-0-9.]+$/i', end($pathExploded))) {
                // if last element not alphanum and is not like x-m-y then remove it
                array_pop($pathExploded);
            }

            $path = implode('/', $pathExploded);

            // @TODO Review the following code because in it's present form it excludes more than just files
            if (!$skipFileExclusion && strpos($path, '.')) {
                // if it's a file, we set no path
                $path = '';
            }

            if ($path) {
                $path = '/' . $path;
            }
        }

        if ($asString) {
            return $domain . $path;
        }

        return (object)[
            'domain' => $domain,
            'path' => (string)$path
        ];
    }

    /**
     * Get domain from a full URL.
     * @param $url
     * @param bool $fulldomain
     * @param bool $subdomain_removal
     * @param bool $removewww
     * @return int|mixed|string
     */
    public static function domain(
        string $url,
        bool $fulldomain = false,
        bool $subdomain_removal = false,
        bool $removewww = true
    ): string|bool {
        //$url = self::encode_utf8($url);
        // remove the rest from space. eg:
        if (empty($url)) {
            return false;
        }
        $url = is_array($url) ? current($url) : $url; // sometimes companyUrl received from Abl::search is an array
        $url = preg_replace('#(\s+)#', '', $url);
        $url = preg_replace('#[/]{2,}$#', '/', $url);
        $url = strtolower($url);
        if (!preg_match('#^(ftp|https?|gophher)://#i', $url)) {
            $url = 'http://' . $url;
        }
        // must imrpove this
        $newurl = parse_url($url);
        //print_r($newurl);
        if (isset($newurl['port']) && $newurl['port'] != 80) {
            $portAdd = ':' . $newurl['port'];
        } else {
            $portAdd = '';
        }
        if ($subdomain_removal) {
            // is IP?
            if (!preg_match('#^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}#', $newurl['host'])) {
                preg_match('#(?P<domain>[^\.]+\.([a-z]{2,3}\.)?[a-z]{2,15})$#i', $newurl['host'], $match);
                //print_R($match);//die();
                if (!isset($match['domain'])) {
                    return -1;
                    //die('NO DOMAIN FOUND @subdomain:' . $newurl['host']);
                }
                return $match['domain'];
            }
        }
        $newurl['host'] = strtolower($newurl['host']);

        if ($fulldomain) {
            $domain = $newurl['scheme'] . '://' . $newurl['host'] . $portAdd;
        } elseif ($removewww) {
            // DAMN BUG WITH sites as weinimwww.de
            // OTHER ISSSUES: http://www.ck/aitutaki/index.htm
            $domain = preg_replace('#^www\.(?![a-z]{2,5}$)#i', '', $newurl['host']);
        } else {
            $domain = $newurl['host'];
        }
        return $domain;
    }

    /**
     * Get topdomain of an url.
     * eg: domain.de => de
     *
     * @param     $url
     * @return
     *
     */
    public static function topdomain(string $url): string|bool
    {
        $domain = self::domain($url);
        preg_match('#\.(?P<topdomain>[^\.]+)$#', $domain, $match);
        return $match['topdomain'];
    }

    /**
     * Return subdomain from a domain only.
     *
     * @param     $url
     * @return     R
     */
    public static function subdomain(string $url): ?string
    {
        $domain = self::domain($url);
        preg_match('#(?P<subdomain>.*?)\.(?P<domain>[^\.]+\.[a-z]{2,4})$#i', $domain, $match);
        if (isset($match['subdomain'])) {
            return $match['subdomain'];
        } else {
            return null;
        }
    }

    /**
     * Validate URL
     * SNIPET : http://www.blog.highub.com/regular-expression/php-regex-regular-expression/php-regex-validating-a-url/
     * Allows for port, path and query string validations
     * @param string $url string containing url user input
     * @return   bool     Returns TRUE/FALSE
     */
    public static function validateURL(string $url): bool
    {
        $url = trim($url);
        $url = preg_replace('#^http(s)?://#i', '', $url);
        $url = preg_replace('#^www#i', '', $url);
        return preg_match('#^[^\]\[\{\}\(\)~\#!@$%\^\*\=\+\,\/\\`\s\t\r\n]{2,100}\.[a-z]{2,50}#iu', $url);
        //return preg_match('#^(http(s)?://)?(www\.)?[^\]\[\{\}\(\)~\#!@$%\^\*\=\+\,\/\\`\s\t\r\n]{2,100}\.[a-z]{2,4}#iu', $url);
        //$pattern = '/^(([\w]+:)?\/\/)?(([\d\w]|%[a-fA-f\d]{2,2})+(:([\d\w]|%[a-fA-f\d]{2,2})+)?@)?([\d\w][-\d\w]{0,253}[\d\w]\.)+[\w]{2,4}(:[\d]+)?(\/([-+_~.\d\w]|%[a-fA-f\d]{2,2})*)*(\?(&amp;?([-+_~.\d\w]|%[a-fA-f\d]{2,2})=?)*)?(#([-+_~.\d\w]|%[a-fA-f\d]{2,2})*)?$/ui';
        // return preg_match($pattern, $url);
        try {
            parse_url($url);
        } catch (Exception $e) {
            return false;
        }
        if (!preg_match('#.*?\..*?#', $url)) {
            return false;
        }
        return true;
    }

    /**
     * Validate an domain. http and www. are allowed.
     *
     * @param     $url
     * @return
     *
     */
    public static function validateDomain(string $url): bool
    {
        if (preg_match('#^(http://)?(www\.)?[' . self::$special_chars . 'a-z0-9\._\-]+\.[a-z]{2,6}/?$#iu', $url)) {
            return true;
        }
        return false;
    }

    /**
     * Check if a valid mail.
     *
     * @param string $text
     * @param int $partial
     * @return  int
     */
    public static function validEmail(string $text, bool $partial = false): bool
    {
        $start = '^';
        $end = '$';
        if ($partial) {
            $start = '';
            $end = '';
        }
        if (preg_match('/' . $start . "[A-Za-z0-9._\-\+]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,24}$end/", $text)) {
            return true;
        }
        return false;
    }

    /**
     * Count words in a text.
     *
     * @param     $text
     */
    public static function wordcount(string $text): int
    {
        $words = self::breakwords($text);
        return count($words ?? []);
    }

    /**
     * Break text into words array.
     *
     * @param string $text
     * @return  array
     */
    public static function breakwords(string $text, $breakMoreSpecialChars = false, $filter = false): ?array
    {
        $text = str_replace("\t", ' ', $text);
        if ($breakMoreSpecialChars) {
            $breakChars = '\-/';
            // convert _ because if we want to delimt by this char the _ is part of an word and not as separator (see word \boundary).
            $text = str_replace('_', '-', $text);
        } else {
            $breakChars = '';
        }

        if (!$text) {
            return [];
        }
        $wordstmpArr = [];
        $text = strtolower($text);
        $text = self::cleanUrlsAndEmails($text);
        // now breakup!
        $special_chars = self::$special_chars . self::$exclude_chars;
        //preg_match_all('#(?P<word>['.$special_chars.']*\b[0-9a-z'.$special_chars.'][^\r\n\t, \.\?&!'.$breakChars.'/\']*[0-9a-z'.$special_chars.']*\b['.$special_chars.']*)#iu', $text, $wordstmpArr);
        //preg_match_all('#(?P<word>[0-9a-z' . $special_chars . '\#_]+)#isu', $text, $wordstmpArr); // this is old
        // the second part is for preserving things likle iso 3834-2 or update 2.0 etc
        // \;\& was added because ampersand was being returned as amp instead of &amp; . This was done because the Text Optimization tool was not correctly comparing the text with keywords containing ampersand
        preg_match_all(
            '#(?P<word>([0-9]+[\.\-+]*[0-9]+)|([0-9a-z' . $special_chars . '\#_\;\&]+))#isu',
            $text,
            $wordstmpArr
        );
        // filter the text and return aliased words (optional)
        if ($filter) {
            $filtered_words = [];
            foreach ($wordstmpArr['word'] as $word) {
                $word = trim($word);
                if (is_numeric($word)) {
                    continue;
                }
                if (strlen($word) <= 2) {
                    continue;
                }
                // we convert the special DE chars, so we can match more easely in text :). The same should be applied to the text.
                $filtered_words[] = self::aliasv2($word);
            }
            // we need only one occurence of the word.
            return array_unique($filtered_words);
        }
        unset($text);
        return $wordstmpArr['word'];
    }

    /**
     * Clean URLS and emails from a text.
     * @param     $text
     * @return
     */
    public static function cleanUrlsAndEmails(string $text): string
    {
        // first and first REMOVE sites.
        $text = preg_replace([
            '#http(s)?://(www\.)?[^\s\t\r\n]+#is',
            '#www\.[^\s\t\r\n]+#is'
        ], '', $text);
        // remove emails.. [1] // as in info`[at]`loew.ag & http://pr-ranklist.de/impressum.php
        $pAround = [
            '@',
            ' ?\[ ?at ?\] ?',
            '`\[at\]`',
            ' ?\(at\) ?',
            '\(via\)'
        ];
        $pDot = [
            '\.',
            '\[dot\]',
            '\(dot\)'
        ];
        $text = preg_replace(
            '#(?P<email>[a-z]([_a-z0-9\-]+)(\.[_a-z0-9\-]+)*(' . implode(
                '|',
                $pAround
            ) . ')([a-z0-9-]+)(\.[a-z0-9-]+)*(' . implode(
                '|',
                $pDot
            ) . ')[a-z]{2,4})#is',
            '',
            $text
        );
        return $text;
    }

    /**
     * Generate alias for a given string
     * v2
     * @todo: replace old function, but first we must make sure it's ``backward compatible``
     *
     * @param string $string
     * @return  string
     */
    public static function aliasv2(string $string, string $spaceDelimiter = '-'): string
    {
        $umlaute = [
            '/ä/',
            '/ö/',
            '/ü/',
            '/Ä/',
            '/Ö/',
            '/Ü/',
            '/ß/'
        ];
        $replace = [
            'ae',
            'oe',
            'ue',
            'ae',
            'oe',
            'ue',
            'ss'
        ];
        $string = preg_replace($umlaute, $replace, $string);

        $string = trim(strtolower($string));
        $string = str_replace([
            '&',
            ',',
            '.',
            '!',
            '?'
        ], '-', $string);
        $string = str_replace(' ', $spaceDelimiter, $string);

        $string = preg_replace('/[^a-zA-Z0-9\-_\p{L}]/', '', $string);
        $string = preg_replace('/[-]{2,}/', '-', $string);
        $string = preg_replace('/[-]{1,}$/', '', $string);
        $string = preg_replace('/^[-]{1,}/', '', $string);
        //$string = preg_replace('#^-(.*)-$#', '\\1', $string);
        return $string;
    }

    /**
     * Clean text. Leave onyl words separated by space.
     * @param     $text
     * @return
     */
    public static function textwords(string $text): string
    {
        $text = mb_strtolower($text);
        $words = self::breakwords($text);
        //var_dump($words);
        return implode(' ', $words);
    }

    /**
     * Encode text using different functions, usefull on data passing.
     *
     * @param $text
     * @return
     */
    public static function encode(string $text): string
    {
        //$text = json_encode($text);
        $text = base64_encode($text);
        $text = str_replace('/', '_SL_', $text);
        $text = str_replace('+', '_PL_', $text);
        return $text;
    }

    /**
     * Decode text using different functions, usefull on data passing.
     *
     * @param $text
     * @return
     */
    public static function decode(string $text): string
    {
        $text = str_replace('_SL_', '/', $text);
        $text = str_replace('_PL_', '+', $text);
        $text = base64_decode($text);
        //$text = json_decode($text);
        return $text;
    }

    public static function keyword_code(string $keyword): string
    {
        //if ((int)$keyword > 0)
        return 'KW__' . $keyword;
        //else return $keyword;
    }

    public static function keywords_decode(array $keywords): array
    {
        foreach ($keywords as $keyword) {
            $keywords_aux[] = self::keyword_decode($keyword);
        }
        return $keywords_aux ?? [];
    }

    public static function keyword_decode(string $keyword): string
    {
        return str_replace('KW__', '', $keyword);
    }

    public static function stringToHex(string $string): string
    {
        // $string = 'ÄäÖöÜüß€ÀÂÄÈÉÊËÎÏÔŒÙÛÜŸàâäèéêëîïôœùûüÿÁÉÍÓÚÑÜáéíóúñüÀÈÉÌÒÓÙàèéìòóùăîâşţĂÎÂŞŢ';
        preg_match_all('#[^\.]{1}#u', $string, $chars);
        $aux = [];
        foreach ($chars[0] as $char) {
            $aux[$char] = dechex(self::ordUTF8($char));
            $aux[$char] = '\x' . strtoupper($aux[$char]);
        }
        $aux = array_unique($aux);
        $aux = implode('', $aux);
        return $aux;
    }

    public static function ordUTF8(string $c, int $index = 0, ?int &$bytes = null): int|bool
    {
        $len = strlen($c);
        $bytes = 0;

        if ($index >= $len) {
            return false;
        }

        $h = ord($c[$index]);

        if ($h <= 0x7F) {
            $bytes = 1;
            return $h;
        } elseif ($h < 0xC2) {
            return false;
        } elseif ($h <= 0xDF && $index < $len - 1) {
            $bytes = 2;
            return ($h & 0x1F) << 6 | (ord($c[$index + 1]) & 0x3F);
        } elseif ($h <= 0xEF && $index < $len - 2) {
            $bytes = 3;
            return ($h & 0x0F) << 12 | (ord($c[$index + 1]) & 0x3F) << 6 | (ord($c[$index + 2]) & 0x3F);
        } elseif ($h <= 0xF4 && $index < $len - 3) {
            $bytes = 4;
            return ($h & 0x0F) << 18 | (ord($c[$index + 1]) & 0x3F) << 12 | (ord($c[$index + 2]) & 0x3F) << 6 | (ord(
                        $c[$index + 3]
                    ) & 0x3F);
        } else {
            return false;
        }
    }

    public static function compare_domains(string $domain, string $domain2): bool
    {
        $countryTld = 0;
        $domain = self::domain_tld($domain);
        $domain2 = self::domain_tld($domain2);

        $countryCodes = [
            'de',
            'at',
            'ch'
        ];
        if ($domain->domain != $domain2->domain) {
            return false;
        }
        if ($domain->tld == $domain2->tld) {
            return true;
        }
        if (in_array($domain->tld, $countryCodes)) {
            $countryTld++;
        }
        if (in_array($domain2->tld, $countryCodes)) {
            $countryTld++;
        }
        if ($countryTld == 1) {
            return true;
        }
        return false;
    }

    public static function domain_tld(string $domain): ?string
    {
        preg_match('#(?P<domain>.*?)\.(?P<tld>[a-z]{2,6})$#', $domain, $match);
        $return = new stdClass();
        $return->domain = isset($match['domain']) ? $match['domain'] : null;
        $return->tld = isset($match['tld']) ? $match['tld'] : null;
        return $return;
    }

    /**
     * Delimiter a text by adding comma after each div, span, td,  - (notice teh space between - ).
     *
     *
     * @param     $text
     */
    public static function delimiterHtmlText(string $text, string $delimiter_output = '###'): string
    {
        $delimiter = ',';
        // first we take care of html entities..
        $text = self::entity_decode($text);
        // first we save into an array the infromation fram a & img (title & alt)
        preg_match_all('#<(?:img|a) .*?(?:title|alt)=["\'](.*?)["\'].*?>#isu', $text, $matches);
        if (isset($matches[1])) {
            $matches[1] = array_filter($matches[1]);
            $text = implode(',', $matches[1]) . ',' . $text;
        }
        // now we inject a delimiter in the ending tags.
        $text = preg_replace(
            '#(</(span|div|p|td|h[1-6]*|fieldset|option|input|form|body|blockquote|dt|dd|li|ul|ol|title|head)>|<(br|hr) ?/?>)#isu',
            " $delimiter$1",
            $text
        );
        // remove style, js, object and other stuff that may result in junk text.
        $text = preg_replace(
            '#<script([^>]*)>(.*?)</script( )?>|<style([^>]*)>(.*?)</style>|<object([^>]*)>(.*?)</object>|<embed([^>]*)>(.*?)</embed>|<!--(.*?)-->#siu',
            '',
            $text
        );
        // strip tags
        $text = strip_tags($text);
        // strip urls & emails.
        $text = self::cleanUrlsAndEmails($text);
        // take care of other punctuations: eg: . ; ? !
        $text = str_replace([
            '.',
            ';',
            ':',
            '!',
            '?',
            ' - ',
            ' – ',
            '|',
            '/',
            '\\',
            '*',
            '+',
            '(',
            ')',
            '[',
            ']',
            '{',
            '}',
            ' & '
        ], ',', $text);
        // some words delimit text, such as: and, or, und, oder
        $text = preg_replace('#\b(and|or|und|oder)\b#i', $delimiter, $text);
        // some cleanup
        $text = str_replace([
            "$delimiter ",
            " $delimiter",
            " $delimiter "
        ], $delimiter, $text);
        // convert text to small case
        $text = mb_strtolower($text);
        // break up the text into words array.
        self::$exclude_chars = $delimiter;
        $words = self::breakwords($text);
        // put back in text
        $text = implode(' ', $words);
        // replace $delimiter into $delimiter_output
        $text = str_replace($delimiter, $delimiter_output, $text);
        return $text;
    }

    /*
     * Reliable only for DE/AT/CH domains.
     * Compare domains and check if they are the same domain
     */

    /**
     * Prioritize array's elements by a keyword.
     *
     * @param     $array
     * @param     $keyword
     * @param     $limit
     */
    public static function prioritizeArrayElementsByKeyword(
        array $array,
        string $keyword,
        string $key_element = 'text',
        int $limit = 500
    ): array {
        $aux = [];
        foreach ($array as $element) {
            $value = trim($element->$key_element);
            if (!$value) {
                $element->density = -1; // force down.
                continue;
            }
            // ok, ATM that function is not that smart, cannot work on multi keywords properly, but hey! better than nothing.
            $density = SeoTools::getKeywordDensity($value, $keyword);
            $element->density = $density->partial_density;
        }
        // sort
        usort($array, [
            'Datafilter',
            'compare_array'
        ]);

        return $array;
    }

    public static function compare_array(array $a, array $b): int
    {
        if ($a->density == $b->density) {
            return 0;
        } else {
            return ($a->density < $b->density);
        }
    }

    /**
     * makes a word like ubersetzungen to (ü|ue|Ü)bersetzungen
     * @param string $keyword
     * @return string
     */
    public static function getDiacriticInsensitivePregString(string $keyword): string
    {
        $umlaute = [
            'ä',
            'ö',
            'ü',
            'ß',
            'é',
            'è',
            'ê',
            'â'
        ];
        $umlaute2 = [
            'ae',
            'oe',
            'ue',
            'ss',
            'e',
            'e',
            'e',
            'a'
        ];
        $umlaute3 = [];
        $umlaute4 = [];
        foreach ($umlaute as $index => $umlaut) {
            $umlaute3[] = '(' . mb_strtoupper($umlaute[$index]) . '|' . mb_strtoupper($umlaute2[$index]) . ')';
            $umlaute4[] = $umlaute[$index] . '|' . mb_strtoupper($umlaute[$index]);
        }
        $keyword = str_replace($umlaute2, $umlaute3, $keyword);
        $keyword = str_replace($umlaute, $umlaute3, $keyword);
        $keyword = mb_strtolower($keyword);
        $keyword = str_replace($umlaute, $umlaute4, $keyword);
        return $keyword;
    }

    /**
     * Normalize diacritcs from an array. eg: Spaß = spass.
     * @param    $array
     * @return
     */
    public static function normalize_diacritcs(array $array): array
    {
        foreach ($array as $key => $element) {
            $array[$key] = self::filter_diacritics($element);
        }
        return $array;
    }

    /**
     * Generates smart combinations of words from the given query after applying aliases and filters by minimum length.
     *
     * @param string $query The input string to generate combinations from.
     * @param int $minLength The minimum length of each combination to include in the result. Defaults to 0.
     * @return array An array of unique combinations that meet the specified minimum length.
     */
    public static function getSmartCombinations(string $query, int $minLength = 0): array
    {
        // break up by words
        $words = self::breakwords($query);
        // apply alias for each word
        $text_aliased = [];
        foreach ($words as $word) {
            $text_aliased[] = self::aliasv2($word);
        }
        $combinations = [];
        $combinations = array_merge($combinations, self::getCombinations($query, $minLength));
        $combinations = array_merge(
            $combinations,
            self::getCombinations(implode(' ', self::breakwords($query, 1)), $minLength)
        );
        $combinations = array_merge($combinations, self::getCombinations(implode(' ', $text_aliased), $minLength));
        $combinations = array_unique($combinations);
        $final_combinations = [];
        foreach ($combinations as $combination) {
            if (strlen($combination) >= $minLength) {
                $final_combinations[] = $combination;
            }
        }
        return $final_combinations;
    }

    /**
     * Generate all possible combinations of words up to a specified number of words in sequence from a given query.
     *
     * @param string $query The input string from which to generate word combinations.
     * @return array An array of word combinations from the input query.
     */
    public static function getCombinations(string $query): array
    {
        $query = preg_replace('/\s\s+/', ' ', $query);
        $elements = explode(' ', $query);
        $aux = [$query];
        $maxwords = 3;
        $elementCount = count($elements ?? []);
        for ($i = 0; $i < $elementCount; $i++) {
            $aux [] = $elements [$i];
            $tmpStr = $elements [$i];
            for ($j = 1; $j < $maxwords; $j++) {
                if (array_key_exists($i + $j, $elements)) {
                    $tmpStr .= ' ' . $elements [$i + $j];
                    $aux [] = $tmpStr;
                }
            }
        }
        return $aux;
    }

    /**
     * Cleanses the request data by removing 'script' strings and normalizing specified values to integers.
     *
     * @param array|null $request The associative array of request data that may contain potentially unsafe values.
     * @param array $intValues List of keys in the request array whose corresponding values should be cast to integer.
     * @return array|null The cleansed request array, or null if the input was null.
     */
    public static function cleanRequestFromScript(?array $request = null, array $intValues = []): ?array
    {
        foreach ($request as $index => $value) {
            if (is_array($intValues) && in_array($index, $intValues)) {
                $request[$index] = (int)$value ? (int)$value : '';
            } else {
                $request[$index] = str_replace('script', '', $value);
            }
        }
        return $request;
    }

    /**
     * Validates a phone number against specified country code standards.
     *
     * @param string $number The phone number to validate.
     * @param string|null $countryCode The ISO 3166-1 two-letter country code (e.g., 'DE'). If null, and number lacks an international prefix, the function returns false.
     * @return bool True if the phone number is valid according to the country's standard, false otherwise.
     */
    public static function validatePhoneNumber(string $number, ?string $countryCode = 'de'): bool
    {
        $phoneUtil = PhoneNumberUtil::getInstance();
        try {
            if ($countryCode) {
                $parsedNumber = $phoneUtil->parseAndKeepRawInput($number, strtoupper($countryCode));
            } else {
                $parsedNumber = $phoneUtil->parse($number);
            }
            return $phoneUtil->isValidNumber($parsedNumber);
        } catch (NumberParseException $e) {
            return false;
        }
    }

    /**
     * Validates a phone number based on the specific country code and additional regional constraints.
     *
     * @param string $number The phone number to validate.
     * @param string $countryCode The ISO 3166-1 two-letter country code (e.g., 'US').
     * @param array $regionCountriesList Optional list of additional country codes that are considered valid for the region.
     * @return bool True if the phone number is valid for the given country or region, false otherwise.
     */
    public static function validatePhoneNumberByCountry(
        string $number,
        string $countryCode,
        array $regionCountriesList = []
    ): bool {
        try {
            $number = str_replace('&nbsp', ' ', $number);
            $phoneUtil = PhoneNumberUtil::getInstance();
            $parsedNumber = $phoneUtil->parseAndKeepRawInput($number, strtoupper($countryCode));
            if ($phoneUtil->isValidNumber($parsedNumber)) {
                $regionCode = $phoneUtil->getRegionCodeForNumber($parsedNumber);
                $countryCode = strtoupper($countryCode);
                if ($regionCode == $countryCode) { //check if the region code returned from filtering is the same from site
                    return true;
                } elseif (!empty($regionCountriesList) && array_search(
                        strtolower($regionCode),
                        $regionCountriesList,
                        true
                    )) {
                    return true;
                } else {
                    // there is a niche situation where the country prefix is the same, but the region code is different, for example Isle of Man and GB
                    $regionCodes = $phoneUtil->getRegionCodesForCountryCode($parsedNumber->getCountryCode());
                    if (in_array($countryCode, $regionCodes)) {
                        foreach ($regionCodes as $regionCode) {
                            if ($phoneUtil->isValidNumberForRegion($parsedNumber, $regionCode)) {
                                return true;
                            }
                        }
                    }
                }
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Formats a phone number into a national format (e.g., "0451 495905").
     *
     * @param string $number The phone number to be formatted.
     * @param string $countryCode The ISO 3166-1 alpha-2 country code (e.g., 'DE'). Default is 'DE'.
     * @return string The formatted national phone number or an empty string if normalization fails.
     */
    public static function normalizePhoneNumberNational(string $number, string $countryCode = 'de'): string
    {
        $number = Datafilter::normalizePhoneNumber($number, $countryCode, 2);
        if (!$number) {
            return $number;
        }
        $number = '0' . substr($number, 0, 3) . ' ' . substr($number, 3);
        return $number;
    }

    /**
     * Normalizes phone numbers to different formats based on the specified format and country code.
     * Available formats:
     * - PhoneNumberFormat::INTERNATIONAL
     * - PhoneNumberFormat::NATIONAL
     * - PhoneNumberFormat::E164
     *
     * @param string $number The phone number to be normalized.
     * @param string $countryCode The ISO 3166-1 alpha-2 country code, default is 'DE'.
     * @param int $format The format to use for the phone number normalization.
     * @param bool $returnFragments If true, returns an object with countryCode and nationalNumber, otherwise returns formatted string.
     * @return string|object The formatted phone number or an object with phone number fragments based on $returnFragments.
     */
    public static function normalizePhoneNumber(
        string $number,
        string $countryCode = 'de',
        int $format = PhoneNumberFormat::INTERNATIONAL,
        bool $returnFragments = false
    ) {
        $number = str_replace('&nbsp', ' ', $number);
        $phoneUtil = PhoneNumberUtil::getInstance();
        try {
            $parsedNumber = $phoneUtil->parseAndKeepRawInput($number, strtoupper($countryCode));
            if ($returnFragments) {
                return (object)[
                    'countryCode' => $parsedNumber->getCountryCode(),
                    'nationalNumber' => $parsedNumber->getNationalNumber()
                ];
            }
            if ($phoneUtil->isValidNumber($parsedNumber)) {
                return $phoneUtil->format($parsedNumber, $format);
            } else {
                return $number;
            }
        } catch (NumberParseException $e) {
            return $number;
        }
    }

    /**
     * Normalizes text by converting to lowercase, normalizing non-German diacritics, and filtering out special characters.
     *
     * @param string $text The text to be normalized.
     * @param string $whiteList A string containing characters to be preserved during normalization.
     * @return string The normalized and filtered text.
     */
    public static function normalizeAndFilterSpecialChars(string $text, string $whiteList = ''): string
    {
        $text = mb_strtolower($text);
        $text = self::normalizeNonGermanDiacritics($text, $whiteList);
        $text = self::filterSpecialChars($text, $whiteList);

        return trim($text);
    }

    /**
     * filters out all non german diacritics e.g. "é" => "e" etc.
     *
     *
     * @param $text
     */
    public static function normalizeNonGermanDiacritics(string $text): string
    {
        $diacritics_from = [
            'Á',
            'É',
            'Í',
            'Ó',
            'Ñ',
            'á',
            'é',
            'í',
            'ó',
            'ú',
            'ñ',
            'à',
            'â',
            'æ',
            'ç',
            'é',
            'è',
            'ê',
            'ë',
            'î',
            'ï',
            'ô',
            'œ',
            'ù',
            'û',
            'À',
            'Â',
            'Æ',
            'Ç',
            'É',
            'È',
            'Ê',
            'Ë',
            'Î',
            'Ï',
            'Ô',
            'Œ',
            'Ù',
            'Û'
        ];
        $diacritics_to = [
            'A',
            'E',
            'I',
            'O',
            'N',
            'a',
            'e',
            'i',
            'o',
            'u',
            'n',
            'a',
            'a',
            'a',
            'c',
            'e',
            'e',
            'e',
            'e',
            'i',
            'i',
            'o',
            'o',
            'u',
            'u',
            'A',
            'A',
            'A',
            'C',
            'E',
            'E',
            'E',
            'E',
            'I',
            'I',
            'O',
            'O',
            'U',
            'U'
        ];

        $text = str_replace($diacritics_from, $diacritics_to, $text);
        return $text;
    }

    /**
     * Takes out special chars and leave only alphanumeric text.
     *
     * @param $text
     * @return mixed
     */
    public static function filterSpecialChars(string $text, string $whiteList = ''): string
    {
        $whiteList = self::escapeRegexSpecialChars($whiteList);
        // remove all that is not a letter or number . \p{L} match any letter with diacritics too
        $text = preg_replace("#[^\p{L}0-9{$whiteList}]#uis", ' ', $text);
        $text = preg_replace('#\s{2,}#', ' ', $text);
        return $text;
    }

    /**
     * Escapes regex special chars.
     * To be used when we work with user input text that may contains special chars (?[]{} etc).
     *
     * @param $text
     * @return mixed
     */
    public static function escapeRegexSpecialChars(string $text): string
    {
        $aux = [];
        foreach (self::$regex_special_chars as $char) {
            $aux[] = "\\" . $char;
        }
        return str_replace(self::$regex_special_chars, $aux, $text);
    }


    /**
     * Reverse a string - supports UTF-8 encoding
     *
     * @param $str
     * @return string
     */
    public static function utf8_strrev(string $str): string
    {
        preg_match_all('/./us', $str, $ar);
        return implode(array_reverse($ar[0]));
    }

    /**
     * Converts utf chars to ucs (usefull for asian match against searches)
     * see: https://blogs.oracle.com/soapbox/entry/fulltext_and_asian_languages_with
     *
     * tks to    http://stackoverflow.com/questions/27940695/how-to-perform-mysql-fulltext-search-with-chinese-characters
     * @param $str
     * @param $s
     * @return string
     */
    public static function UTF2UCS(string $str, bool $s = false): string
    {
        $str = strtolower($str);
        $char = 'UTF-8';
        $arr = [];
        $out = '';
        $c = mb_strlen($str, $char);
        $t = false;

        for ($i = 0; $i < $c; $i++) {
            $arr[] = mb_substr($str, $i, 1, $char);
        }

        foreach ($arr as $i => $v) {
            if (preg_match('/\w/i', $v, $match)) {
                $out .= $v;
                $t = true;
            } else {
                if ($t) {
                    $out .= ' ';
                }
                if (isset($s) && $s) {
                    $out .= '+';
                }
                $out .= bin2hex(iconv('UTF-8', 'UCS-2', $v)) . ' ';
                $t = false;
            }
        }
        return $out;
    }


    public static function htmlspecialchars_decode(string $text): string
    {
        $text = str_replace('&apos;', "'", $text); // damn you php
        return htmlspecialchars_decode($text, ENT_QUOTES);
    }

    public static function validateCompanyName(string $text, bool $returnUnmatched = true): mixed
    {
        $text = preg_replace('#\xad#u', '', $text);
        $valid = false;

        $pattern = "\p{L}0-9\(\)\[\]\?:;\/!\,\・\.\-%\&\s\r\n\t_\*§²`´·’\"'\+¡¿@\”\“\|";

        if (!$text || preg_match("#^[$pattern]+$#isu", $text)) {
            $valid = true;
        } else {
            preg_match_all("#[^$pattern]+#isu", $text, $matches); // PREG_OFFSET_CAPTURE
        }

        if ($returnUnmatched) {
            return (object)['valid' => $valid, 'unmatched' => (isset($matches[0]) ? $matches[0] : '')];
        }

        return $valid;
    }

    public static function escapeQuotesForJavascriptJSON(string $text): string
    {
        return str_replace(["'", "'", '"'], ["\'", '&#039;', '&quot;'], $text);
        return htmlentities(str_replace("'", "\'", $text), ENT_QUOTES);
    }


    /**
     * Removes consecutive punctuations from a text string. Optionally, it can remove sequences of the same punctuation.
     *
     * @param string $text The input text from which to remove consecutive punctuations.
     * @param bool $remove_only_same_occurrence If true, removes sequences of the same punctuation; otherwise, removes all sequences of punctuations.
     * @return string The text with consecutive punctuations removed according to the specified mode.
     */
    public static function removeConsecutivePunctuations(string $text, bool $remove_only_same_occurrence = true): string
    {
        // detects sequences as: ! ! ! (<punctuation <space>) and normalize.
        $text = preg_replace('#([^\s\t\p{L}\p{N}])[\s\t]+(?![\p{L}\p{N}])#usi', '$1', $text);
        if ($remove_only_same_occurrence) {
            return preg_replace('#([^\/\p{L}\p{N}])\1{1,}#usi', '$1', $text);
        }
        return preg_replace('#([^\/\s\p{L}\p{N}]){1,}#usi', '$1', $text);
    }

    /**
     * Checks if the provided text contains a URL pattern.
     *
     * @param string $text The text to be checked for URL patterns.
     * @return bool True if a URL pattern is found in the text, otherwise false.
     */
    public static function textContainsUrl(string $text): bool
    {
        if (preg_match('/([0-9a-zA-Z])\w+[.][a-zA-Z]{1,}/', $text)) {
            return true;
        }
        return false;
    }

    /**
     * @param $text
     * @param $max_characters
     * @return bool
     * Checks if maximum characters allowed is reached in a string
     */
    public static function hasMaxCharactersAllowed(string $text, int $max_characters): bool
    {
        // decode html entities before validation
        $text = html_entity_decode($text);

        if (!empty($text) && !empty($max_characters)) {
            $length = mb_strlen($text, mb_detect_encoding($text));
            if ($length > $max_characters) {
                return false;
            }
            return true;
        }
        return false;
    }

    public static function textContainsEmail(string $text): bool
    {
        if (preg_match('~([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,6})~', $text)) {
            return true;
        }
        return false;
    }

    /**
     * @param $text
     * @return bool
     * Checks if text contains an URL prefix (www. , http://, https://) but accepts URL sufixes( .com, .net)
     */
    public static function textContainsURLPrefix(string $text): bool
    {
        if (preg_match('/^(?:https?:\/\/|www.)/i', $text)) {
            return true;
        }
        return false;
    }

    public static function textContainsPhoneNumber(string $text): bool
    {
        $user = AuthService::instance()->get_user();
        $phoneNumberUtil = PhoneNumberUtil::getInstance();
        $phoneNumberMatcher = $phoneNumberUtil->findNumbers($text, $user->site->country->country_shortcode);

        foreach ($phoneNumberMatcher as $phoneNumberMatch) {
            return true;
        }

        $text = str_replace(['(', ')', '/'], '', $text);

        //matches the following patterns and check if the match is longer than 6 (usual phone number length)
        //123-123-1234, (123) 123 1234 ,+12 123123123, +123123123123, 123123123
        $regex = '/\(?([0-9]{3})\)?[-. ]?([0-9]{3})[-. ]?([0-9]{4})/';
        if (preg_match($regex, $text, $matches)) {
            return true;
        }

        /*

    (i) allows for valid international prefixes
    (ii) followed by 9 or 10 digits, with any    or placing of delimeters (except between the last two digits)

    This will match:
    +1-234-567-8901
    +61-234-567-89-01
    +46-234 5678901
    +1 (234) 56 89 901
    +1 (234) 56-89 901
    +46.234.567.8901
    +1/234/567/8901

     */

        $regex = '~\+(9[976]\d|8[987530]\d|6[987]\d|5[90]\d|42\d|3[875]\d|2[98654321]\d|9[8543210]|8[6421]|6[6543210]|5[87654321]|4[987654310]|3[9643210]|2[70]|7|1)\W*\d\W*\d\W*\d\W*\d\W*\d\W*\d\W*\d\W*\d\W*(\d{1,2})$~';
        if (preg_match($regex, $text)) {
            if (preg_match($regex, $text)) {
                return true;
            }
        }

        return false;
    }

    public static function textHasOnlyDigits(string $text): bool
    {
        // checks if text has only digits and space
        $regex = '"^[0-9 ]+$"';
        if (preg_match($regex, $text)) {
            return true;
        }
        return false;
    }

    public static function textHasToManyPunctuation(string $text): bool
    {
        // checks if text has more than 6 digits
        $count = preg_match_all('/[[:punct:]]/', $text);
        if ($count > 6) {
            return true;
        }
        return false;
    }

    public static function getYoutubeID(string $url): ?string
    {
        $rx = '~
          ^(?:https?://)?' .                            // Optional protocol
            '(?:www[.])?' .                               // Optional sub-domain
            '(?:youtube[.]com/watch[?]v=|youtu[.]be/)' .  // Mandatory domain name (w/ query string in .com)
            '(?P<id>[^&]{11})' .                          // Video id of 11 characters as capture group 'id'
            '~x';
        preg_match($rx, $url, $matches);
        if (!empty($matches['id'])) {
            return $matches['id'];
        }
        return null;
    }

    public static function maskIBAN(string $iban): string
    {
        return substr_replace(substr_replace($iban, 'xx', 2, 2), 'xxxxxx', 12, 6);
    }

    /**
     * Rounds a given time in seconds to the nearest interval specified in minutes.
     *
     * @param int $time The time in seconds to be rounded.
     * @param int $minutes The interval in minutes to which the time should be rounded.
     * @return int The rounded time in seconds.
     */
    public static function roundMinutes(int $time, int $minutes): int
    {
        return round($time / ($minutes * 60)) * ($minutes * 60);
    }

    /**
     * Formats a locale string from an ISO format to a format suitable for use in JavaScript.
     * For example, it converts 'en_US' to 'en-US'.
     *
     * @param string $locale The locale string in ISO format (e.g., 'en_US').
     * @return string The locale string formatted for JavaScript (e.g., 'en-US').
     */
    public static function localeForJavascript(string $locale): string
    {
        $language = substr($locale, 0, 2);
        $country = substr($locale, 3, 2);

        $country = strtoupper($country);

        return "$language-$country";
    }

    public static function replaceUTF8SoftHyphen(string $text): string
    {
        $text = str_replace('­', '', $text);
        return $text;
    }

    /** this clears all the characters that are not alphanumeric and replaces them with space */
    public static function clearNonAlphaNumericCharacters(string $text): string
    {
        $text = preg_replace('~[^a-zA-Z 0-9]+~', ' ', $text);
        return $text;
    }

    public static function getCityFromAddress(string $address): string
    {
        $result = null;
        $match = preg_match('~(?P<city>.*),\s(?P<state>[a-z]{0,2})~', $address, $result);
        if ($match) {
            return $result['city'];
        }
        return $address;
    }

    /**
     * Validate a given $number against a REGEX to verify if it is latitude
     *
     * @param string|float $value
     * @return bool
     */
    public static function isLatitude(float|string $value): bool
    {
        if (preg_match('/^[-]?(([0-8]?[0-9])\.(\d+))|(90(\.0+)?)$/', $value)) {
            return true;
        }

        return false;
    }

    /**
     * Validate a given $number against a REGEX to verify if it is longitude
     *
     * @param string|float $value
     * @return bool
     */
    public static function isLongitude(float|string $value): bool
    {
        if (preg_match('/^[-]?((((1[0-7][0-9])|([0-9]?[0-9]))\.(\d+))|180(\.0+)?)$/', $value)) {
            return true;
        }

        return false;
    }

    /**
     * Returns the smallest integer value from an array that is not zero.
     *
     * @param array $values An array of values from which to find the smallest non-zero integer.
     * @return int The smallest integer that is not zero. Returns `null` if no valid non-zero integer is found.
     */
    public static function minNotNull(array $values): ?int
    {
        return min(array_diff(array_map('intval', $values), [0]));
    }


    /**
     * Replace language-specific characters by ASCII-equivalents. e.g. ö => oe
     * @param string $s
     * @return string
     */
    public static function normalizeDiacritics(string $s): string
    {
        $replace = [
            'ъ' => '-',
            'Ь' => '-',
            'Ъ' => '-',
            'ь' => '-',
            'Ă' => 'A',
            'Ą' => 'A',
            'À' => 'A',
            'Ã' => 'A',
            'Á' => 'A',
            'Æ' => 'A',
            'Â' => 'A',
            'Å' => 'A',
            'Ä' => 'Ae',
            'Þ' => 'B',
            'Ć' => 'C',
            'ץ' => 'C',
            'Ç' => 'C',
            'È' => 'E',
            'Ę' => 'E',
            'É' => 'E',
            'Ë' => 'E',
            'Ê' => 'E',
            'Ğ' => 'G',
            'İ' => 'I',
            'Ï' => 'I',
            'Î' => 'I',
            'Í' => 'I',
            'Ì' => 'I',
            'Ł' => 'L',
            'Ñ' => 'N',
            'Ń' => 'N',
            'Ø' => 'O',
            'Ó' => 'O',
            'Ò' => 'O',
            'Ô' => 'O',
            'Õ' => 'O',
            'Ö' => 'Oe',
            'Ş' => 'S',
            'Ś' => 'S',
            'Ș' => 'S',
            'Š' => 'S',
            'Ț' => 'T',
            'Ù' => 'U',
            'Û' => 'U',
            'Ú' => 'U',
            'Ü' => 'Ue',
            'Ý' => 'Y',
            'Ź' => 'Z',
            'Ž' => 'Z',
            'Ż' => 'Z',
            'â' => 'a',
            'ǎ' => 'a',
            'ą' => 'a',
            'á' => 'a',
            'ă' => 'a',
            'ã' => 'a',
            'Ǎ' => 'a',
            'а' => 'a',
            'А' => 'a',
            'å' => 'a',
            'à' => 'a',
            'א' => 'a',
            'Ǻ' => 'a',
            'Ā' => 'a',
            'ǻ' => 'a',
            'ā' => 'a',
            'ä' => 'ae',
            'æ' => 'ae',
            'Ǽ' => 'ae',
            'ǽ' => 'ae',
            'б' => 'b',
            'ב' => 'b',
            'Б' => 'b',
            'þ' => 'b',
            'ĉ' => 'c',
            'Ĉ' => 'c',
            'Ċ' => 'c',
            'ć' => 'c',
            'ç' => 'c',
            'ц' => 'c',
            'צ' => 'c',
            'ċ' => 'c',
            'Ц' => 'c',
            'Č' => 'c',
            'č' => 'c',
            'Ч' => 'ch',
            'ч' => 'ch',
            'ד' => 'd',
            'ď' => 'd',
            'Đ' => 'd',
            'Ď' => 'd',
            'đ' => 'd',
            'д' => 'd',
            'Д' => 'D',
            'ð' => 'd',
            'є' => 'e',
            'ע' => 'e',
            'е' => 'e',
            'Е' => 'e',
            'Ə' => 'e',
            'ę' => 'e',
            'ĕ' => 'e',
            'ē' => 'e',
            'Ē' => 'e',
            'Ė' => 'e',
            'ė' => 'e',
            'ě' => 'e',
            'Ě' => 'e',
            'Є' => 'e',
            'Ĕ' => 'e',
            'ê' => 'e',
            'ə' => 'e',
            'è' => 'e',
            'ë' => 'e',
            'é' => 'e',
            'ф' => 'f',
            'ƒ' => 'f',
            'Ф' => 'f',
            'ġ' => 'g',
            'Ģ' => 'g',
            'Ġ' => 'g',
            'Ĝ' => 'g',
            'Г' => 'g',
            'г' => 'g',
            'ĝ' => 'g',
            'ğ' => 'g',
            'ג' => 'g',
            'Ґ' => 'g',
            'ґ' => 'g',
            'ģ' => 'g',
            'ח' => 'h',
            'ħ' => 'h',
            'Х' => 'h',
            'Ħ' => 'h',
            'Ĥ' => 'h',
            'ĥ' => 'h',
            'х' => 'h',
            'ה' => 'h',
            'î' => 'i',
            'ï' => 'i',
            'í' => 'i',
            'ì' => 'i',
            'į' => 'i',
            'ĭ' => 'i',
            'ı' => 'i',
            'Ĭ' => 'i',
            'И' => 'i',
            'ĩ' => 'i',
            'ǐ' => 'i',
            'Ĩ' => 'i',
            'Ǐ' => 'i',
            'и' => 'i',
            'Į' => 'i',
            'י' => 'i',
            'Ї' => 'i',
            'Ī' => 'i',
            'І' => 'i',
            'ї' => 'i',
            'і' => 'i',
            'ī' => 'i',
            'ĳ' => 'ij',
            'Ĳ' => 'ij',
            'й' => 'j',
            'Й' => 'j',
            'Ĵ' => 'j',
            'ĵ' => 'j',
            'я' => 'ja',
            'Я' => 'ja',
            'Э' => 'je',
            'э' => 'je',
            'ё' => 'jo',
            'Ё' => 'jo',
            'ю' => 'ju',
            'Ю' => 'ju',
            'ĸ' => 'k',
            'כ' => 'k',
            'Ķ' => 'k',
            'К' => 'k',
            'к' => 'k',
            'ķ' => 'k',
            'ך' => 'k',
            'Ŀ' => 'l',
            'ŀ' => 'l',
            'Л' => 'l',
            'ł' => 'l',
            'ļ' => 'l',
            'ĺ' => 'l',
            'Ĺ' => 'l',
            'Ļ' => 'l',
            'л' => 'l',
            'Ľ' => 'l',
            'ľ' => 'l',
            'ל' => 'l',
            'מ' => 'm',
            'М' => 'm',
            'ם' => 'm',
            'м' => 'm',
            'ñ' => 'n',
            'н' => 'n',
            'Ņ' => 'n',
            'ן' => 'n',
            'ŋ' => 'n',
            'נ' => 'n',
            'Н' => 'n',
            'ń' => 'n',
            'Ŋ' => 'n',
            'ņ' => 'n',
            'ŉ' => 'n',
            'Ň' => 'n',
            'ň' => 'n',
            'о' => 'o',
            'О' => 'o',
            'ő' => 'o',
            'õ' => 'o',
            'ô' => 'o',
            'Ő' => 'o',
            'ŏ' => 'o',
            'Ŏ' => 'o',
            'Ō' => 'o',
            'ō' => 'o',
            'ø' => 'o',
            'ǿ' => 'o',
            'ǒ' => 'o',
            'ò' => 'o',
            'Ǿ' => 'o',
            'Ǒ' => 'o',
            'ơ' => 'o',
            'ó' => 'o',
            'Ơ' => 'o',
            'œ' => 'oe',
            'Œ' => 'oe',
            'ö' => 'oe',
            'פ' => 'p',
            'ף' => 'p',
            'п' => 'p',
            'П' => 'p',
            'ק' => 'q',
            'ŕ' => 'r',
            'ř' => 'r',
            'Ř' => 'r',
            'ŗ' => 'r',
            'Ŗ' => 'r',
            'ר' => 'r',
            'Ŕ' => 'r',
            'Р' => 'r',
            'р' => 'r',
            'ș' => 's',
            'с' => 's',
            'Ŝ' => 's',
            'š' => 's',
            'ś' => 's',
            'ס' => 's',
            'ş' => 's',
            'С' => 's',
            'ŝ' => 's',
            'Щ' => 'sch',
            'щ' => 'sch',
            'ш' => 'sh',
            'Ш' => 'sh',
            'ß' => 'ss',
            'т' => 't',
            'ט' => 't',
            'ŧ' => 't',
            'ת' => 't',
            'ť' => 't',
            'ţ' => 't',
            'Ţ' => 't',
            'Т' => 't',
            'ț' => 't',
            'Ŧ' => 't',
            'Ť' => 't',
            '™' => 'tm',
            'ū' => 'u',
            'у' => 'u',
            'Ũ' => 'u',
            'ũ' => 'u',
            'Ư' => 'u',
            'ư' => 'u',
            'Ū' => 'u',
            'Ǔ' => 'u',
            'ų' => 'u',
            'Ų' => 'u',
            'ŭ' => 'u',
            'Ŭ' => 'u',
            'Ů' => 'u',
            'ů' => 'u',
            'ű' => 'u',
            'Ű' => 'u',
            'Ǖ' => 'u',
            'ǔ' => 'u',
            'Ǜ' => 'u',
            'ù' => 'u',
            'ú' => 'u',
            'û' => 'u',
            'У' => 'u',
            'ǚ' => 'u',
            'ǜ' => 'u',
            'Ǚ' => 'u',
            'Ǘ' => 'u',
            'ǖ' => 'u',
            'ǘ' => 'u',
            'ü' => 'ue',
            'в' => 'v',
            'ו' => 'v',
            'В' => 'v',
            'ש' => 'w',
            'ŵ' => 'w',
            'Ŵ' => 'w',
            'ы' => 'y',
            'ŷ' => 'y',
            'ý' => 'y',
            'ÿ' => 'y',
            'Ÿ' => 'y',
            'Ŷ' => 'y',
            'Ы' => 'y',
            'ž' => 'z',
            'З' => 'z',
            'з' => 'z',
            'ź' => 'z',
            'ז' => 'z',
            'ż' => 'z',
            'ſ' => 'z',
            'Ж' => 'zh',
            'ж' => 'zh'
        ];
        return strtr($s, $replace);
    }

    /**
     * Returns true if the given $url is on the SSL protocol, as in getProtocolFromUrl it defaults to true
     *
     * @param $url
     * @return bool
     */
    public static function urlHasSecureProtocol(string $url): bool
    {
        return (self::getProtocolFromUrl($url) == 'https');
    }

    /**
     * Gets the protocol (scheme) from an $url string, it defaults to empty string
     *
     * @param $url
     * @return mixed|string
     */
    public static function getProtocolFromUrl(string $url): string
    {
        $urlParts = parse_url($url);
        if (isset($urlParts['scheme'])) {
            return $urlParts['scheme'];
        }

        return '';
    }

    public static function format_vatID_with_country_code(string $vatID, string $country_shortcode): string
    {
        if (!str_starts_with(strtoupper($vatID), strtoupper($country_shortcode))) {
            $vatID = $country_shortcode . $vatID;
        }

        return $vatID;
    }

    /**
     * Remove punctuation from a string
     *
     * @param string $data
     * @return  string
     */
    public function cleanNum(string $data): stroing
    {
        return str_replace([
            ' ',
            ',',
            '.',
            '\''
        ], '', $data);
    }

    /**
     * Get page from an url?
     * @param     $url
     * @return
     */
    public function urlpage(string $url): string
    {
        preg_match(
            '#^((?:(?:http(?:s)?|ftp):(/){1,3})?(?:(?:(?:[a-z0-9\.\-_]+\.)?[^/\?\.]{1,255}\.[a-z]{2,4})|[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}))#iu',
            $url,
            $match
        );
        $page = str_replace($match[1], '', $url);
        return $page;
    }

    /**
     * Remove http, www from an URL.
     */
    public function sitenormalize(string $url): string
    {
        $url = preg_replace('#^http://#iu', '', $url);
        $url = preg_replace('#^www\.#iu', '', $url);
        $url = preg_replace('#\/$#u', '', $url);
        return $url;
    }

    /**
     * Checks if input is a valid timestamp
     *
     * @param string|int $timestamp
     * @return bool
     */
    public static function isValidTimestamp(string|int $timestamp): bool
    {
        return ((string)(int)$timestamp == $timestamp)
            && ($timestamp <= PHP_INT_MAX)
            && ($timestamp >= PHP_INT_MIN);
    }

    /**
     * @param string $string
     * @return string
     */
    public static function underscoreToCamelCase(string $string): string
    {
        $words = explode('_', $string);
        $camelCase = '';
        foreach ($words as $word) {
            $camelCase .= ucfirst($word);
        }
        return lcfirst($camelCase);
    }

    /**
     * Obtain a valid IP address for the given domain name.
     *
     * @param string $domainName The domain name for which to obtain the IP address.
     * @return mixed|null The valid IP address for the domain, or null if it is not valid.
     */
    public static function getValidIPForHost(string $domainName): ?string
    {
        $ips = gethostbynamel($domainName);

        if (false === $ips) {
            return null;
        }

        // Select the first IP address from the list, if available
        $ip = null;
        if (count($ips) > 0) {
            $ip = $ips[0];
        }

        // Check if the selected IP address is valid, return it if valid, otherwise return null
        return self::isValidIPAddress($ip) ? $ip : null;
    }

    /**
     * This function checks if the provided IP address is valid.
     * If the IP address is not provided, it returns false.
     * It uses the filter_var function to validate the IP address, excluding reserved ranges.
     *
     * @param string|null $ipAddress The IP address to validate
     * @return bool True if the IP address is valid, otherwise false
     */
    public static function isValidIPAddress(?string $ipAddress = null): bool
    {
        if (null === $ipAddress) {
            return false;
        }

        // Validate the provided IP address using filter_var, excluding reserved ranges
        return (bool)filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE);
    }
}
