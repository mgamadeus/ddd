<?php
/** @noinspection PhpArithmeticTypeCheckInspection */

/**
 *  Some functions to be used for data manipulation.
 * !!! ATENTION, DO NOT MODIFY/SAVE THIS FILE IN OTHER ENCODING. USED ZEND UTF-8.
 *
 * @project  companysearchengine, ampel, seolyzer, addresscrawler
 */

namespace DDD\Infrastructure\Libs;

use HTMLPurifier;
use HTMLPurifier_AttrDef_CSS_Length;
use HTMLPurifier_AttrDef_CSS_Multiple;
use HTMLPurifier_AttrDef_Enum;
use HTMLPurifier_Config;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use stdClass;

define('_is_utf8_split', 5000);

class Datafilter
{
    /** @var HTMLPurifier */
    public static $htmlPurifier = null;

    public static $locale = null;

    public static $currency = null;

    public static $debug_url = null;

    // first we put in utf8 then in hex
    // Strange, functions doesnt work OK if using hexa. weird char? @todo research. \xC4\xE4\xD6\xF6\xDC\xFC\xDF\x20AC\xC0\xC2\xC8\xC9\xCA\xCB\xCE\xCF\xD4\x152\xD9\xDB\x178\xE0\xE2\xE8\xE9\xEA\xEB\xEE\xEF\xF4\x153\xF9\xFB\xFF\xC1\xCD\xD3\xDA\xD1\xE1\xED\xF3\xFA\xF1\xCC\xD2\xEC\xF2\x103\x15F\x163\x102\x15E\x162
    public static $special_charsOLD = 'ÄäÖöÜüß€ÀÂÄÈÉÊËÎÏÔŒÙÛÜŸàâäèéêëîïôœùûüÿÁÉÍÓÚÑÜáéíóúñüÀÈÉÌÒÓÙàèéìòóùăîâşţĂÎÂŞŢșțΑαΒβΓγΔδΕεΖζΗηΘθΙιΚκΛλΜμΝνΞξΟοΠπΡρΣσΣσςΤτΥυΦφΧχΨψΩωĄąĘęĆćŁłŃńŚśŹźŻżÓóόώ';

    // ok, NO need to list all unicode LETTERS. use this.
    public static $special_chars = '\p{L}\p{M}\p{N}';

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
        // NOTE: '<' and '>' are included for legacy compatibility with keyword matching.
        // These characters allow HTML tag fragments to pass through. If you use this array
        // to build a filter regex, ensure the output is HTML-escaped before rendering.
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
        /**
         * Decodes &amp; back to & in contexts like "Tom &amp; Jerry".
         *
         * @security WARNING: If called on HTML-encoded output, this partially reverses encoding.
         *           Ensure the result is not inserted into HTML without re-encoding.
         */
        return preg_replace_callback('/([a-zA-Z0-9]*\s*)&amp;(\s*[a-zA-Z0-9]*)/', function ($matches) {
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
            $purified = self::$htmlPurifier->purify($input);
            // HTMLPurifier encodes bare & as &amp;, < as &lt;, > as &gt; etc.
            // This is correct for HTML output but wrong for data storage — the values
            // are stored in the database and the frontend handles escaping at render time.
            // Decode HTML entities back to their literal characters.
            return html_entity_decode($purified, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (is_array($input)) {
            return array_map([self::class, 'sanitizeInput'], $input);
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
     * @return HTMLPurifier
     */
    public static function initializePurifier(array $customDefinitions = []): HTMLPurifier
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.DefinitionID', $customDefinitions['definition_id'] ?? 'default');
        $config->set('HTML.DefinitionRev', $customDefinitions['definition_rev'] ?? 1);

        // Explicitly restrict URL schemes. This prevents javascript:, vbscript:, data: etc.
        // (If you ever need data:image/... for inline images, it must be added consciously.)
        $config->set('URI.AllowedSchemes', [
            'http' => true,
            'https' => true,
            'mailto' => true,
            'tel' => true,
        ]);

        // Allow inline styles (style="...") in a controlled way.
        // NOTE: We must NOT use HTML.AllowedAttributes here, because it overrides *all* allowed
        // attributes and would strip essentials like img[src|alt], causing images to disappear.
        // Instead we set HTML.Allowed with a constrained tag+attribute allowlist.
        //
        // Keep in mind: allowing CSS increases UI-redressing risk; do not allow layout primitives
        // like position/top/left/z-index/opacity/transform/pointer-events, and any url()-loading props.
        $config->set('HTML.Allowed', implode(',', [
            // Headings
            'h1[id|class|style]',
            'h2[id|class|style]',
            'h3[id|class|style]',
            'h4[id|class|style]',
            'h5[id|class|style]',
            'h6[id|class|style]',

            // Text / structure
            'p[id|class|style]',
            'div[id|class|style]',
            'span[id|class|style]',
            'br',
            'hr',
            'blockquote[id|class|style]',
            'pre[id|class|style]',
            'code[id|class|style]',

            // Basic formatting
            'strong[id|class]',
            'em[id|class]',
            'b',
            'i',
            'u',
            'sub',
            'sup',

            // Links
            'a[id|class|href|title|target|rel|style]',

            // Lists
            'ul[id|class|style]',
            'ol[id|class|style]',
            'li[id|class|style]',

            // Tables
            'table[id|class|style]',
            'thead[id|class|style]',
            'tbody[id|class|style]',
            'tr[id|class|style]',
            'td[id|class|style|colspan|rowspan]',
            'th[id|class|style|colspan|rowspan]',

            // Images
            'img[id|class|src|alt|title|width|height|style]',
        ]));

        // Enable additional CSS modules. We'll still restrict via CSS.AllowedProperties.
        // - CSS.AllowTricky enables properties like display/visibility/opacity/overflow.
        // - CSS.Proprietary enables some additional CSS3-ish properties.
        $config->set('CSS.Proprietary', true);
        $config->set('CSS.AllowTricky', true);

        $config->set('CSS.Trusted', false);
        $config->set('CSS.AllowedProperties', [
            // Typography
            'color',
            'background-color',
            'font-family',
            'font-size',
            'font-style',
            'font-weight',
            'font-variant',
            'line-height',
            'letter-spacing',
            'word-spacing',
            'text-align',
            'text-decoration',
            'text-decoration-line',
            'text-decoration-style',
            'text-decoration-color',
            'text-transform',
            'text-indent',
            'white-space',

            // Spacing
            'margin',
            'margin-top',
            'margin-right',
            'margin-bottom',
            'margin-left',
            'padding',
            'padding-top',
            'padding-right',
            'padding-bottom',
            'padding-left',

            // Borders
            'border',
            'border-top',
            'border-right',
            'border-bottom',
            'border-left',
            'border-color',
            'border-top-color',
            'border-right-color',
            'border-bottom-color',
            'border-left-color',
            'border-style',
            'border-top-style',
            'border-right-style',
            'border-bottom-style',
            'border-left-style',
            'border-width',
            'border-top-width',
            'border-right-width',
            'border-bottom-width',
            'border-left-width',
            'border-radius',
            'border-top-left-radius',
            'border-top-right-radius',
            'border-bottom-left-radius',
            'border-bottom-right-radius',

            // Sizing
            'width',
            'height',
            'min-width',
            'max-width',
            'min-height',
            'max-height',
            'aspect-ratio',

            // Basic layout
            // NOTE: allowing display can enable display:none (UI redressing). We disallow "none" below via enum.
            'display',

            // Tables
            'border-collapse',
            'border-spacing',
            'table-layout',
            'caption-side',

            // Images
            'vertical-align',

            // Lists
            'list-style-type',
            'list-style-position',
        ]);

        // HTMLPurifier's default CSSDefinition is conservative and does not support newer flexbox
        // properties/values. If we want `display:flex` + gap/alignment, we need to add definitions.
        //
        // IMPORTANT:
        // - Do NOT call getCSSDefinition(true) / maybeGetRawCSSDefinition() here.
        //   In this HTMLPurifier build, the directive `CSS.DefinitionID` is not available, and
        //   requesting the *raw* CSS definition triggers a warning:
        //   "Cannot retrieve value of undefined directive CSS.DefinitionID".
        // - getCSSDefinition() (without the raw flag) is sufficient for extending allowed values.
        if ($cssDef = $config->getCSSDefinition()) {
            // Extend display to allow flex layouts.
            $cssDef->info['display'] = new HTMLPurifier_AttrDef_Enum([
                'inline',
                'block',
                'inline-block',
                'list-item',
                'table',
                'inline-table',
                'table-row-group',
                'table-header-group',
                'table-footer-group',
                'table-row',
                'table-column-group',
                'table-column',
                'table-cell',
                'table-caption',
                'flex',
                'inline-flex',
            ]);

            $cssDef->info['flex-direction'] = new HTMLPurifier_AttrDef_Enum([
                'row',
                'row-reverse',
                'column',
                'column-reverse',
            ]);

            $cssDef->info['flex-wrap'] = new HTMLPurifier_AttrDef_Enum([
                'nowrap',
                'wrap',
                'wrap-reverse',
            ]);

            $cssDef->info['justify-content'] = new HTMLPurifier_AttrDef_Enum([
                'flex-start',
                'flex-end',
                'center',
                'space-between',
                'space-around',
                'space-evenly',
            ]);

            $cssDef->info['align-items'] = new HTMLPurifier_AttrDef_Enum([
                'stretch',
                'flex-start',
                'flex-end',
                'center',
                'baseline',
            ]);

            $cssDef->info['align-content'] = new HTMLPurifier_AttrDef_Enum([
                'stretch',
                'flex-start',
                'flex-end',
                'center',
                'space-between',
                'space-around',
            ]);

            // gap supports one or two lengths; we'll accept up to two lengths, non-negative.
            $gapLength = new HTMLPurifier_AttrDef_CSS_Length('0');
            $cssDef->info['gap'] = new HTMLPurifier_AttrDef_CSS_Multiple($gapLength, 2);
            $cssDef->info['row-gap'] = $gapLength;
            $cssDef->info['column-gap'] = $gapLength;
        }

        // Allow a couple of HTML5-ish image attributes used in content (these are optional; if unknown
        // to HTMLPurifier they would be stripped even if present in the HTML).
        if ($def = $config->maybeGetRawHTMLDefinition()) {
            $def->addAttribute('img', 'loading', 'Enum#lazy,eager,auto');
            $def->addAttribute('img', 'decoding', 'Enum#sync,async,auto');
        }

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

        self::$htmlPurifier = new HTMLPurifier($config);
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

    // accents, diacritcs
    // public static $specialChars = '\x27\x41-\x5a\x5f\x61-\x7a\xc0-\xd6\xd8-\xf6\xf8-\xff\x100\x103\x17e\x180';

    /**
     * Entity decode.
     *
     * @security WARNING: This method converts HTML entities back to their literal characters
     *           (e.g. &lt;script&gt; → <script>). NEVER call this on output that has already
     *           been sanitized by HTMLPurifier or htmlspecialchars(). Use only on raw input
     *           BEFORE sanitization, or on data that will not be rendered as HTML.
     */
    public static function entity_decode(string $text): string
    {
        $text = html_entity_decode(str_replace('&nbsp;', ' ', $text), ENT_QUOTES, 'utf-8');
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
        // SECURITY: do not allow '<' or '>' through a "cleanText" plaintext sanitizer.
            '#[^~\r\n\ta-z ' . self::$special_chars . '90-9\.:,;=\-_\+\*&\?!/{}\(\)\[\]%$€' . $chars . '\|\\\]#isu',
            ' ',
            $data
        );
        $data = preg_replace('#[\r\n\t]#', ' ', $data);
        $data = preg_replace('/\s+/', ' ', $data);
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


        // PHP 8.2+: utf8_encode()/utf8_decode() are deprecated.
        // utf8_encode() historically converted ISO-8859-1 -> UTF-8.
        // Only apply this conversion if the string is NOT already valid UTF-8.
        if (!mb_check_encoding($data, 'UTF-8')) {
            $data = mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');
        }

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
            if (
                preg_match(
                    '#Â©|Ã¤|Ã¼|Ã¶|Ã®|ÄÅ|[^\w\s\d\x00-\x7F]|â¬|Â»|â¢|â€|€™|Â|Å|â|Å£|Ã¢|Ã|Ã§|°Ñ|Ð|Ä|ã|¼|å|¤|¾|é#su',
                    $word
                )
            ) {
                //if (!self::is_utf8($word)) {
                // utf8_decode() is deprecated; equivalent conversion UTF-8 -> ISO-8859-1
                $wordArr[] = mb_convert_encoding($word, 'ISO-8859-1', 'UTF-8');
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

    /**
     * Generate alias for a given string
     *
     * @param string $string
     * @return  string
     */
    public static function alias(string $string, string $spaceDelimiter = '-'): string
    {
        $string = trim(mb_strtolower($string));
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
        $string = preg_replace('/-{2,}/', '-', $string);
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
     * Clean keyword
     *
     * @param string $string
     * @param bool $useWhitelist
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
    ): ?stdClass {
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
            'path' => $path
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
        $string = preg_replace('/-{2,}/', '-', $string);
        $string = preg_replace('/-+$/', '', $string);
        $string = preg_replace('/^-+/', '', $string);
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

    /*
     * Reliable only for DE/AT/CH domains.
     * Compare domains and check if they are the same domain
     */

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
        // SECURITY NOTE:
        // The previous implementation removed the substring "script" from values, which is trivially bypassable
        // (e.g. <scrscriptipt> becomes <script> after replacement). This method MUST NOT be used as an XSS defense.
        //
        // We now normalize request values as plain text by stripping tags.
        // If you actually need to accept rich HTML, use sanitizeInput() explicitly at the call site.
        if ($request === null) {
            return null;
        }
        foreach ($request as $index => $value) {
            if (is_array($intValues) && in_array($index, $intValues)) {
                $request[$index] = (int)$value ? (int)$value : '';
            } else {
                // Convert scalars to string and remove all markup.
                // SECURITY: also recurse into nested arrays (e.g. foo[bar]=<script>...)
                if (is_scalar($value)) {
                    $request[$index] = strip_tags((string)$value);
                } elseif (is_array($value)) {
                    $request[$index] = self::cleanRequestFromScript($value, []);
                } else {
                    $request[$index] = $value;
                }
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
     * Takes out special chars and leave only alphanumeric text.
     *
     * @param $text
     * @return mixed
     */
    public static function filterSpecialChars(string $text, string $whiteList = ''): string
    {
        $whiteList = self::escapeRegexSpecialChars($whiteList);
        // remove all that is not a letter or number . \p{L} match any letter with diacritics too
        $text = preg_replace("#[^\p{L}0-9$whiteList]#uis", ' ', $text);
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

    public static function htmlspecialchars_decode(string $text): string
    {
        /**
         * @security WARNING: Reverses HTML encoding. Calling this on sanitized output
         *           re-introduces raw HTML and opens XSS vectors. Use only when the
         *           decoded value will NOT be rendered in an HTML context.
         */
        $text = str_replace('&apos;', "'", $text); // damn you php
        return htmlspecialchars_decode($text, ENT_QUOTES);
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

    /**
     * Checks if input is a valid timestamp
     *
     * @param string|int $timestamp
     * @return bool
     */
    public static function isValidTimestamp(string|int $timestamp): bool
    {
        return ((string)(int)$timestamp == $timestamp) && ($timestamp <= PHP_INT_MAX) && ($timestamp >= PHP_INT_MIN);
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
