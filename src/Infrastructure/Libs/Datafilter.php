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
    public static $regex_special_chars = array(
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
    );
    // greek: ΑαΒβΓγΔδΕεΖζΗηΘθΙιΚκΛλΜμΝνΞξΟοΠπΡρΣσΣσςΤτΥυΦφΧχΨψΩω
    // polish: ĄąĘęĆćŁłŃńŚśŹźŻżÓó
    // india (hindi): ऑऒऊऔउबभचछडढफफ़गघग़घग़हजझकखख़लळऌऴॡमनङञणऩॐपक़रऋॠऱसशषटतठदथधड़ढ़वयय़ज़
    // italian: ÀÈÉÌÒÙàèéìòù
    public static $umlauts = array(
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
    );
    public static $special = array(
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
    );

    public static function decodeAmpersand(string $input): string
    {
        return preg_replace_callback("/([a-zA-Z0-9]*\s*)&amp;(\s*[a-zA-Z0-9]*)/", function($matches) {
            return $matches[1] . '&' . $matches[2];
        }, $input);
    }

    /**
     * @param string|array|int|float|bool|null $input
     * @return string|array|int|float|bool|null
     */
    public static function sanitizeInput(string|array|int|float|bool|null $input): string|array|int|float|bool|null
    {
        if (is_int($input) || is_float($input) || is_bool($input) || is_null($input)) {
            return $input;
        }
        if (!self::$htmlPurifier) {
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('Core.Encoding', 'UTF-8');
            $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
            self::$htmlPurifier = new \HTMLPurifier($config);
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
     * Check if string is UTF8 valid.
     * By rodrigo at overflow dot biz
     * http://php.net/manual/en/function.utf8-encode.php
     * @param     $string
     * @return
     */
    public static function is_utf8($string)
    { // v1.01
        if (strlen($string) > _is_utf8_split) {
            // Based on: http://mobile-website.mobi/php-utf8-vs-iso-8859-1-59
            for ($i = 0, $s = _is_utf8_split, $j = ceil(strlen($string) / _is_utf8_split); $i < $j; $i++, $s += _is_utf8_split) {
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

    public static function encode_utf8v2($text, $with_htmldecoding = true)
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
    public static function entity_decode($text)
    {
        $text = html_entity_decode(str_replace('&nbsp;', ' ', $text), ENT_QUOTES, 'utf-8');
        return $text;
    }

    /**
     * Take care of extra spaces or add spaces between [.,!?]
     * @param $text
     */
    public static function nice_text($text)
    {
        $replace_array = array(
            ', ',
            '. ',
            '! ',
            '? ',
            ': '
        );
        // add space
        $text = str_replace(array(
            ',',
            '.',
            '!',
            '?',
            ':'
        ), $replace_array, $text);
        // remove dobule space
        $text = str_replace(array(
            ',  ',
            '.  ',
            '!  ',
            '?  ',
            ':  '
        ), $replace_array, $text);
        return $text;
    }

    public static function removeNonAscii($string)
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
    public static function cleanAndFilterKeyword($text)
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
     * @author Draga Sergiu
     */
    public static function cleanText($data, $utfdecode = 0, $utfencode = 1, $extrachars = 0)
    {
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
        $data = preg_replace('#[^~\r\n\ta-z ' . self::$special_chars . '90-9\.:,;=\-_\+\*&\?!/{}\(\)\[\]%$€<>' . $chars . '\|\\\]#isu', ' ', $data);
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
     * @author Draga Sergiu
     */
    public static function encode_utf8($data)
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
        $wordArr = array();

        foreach ($dataExp as $word) {
            // from DE, RO, jpn charset ..
            if (preg_match('#Â©|Ã¤|Ã¼|Ã¶|Ã®|ÄÅ|[^\w\s\d\x00-\x7F]|â¬|Â»|â¢|â€|€™|Â|Å|â|Å£|Ã¢|Ã|Ã§|°Ñ|Ð|Ä|ã|¼|å|¤|¾|é#su', $word)) {
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

    public static function cleanUrl($url)
    {
        return 'http://' . str_replace(array(
                'http://',
                'https://'
            ), '', $url);
    }

    public static function cleanTextTest($data, $utfdecode = 0)
    {
        $data = utf8_decode($data);
        $data = preg_replace('#[^~\r\n\ta-z \xDC\xFC\xF6\xD6\xC4\xE4\xDF0-9\.:,;=\-_\+\*&\?!/{}\(\)\[\]%$€<>\|\\\]#isu', ' ', $data);
        $data = preg_replace('#[\r\n\t]#s', '', $data);
        $from = array(
            "#'#",
            '#"#'
        );
        $to = array(
            '\u0027',
            '\u0022'
        );
        $data = preg_replace($from, $to, $data);
        $data = trim($data);
        return $data;
    }

    /**
     * Generate alias for a given string
     *
     * @param string $string
     * @return  string
     * @author Draga Sergiu
     */
    public static function alias($string, $spaceDelimiter = '-')
    {
        $string = trim(mb_strtolower($string));
        /*
            $umlaute = Array("/ä/", "/ö/", "/ü/", "/Ä/", "/Ö/", "/Ü/", "/ß/");
            $replace = Array("ae", "oe", "ue", "ae", "oe", "ue", "ss");
            $string = preg_replace($umlaute, $replace, $string);
         */
        $string = str_replace(array(
            '&',
            ',',
            '.',
            '!',
            '?'
        ), '-', $string);
        $string = str_replace(' ', $spaceDelimiter, $string);
        // replace “ ”
        $string = str_replace(array(
            '“',
            '”'
        ), '"', $string);
        $string = preg_replace('/[^@0-9' . $spaceDelimiter . '\-_\p{L}\'"’]/iu', '', $string);
        $string = preg_replace('/[-]{2,}/', '-', $string);
        //$string = preg_replace('#^-(.*)-$#', '\\1', $string);
        return $string;
    }

    /**
     * Alias function for keywords..
     * @param     $string
     * @return
     */
    public static function alias_keyword($string)
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
    public static function quoteKeyword($keyword)
    {
        return str_replace(array(
            //'&apos;',
            "'",
            '"'
        ), array(
            //"&rsquo;",
            "\'",
            '&quot;'
        ), $keyword);
    }

    /**
     * Clean keywords array
     * @param     $string
     * @return
     */
    public static function clean_keywords($array, $removeDuplicates = false)
    {
        $auxarray = array();
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
    public static function remove_consecutive_duplicate($text)
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
     * @param     $string
     * @return
     */
    public static function clean_keyword($string, $useWhitelist = false)
    {
        //@todo why don't use \p{L} for a-z AND utf8 diacrtics? why use umlauts array?
        //lowercase for normalization
        $string = mb_strtolower($string);
        //replace html entities to normal characters
        $string = html_entity_decode($string);
        // fast patch for keywords sent by RC ui.js (eg: children's clothes)
        $string = str_replace(array(
            '&#39;',
            '’'
        ), "'", $string);
        //replace unicode string representation with coresponding utf8 char
        $string = str_replace(array(
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
        ), Datafilter::$umlauts, $string);
        $string = str_replace(array(
            'ã£â¶',
            'ã£â¤',
            'ã£â¼',
            'ã£â'
        ), array(
            'ö',
            'ä',
            'ü',
            'ß'
        ), $string);
        //error fron json_decode( json_encode( utf8_encode( $string)))   string already encoded UTF-8
        //$string = str_replace(array("ã", "å", "ã©", "ã¨", "ã«", "ã¡", "ã ", "ã¤", "ã¢", "ã£", "ã¶", "ã´", "ã³", "ã²", "ã¯", "ã", "ã®", "å", "è", "å£", "è", "ã¼", "ã¹", "ã»", "ã±", "ã¿"), Datafilter::$umlauts, $string);
        //remove " from not <number>" or <space>" e.g.: 3.2" tft touchscreen 19 " 1/4" we3/4" telephone pda3" "mobile" p05-i92" test " => 3.2" tft touchscreen 19 " 1/4" we3/4 telephone pda3 mobile p05-i92 test
        //$string = preg_replace(array("/\"([\w])/u", "/([a-z\p{L}][\/\d\.\-]* *)\"/u"), array('$1', '$1'), $string);
        //convert to space everything that is not allowed
        $special = implode("\\", self::$special);
        //$umlauts = implode('', self::$umlauts);
        // replace “ ”
        $string = str_replace(array(
            '“',
            '”'
        ), '"', $string);
        $string = str_replace(array(
            ',',
        ), ' ', $string);
        //$regex = '[^\'"’a-z ' . $special . self::$special_chars . '0-9]';
        $regex = '[^a-z ' . $special . self::$special_chars . '0-9]';
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
     * @author Draga Sergiu
     */
    public static function array_unique($array)
    {
        $aux = array();
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
    public static function filter_diacritics($text, $allowedDiacritics = array())
    {
        $text = mb_strtolower(trim($text));
        if (!$text) {
            return;
        }
        // replace into standard format, eg: ä => ae
        $umlaute = [];
        if (!isset(self::$allowedDiacriticsCache)) {
            $umlaute = array(
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
            );
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
    public static function getDomainAndPath($url, $asString = false, $removewww = false, bool $skipFileExclusion = true)
    {
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
            $pathExploded = preg_split('#[/\?\#]+#', $path); // also take care of url such as omnomn.wix.com/hotel#!book-a-room
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

        return (object)array(
            'domain' => $domain,
            'path' => (string)$path
        );
    }

    /**
     * Get domain from a full URL.
     * @param $url
     * @param bool $fulldomain
     * @param bool $subdomain_removal
     * @param bool $removewww
     * @return int|mixed|string
     */
    public static function domain($url, $fulldomain = false, $subdomain_removal = false, $removewww = true)
    {
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
    public static function topdomain($url)
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
    public static function subdomain($url)
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
    public static function validateURL($url)
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
            return 0;
        }
        if (!preg_match('#.*?\..*?#', $url)) {
            return 0;
        }
        return 1;
    }

    /**
     * Validate an domain. http and www. are allowed.
     *
     * @param     $url
     * @return
     *
     */
    public static function validateDomain($url)
    {
        if (preg_match('#^(http://)?(www\.)?[' . self::$special_chars . 'a-z0-9\._\-]+\.[a-z]{2,6}/?$#iu', $url)) {
            return 1;
        }
        return 0;
    }

    /**
     * Check if a valid mail.
     *
     * @param string $text
     * @param int $partial
     * @return  int
     * @author Draga Sergiu
     */
    public static function validEmail($text, $partial = 0)
    {
        $start = '^';
        $end = '$';
        if ($partial == 1) {
            $start = '';
            $end = '';
        }
        if (preg_match('/' . $start . "[A-Za-z0-9._\-\+]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,24}$end/", $text)) {
            return 1;
        }
        return 0;
    }

    /**
     * Count words in a text.
     *
     * @param     $text
     */
    public static function wordcount($text)
    {
        $words = self::breakwords($text);
        return count($words ?? []);
    }

    /**
     * Break text into words array.
     *
     * @param string $text
     * @return  array
     * @author Draga Sergiu
     */
    public static function breakwords($text, $breakMoreSpecialChars = 0, $filter = 0)
    {
        $text = str_replace("\t", ' ', $text);
        if ($breakMoreSpecialChars == 1) {
            $breakChars = '\-/';
            // convert _ because if we want to delimt by this char the _ is part of an word and not as separator (see word \boundary).
            $text = str_replace('_', '-', $text);
        } else {
            $breakChars = '';
        }

        if (!$text) {
            return array();
        }
        $wordstmpArr = array();
        $text = strtolower($text);
        $text = self::cleanUrlsAndEmails($text);
        // now breakup!
        $special_chars = self::$special_chars . self::$exclude_chars;
        //preg_match_all('#(?P<word>['.$special_chars.']*\b[0-9a-z'.$special_chars.'][^\r\n\t, \.\?&!'.$breakChars.'/\']*[0-9a-z'.$special_chars.']*\b['.$special_chars.']*)#iu', $text, $wordstmpArr);
        //preg_match_all('#(?P<word>[0-9a-z' . $special_chars . '\#_]+)#isu', $text, $wordstmpArr); // this is old
        // the second part is for preserving things likle iso 3834-2 or update 2.0 etc
        // \;\& was added because ampersand was being returned as amp instead of &amp; . This was done because the Text Optimization tool was not correctly comparing the text with keywords containing ampersand
        preg_match_all('#(?P<word>([0-9]+[\.\-+]*[0-9]+)|([0-9a-z' . $special_chars . '\#_\;\&]+))#isu', $text, $wordstmpArr);
        // filter the text and return aliased words (optional)
        if ($filter) {
            $filtered_words = array();
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
    public static function cleanUrlsAndEmails($text)
    {
        // first and first REMOVE sites.
        $text = preg_replace(array(
            '#http(s)?://(www\.)?[^\s\t\r\n]+#is',
            '#www\.[^\s\t\r\n]+#is'
        ), '', $text);
        // remove emails.. [1] // as in info`[at]`loew.ag & http://pr-ranklist.de/impressum.php
        $pAround = array(
            '@',
            ' ?\[ ?at ?\] ?',
            '`\[at\]`',
            ' ?\(at\) ?',
            '\(via\)'
        );
        $pDot = array(
            '\.',
            '\[dot\]',
            '\(dot\)'
        );
        $text = preg_replace(
            '#(?P<email>[a-z]([_a-z0-9\-]+)(\.[_a-z0-9\-]+)*(' . implode('|', $pAround) . ')([a-z0-9-]+)(\.[a-z0-9-]+)*(' . implode(
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
     * @author Draga Sergiu
     * @param string $string
     * @return  string
     */
    public static function aliasv2($string, $spaceDelimiter = '-')
    {
        $umlaute = array(
            '/ä/',
            '/ö/',
            '/ü/',
            '/Ä/',
            '/Ö/',
            '/Ü/',
            '/ß/'
        );
        $replace = array(
            'ae',
            'oe',
            'ue',
            'ae',
            'oe',
            'ue',
            'ss'
        );
        $string = preg_replace($umlaute, $replace, $string);

        $string = trim(strtolower($string));
        $string = str_replace(array(
            '&',
            ',',
            '.',
            '!',
            '?'
        ), '-', $string);
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
    public static function textwords($text)
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
     * @author Draga Sergiu
     */
    public static function encode($text)
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
     * @author Draga Sergiu
     */
    public static function decode($text)
    {
        $text = str_replace('_SL_', '/', $text);
        $text = str_replace('_PL_', '+', $text);
        $text = base64_decode($text);
        //$text = json_decode($text);
        return $text;
    }

    public static function keyword_code($keyword)
    {
        //if ((int)$keyword > 0)
        return 'KW__' . $keyword;
        //else return $keyword;
    }

    public static function keywords_decode($keywords)
    {
        foreach ($keywords as $keyword) {
            $keywords_aux[] = self::keyword_decode($keyword);
        }
        return $keywords_aux ?? [];
    }

    public static function keyword_decode($keyword)
    {
        return str_replace('KW__', '', $keyword);
    }

    public static function stringToHex($string)
    {
        // $string = 'ÄäÖöÜüß€ÀÂÄÈÉÊËÎÏÔŒÙÛÜŸàâäèéêëîïôœùûüÿÁÉÍÓÚÑÜáéíóúñüÀÈÉÌÒÓÙàèéìòóùăîâşţĂÎÂŞŢ';
        preg_match_all('#[^\.]{1}#u', $string, $chars);
        $aux = array();
        foreach ($chars[0] as $char) {
            $aux[$char] = dechex(self::ordUTF8($char));
            $aux[$char] = '\x' . strtoupper($aux[$char]);
        }
        $aux = array_unique($aux);
        $aux = implode('', $aux);
        return $aux;
    }

    public static function ordUTF8($c, $index = 0, &$bytes = null)
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
            return ($h & 0x0F) << 18 | (ord($c[$index + 1]) & 0x3F) << 12 | (ord($c[$index + 2]) & 0x3F) << 6 | (ord($c[$index + 3]) & 0x3F);
        } else {
            return false;
        }
    }

    public static function compare_domains($domain, $domain2)
    {
        $countryTld = 0;
        $domain = self::domain_tld($domain);
        $domain2 = self::domain_tld($domain2);

        $countryCodes = array(
            'de',
            'at',
            'ch'
        );
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

    public static function domain_tld($domain)
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
    public static function delimiterHtmlText($text, $delimiter_output = '###')
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
        $text = str_replace(array(
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
        ), ',', $text);
        // some words delimit text, such as: and, or, und, oder
        $text = preg_replace('#\b(and|or|und|oder)\b#i', $delimiter, $text);
        // some cleanup
        $text = str_replace(array(
            "$delimiter ",
            " $delimiter",
            " $delimiter "
        ), $delimiter, $text);
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
    public static function prioritizeArrayElementsByKeyword($array, $keyword, $key_element = 'text', $limit = 500)
    {
        $aux = array();
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
        usort($array, array(
            'Datafilter',
            'compare_array'
        ));

        return $array;
    }

    public static function compare_array($a, $b)
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
    public static function getDiacriticInsensitivePregString($keyword)
    {
        $umlaute = array(
            'ä',
            'ö',
            'ü',
            'ß',
            'é',
            'è',
            'ê',
            'â'
        );
        $umlaute2 = array(
            'ae',
            'oe',
            'ue',
            'ss',
            'e',
            'e',
            'e',
            'a'
        );
        $umlaute3 = array();
        $umlaute4 = array();
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
    public static function normalize_diacritcs($array)
    {
        foreach ($array as $key => $element) {
            $array[$key] = self::filter_diacritics($element);
        }
        return $array;
    }

    /**
     * Get more combinations, considering aliases too.
     * @param $query
     * @param $minLength
     * @return array
     */
    public static function getSmartCombinations($query, $minLength = 0)
    {
        // break up by words
        $words = self::breakwords($query);
        // apply alias for each word
        $text_aliased = array();
        foreach ($words as $word) {
            $text_aliased[] = self::aliasv2($word);
        }
        $combinations = array();
        $combinations = array_merge($combinations, self::getCombinations($query, $minLength));
        $combinations = array_merge($combinations, self::getCombinations(implode(' ', self::breakwords($query, 1)), $minLength));
        $combinations = array_merge($combinations, self::getCombinations(implode(' ', $text_aliased), $minLength));
        $combinations = array_unique($combinations);
        $final_combinations = array();
        foreach ($combinations as $combination) {
            if (strlen($combination) >= $minLength) {
                $final_combinations[] = $combination;
            }
        }
        return $final_combinations;
    }

    public static function getCombinations($query)
    {
        $query = preg_replace('/\s\s+/', ' ', $query);
        $elements = explode(' ', $query);
        $aux = array($query);
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
     * cleans scrips and normalizes int values
     * @param null $request
     * @param array $intValues array('key','key2') of int indexes => this will be (int) ed
     * @return null
     */
    public static function cleanRequestFromScript($request = null, $intValues = array())
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
     * determines correctness of phone numbers
     *
     * @param $number
     * @param string $countryCode e.g. de if null, and number contains no international prefix, e.g. 0049 or +49 the function will return false
     * @return bool
     */
    public static function validatePhoneNumber($number, $countryCode = 'de')
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

    public static function validatePhoneNumberByCountry($number, $countryCode, $regionCountriesList = [])
    {
        try {
            $number = str_replace('&nbsp', ' ', $number);
            $phoneUtil = PhoneNumberUtil::getInstance();
            $parsedNumber = $phoneUtil->parseAndKeepRawInput($number, strtoupper($countryCode));
            if ($phoneUtil->isValidNumber($parsedNumber)) {
                $regionCode = $phoneUtil->getRegionCodeForNumber($parsedNumber);
                $countryCode = strtoupper($countryCode);
                if ($regionCode == $countryCode) { //check if the region code returned from filtering is the same from site
                    return true;
                } elseif (!empty($regionCountriesList) && array_search(strtolower($regionCode), $regionCountriesList, true)) {
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
     * Returns national number formated such as: 0451 495905
     * @param $number
     * @param string $countryCode
     * @return string
     */
    public static function normalizePhoneNumberNational($number, $countryCode = 'de')
    {
        $number = Datafilter::normalizePhoneNumber($number, $countryCode, 2);
        if (!$number) {
            return $number;
        }
        $number = '0' . substr($number, 0, 3) . ' ' . substr($number, 3);
        return $number;
    }

    /*
     * Manipulate cities name (?)
     * @todo author
     *
     * @param string $query
     * @return  array
     */

    /**
     * normalizes phone numbers
     * formats available:
     * PhoneNumberFormat::INTERNATIONAL
     * PhoneNumberFormat::NATIONAL
     * PhoneNumberFormat::E164
     *
     * @param $number
     * @param string $countryCode e.g. de
     * @param int $format
     * @params bool $returnFragments
     * @return string
     */
    public static function normalizePhoneNumber($number, $countryCode = 'de', $format = PhoneNumberFormat::INTERNATIONAL, $returnFragments = false)
    {
        $number = str_replace('&nbsp', ' ', $number);
        $phoneUtil = PhoneNumberUtil::getInstance();
        try {
            $parsedNumber = $phoneUtil->parseAndKeepRawInput($number, strtoupper($countryCode));
            if ($returnFragments) {
                return (object)array(
                    'countryCode' => $parsedNumber->getCountryCode(),
                    'nationalNumber' => $parsedNumber->getNationalNumber()
                );
            }
            if ($phoneUtil->isValidNumber($parsedNumber)) {
//			    echo "IS VALID '$countryCode':";
                return $phoneUtil->format($parsedNumber, $format);
            } else {
//			    echo "NOT VALID '$countryCode':";
                return $number;
            }
        } catch (NumberParseException $e) {
            return $number;
        }
    }

    public static function normalizeAndFilterSpecialChars($text, $whiteList = '')
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
    public static function normalizeNonGermanDiacritics($text)
    {
        $diacritics_from = array(
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
        );
        $diacritics_to = array(
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
        );

        $text = str_replace($diacritics_from, $diacritics_to, $text);
        return $text;
    }

    /**
     * Takes out special chars and leave only alphanumeric text.
     *
     * @param $text
     * @return mixed
     */
    public static function filterSpecialChars($text, $whiteList = '')
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
    public static function escapeRegexSpecialChars($text)
    {
        $aux = array();
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
    public static function utf8_strrev($str)
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
    public static function UTF2UCS($str, $s = false)
    {
        $str = strtolower($str);
        $char = 'UTF-8';
        $arr = array();
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

    // remove common words. used initially on companyName (when building search query) and when comparing.

    public static function microtime_float()
    {
        [$usec, $sec] = explode(' ', microtime());
        return ((float)$usec + (float)$sec);
    }

    /**
     * Return date based on locale
     * eg:
     * for us: 12/23/2015
     * for europe: 23.12.2015
     * for uk: 23/12/2015
     * @param null $time
     * @return string
     */
    public static function getLocaleDate($time = null)
    {
        $time = $time ? $time : time();
        // note, first the locale must be set as: eg setlocale(LC_TIME, array('en_US'. '.utf8', 'en_US'));
        $date = strftime('%x', $time);
        $year = strftime('%Y', $time);
        // bad thing is that %x doesn't return year in 4digts.. so we shall replace. we assume the year is shown in the end evertime.
        $aux = preg_split('#([^0-9a-b]+)#', $date, -1, PREG_SPLIT_DELIM_CAPTURE);
        array_pop($aux);
        $aux[] = $year;
        $date = implode('', $aux);
        return $date;
    }

    /**
     * Return date with time based on locale
     * @param null $time
     * @return string
     * @deprecated maybe
     * eg:
     * for us: 12/23/2015 04:00PM
     * for europe: 23.12.2015 16:00
     */
    public static function getLocaleDateTime($time = null, $removeSeconds = true)
    {
        $time = $time ? $time : time();
        // note, first the locale must be set as: eg setlocale(LC_TIME, array('en_US'. '.utf8', 'en_US'));
        $date = strftime('%x', $time);
        $year = strftime('%Y', $time);
        // bad thing is that %x doesn't return year in 4digts.. so we shall replace. we assume the year is shown in the end evertime.
        $aux = preg_split('#([^0-9a-b]+)#', $date, -1, PREG_SPLIT_DELIM_CAPTURE);
        array_pop($aux);
        $aux[] = $year;
        $date = implode('', $aux);
        $hour = strftime('%X', $time);
        if ($removeSeconds) {
            preg_match('#(am|pm)$#i', $hour, $match);
            $hour = explode(':', $hour);
            array_pop($hour);
            $hour = implode(':', $hour);
        }
        return trim($date . ' ' . $hour . (!empty($match[1]) ? $match[1] : ''));
    }

    /**
     * strtotime doesn't work when passing GB date, which is separated as US ones (/).. that make it confused.
     * so we preprocess this.
     * @param string $strdate
     * @return
     */
    public static function getLocaleUnixTimestamp($strdate)
    {
        if (self::$locale) {
            $locale = self::$locale;
        } else {
//			$locale = self::$locale = setlocale(LC_TIME, 0);
            $locale = self::$locale = orm::getLocale();
        }

        //this is because us and ca have date format mm/dd/yyyy
        if (strpos($locale, 'us') == true || strpos($locale, 'ca') == true || strpos($locale, 'au') == true || strpos($locale, 'be') == true) {
            $result = strtotime($strdate);
            return $result;
        } else {
            $result = strtotime(str_replace('/', '.', $strdate));
            return $result;
        }
    }

    public static function validateUberallText($text, $returnUnmatched = true)
    {
        // ty http://stackoverflow.com/questions/8082784/get-mystery-characters-ord-value-in-php
        $text = preg_replace('#\xad#u', '', $text);

        // when validating the text for special characters we decode the data
        $text = self::htmlspecialchars_decode($text);
        $valid = false;
        // uberall accepts only these
        $pattern = "\p{L}0-9\(\)\[\]\?:;\/!\,\。・\.\-%\&\s\r\n\t_\*§²`´·’\"'\+¡¿@\”\“\％\＊\＆\@\!\+";
        if (!$text || preg_match("#^[$pattern]+$#isu", $text)) {
            $valid = true;
        } else {
            preg_match_all("#[^$pattern]+#isu", $text, $matches);
        } // PREG_OFFSET_CAPTURE
        //preg_match("#^[$pattern]+$#isu", $text, $x);
        //var_dump($x);
        if ($returnUnmatched) {
            return (object)['valid' => $valid, 'unmatched' => (isset($matches[0]) ? $matches[0] : '')];
        }
        return $valid;
    }

    public static function htmlspecialchars_decode($text)
    {
        $text = str_replace('&apos;', "'", $text); // damn you php
        return htmlspecialchars_decode($text, ENT_QUOTES);
    }

    public static function validateCompanyName($text, $returnUnmatched = true)
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

    public static function escapeQuotesForJavascriptJSON($text)
    {
        return str_replace(["'", "'", '"'], ["\'", '&#039;', '&quot;'], $text);
        return htmlentities(str_replace("'", "\'", $text), ENT_QUOTES);
    }

    /**
     * Returns amount and currency localized
     * @param $value
     * @param $currency
     * @return mixed|string
     */
    public static function formatNumberWithCurrency($value, $currency = null, $decimals = true, $localeOverwrite = null)
    {
        //setlocale(LC_MONETARY, orm::getlocale());
        setlocale(LC_NUMERIC, null); // fix  NaN
        if (!$localeOverwrite) {
            $nf = new NumberFormatter(orm::getlocale(), NumberFormatter::CURRENCY);
        } else {
            $nf = new NumberFormatter($localeOverwrite, NumberFormatter::CURRENCY);
        }
        if (!$currency) {
            if (self::$currency) {
                $currency = self::$currency;
            } else {
                self::$currency = $currency = orm::getCountry(true)->currency;
            }
        }

        if (!$decimals) {
            $nf->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 0);
            $nf->setAttribute(NumberFormatter::MAX_SIGNIFICANT_DIGITS, 7);
        }
        if ($value == '') {
            return $value;
        }

        $return = $nf->formatCurrency($value, $currency);

        return $return;
    }

    /**
     * @param null $currency
     * @param null $localeOverwrite
     * @return string
     */
    public static function getCurrencySymbol($localeOverwrite = null)
    {
        //setlocale(LC_MONETARY, orm::getlocale());
        setlocale(LC_NUMERIC, null); // fix  NaN
        if (!$localeOverwrite) {
            $nf = new NumberFormatter(orm::getlocale(), NumberFormatter::CURRENCY);
        } else {
            $nf = new NumberFormatter($localeOverwrite, NumberFormatter::CURRENCY);
        }

        $symbol = $nf->getSymbol(NumberFormatter::CURRENCY_SYMBOL);

        return $symbol;
    }

    /**
     * @param null $currency
     * @param null $localeOverwrite
     * @return string
     */
    public static function getCurrencyDecimalSeparator($localeOverwrite = null)
    {
        //setlocale(LC_MONETARY, orm::getlocale());
        setlocale(LC_NUMERIC, null); // fix  NaN
        if (!$localeOverwrite) {
            $nf = new NumberFormatter(orm::getlocale(), NumberFormatter::CURRENCY);
        } else {
            $nf = new NumberFormatter($localeOverwrite, NumberFormatter::CURRENCY);
        }

        $symbol = $nf->getSymbol(NumberFormatter::MONETARY_SEPARATOR_SYMBOL);

        return $symbol;
    }

    public static function removeConsecutivePunctuations($text, $remove_only_same_occurrence = true)
    {
        // detects sequences as: ! ! ! (<punctuation <space>) and normalize.
        $text = preg_replace('#([^\s\t\p{L}\p{N}])[\s\t]+(?![\p{L}\p{N}])#usi', '$1', $text);
        if ($remove_only_same_occurrence) {
            return preg_replace('#([^\/\p{L}\p{N}])\1{1,}#usi', '$1', $text);
        }
        return preg_replace('#([^\/\s\p{L}\p{N}]){1,}#usi', '$1', $text);
    }

    public static function textContainsUrl($text)
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
    public static function hasMaxCharactersAllowed($text, $max_characters)
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

    public static function textContainsEmail($text)
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
    public static function textContainsURLPrefix($text)
    {
        if (preg_match('/^(?:https?:\/\/|www.)/i', $text)) {
            return true;
        }
        return false;
    }

    public static function textContainsPhoneNumber($text)
    {
        $user = AuthService::instance()->get_user();
        $phoneNumberUtil = PhoneNumberUtil::getInstance();
        $phoneNumberMatcher = $phoneNumberUtil->findNumbers($text, $user->site->country->country_shortcode);

        foreach ($phoneNumberMatcher as $phoneNumberMatch) {
            return true;
        }

        $text = str_replace(array('(', ')', '/'), '', $text);

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

    public static function textHasOnlyDigits($text)
    {
        // checks if text has only digits and space
        $regex = '"^[0-9 ]+$"';
        if (preg_match($regex, $text)) {
            return true;
        }
        return false;
    }

    public static function textHasToManyPunctuation($text)
    {
        // checks if text has more than 6 digits
        $count = preg_match_all('/[[:punct:]]/', $text);
        if ($count > 6) {
            return true;
        }
        return false;
    }

    public static function getYoutubeID($url)
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

    public static function maskIBAN($iban)
    {
        return substr_replace(substr_replace($iban, 'xx', 2, 2), 'xxxxxx', 12, 6);
    }

    public static function roundMinutes($time, $minutes)
    {
        return round($time / ($minutes * 60)) * ($minutes * 60);
    }

    public static function localeForJavascript($locale)
    {
        $language = substr($locale, 0, 2);
        $country = substr($locale, 3, 2);

        $country = strtoupper($country);

        return "$language-$country";
    }

    public static function replaceUTF8SoftHyphen($text)
    {
        $text = str_replace('­', '', $text);
        return $text;
    }

    /** this clears all the characters that are not alphanumeric and replaces them with space */
    public static function clearNonAlphaNumericCharacters($text)
    {
        $text = preg_replace('~[^a-zA-Z 0-9]+~', ' ', $text);
        return $text;
    }

    public static function getCityFromAddress($address)
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
    public static function isLatitude($value)
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
    public static function isLongitude($value)
    {
        if (preg_match('/^[-]?((((1[0-7][0-9])|([0-9]?[0-9]))\.(\d+))|180(\.0+)?)$/', $value)) {
            return true;
        }

        return false;
    }

    /**
     * A function that returns the lowest integer that is not 0.
     * @param array $values
     * @return mixed
     */
    public static function minNotNull(array $values)
    {
        return min(array_diff(array_map('intval', $values), array(0)));
    }

    /**
     * Validates the postal code with the help of a helper class
     */
    public static function validatePostalCode($countryShortCode, $value)
    {
        $validator = new Validator_PostalCode();

        return $validator->isValid(mb_strtoupper($countryShortCode), $value);
    }

    public static function validateMobilePhoneNumber($countryShortCode, $value)
    {
        $validator = new Validator_MobilePhoneNumber();

        return $validator->isValid(mb_strtoupper($countryShortCode), $value);
    }

    /**
     * Validates an interval of 2 dates (usually)
     * @param $from
     * @param $to
     * @return bool
     */
    public static function validateRangeInterval($from, $to)
    {
        return $from <= $to;
    }

    /**
     * Replace language-specific characters by ASCII-equivalents. e.g. ö => oe
     * @param string $s
     * @return string
     */
    public static function normalizeDiacritics($s)
    {
        $replace = array(
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
        );
        return strtr($s, $replace);
    }

    /**
     * Returns true if the given $url is on the SSL protocol, as in getProtocolFromUrl it defaults to true
     *
     * @param $url
     * @return bool
     */
    public static function urlHasSecureProtocol($url)
    {
        return (self::getProtocolFromUrl($url) == 'https');
    }

    /**
     * Gets the protocol (scheme) from an $url string, it defaults to empty string
     *
     * @param $url
     * @return mixed|string
     */
    public static function getProtocolFromUrl($url)
    {
        $urlParts = parse_url($url);
        if (isset($urlParts['scheme'])) {
            return $urlParts['scheme'];
        }

        return '';
    }

    public static function format_vatID_with_country_code($vatID, $country_shortcode)
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
     * @author Draga Sergiu
     */
    public function cleanNum($data)
    {
        return str_replace(array(
            ' ',
            ',',
            '.',
            '\''
        ), '', $data);
    }

    /**
     * Get page from an url?
     * @param     $url
     * @return
     */
    public function urlpage($url)
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
    public function sitenormalize($url)
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
     * @param $string
     * @return string
     */
    public static function underscoreToCamelCase($string) :string
    {
        $words = explode('_', $string);
        $camelCase = '';
        foreach ($words as $word) {
            $camelCase .= ucfirst($word);
        }
        return lcfirst($camelCase);
    }
}
