<?php

namespace G4NReact\MsCatalogMagento2GraphQl\Helper;

/**
 * Class Parser
 * @package Global4net\CatalogGraphQl\Helper
 */
class Parser
{
    /**
     * @var int
     */
    const MAX_INT = 2147483647;

    /**
     * @param $text
     * @return string
     */
    public static function parseSearchText($text)
    {
        $searchText = self::convertPolishLetters($text);
        $searchText = strtolower(preg_replace('/[^a-zA-Z0-9_\- ]+/ui', '', $searchText));
        $searchText = trim(str_replace('-', ' ', $searchText));
        $searchText = Parser::escape(str_replace('\\', '', $searchText));
        $searchText = Parser::parseIsInt($searchText);

        return $searchText;
    }


    /**
     * @todo temporary
     * Converts polish letters to non diacritic version
     * @param $string
     * @return string
     */
    public static function convertPolishLetters($string)
    {
        $table = Array(
            //WIN
            "\xb9"     => "a", "\xa5" => "A", "\xe6" => "c", "\xc6" => "C",
            "\xea"     => "e", "\xca" => "E", "\xb3" => "l", "\xa3" => "L",
            "\xf3"     => "o", "\xd3" => "O", "\x9c" => "s", "\x8c" => "S",
            "\x9f"     => "z", "\xaf" => "Z", "\xbf" => "z", "\xac" => "Z",
            "\xf1"     => "n", "\xd1" => "N",
            //UTF
            "\xc4\x85" => "a", "\xc4\x84" => "A", "\xc4\x87" => "c", "\xc4\x86" => "C",
            "\xc4\x99" => "e", "\xc4\x98" => "E", "\xc5\x82" => "l", "\xc5\x81" => "L",
            "\xc3\xb3" => "o", "\xc3\x93" => "O", "\xc5\x9b" => "s", "\xc5\x9a" => "S",
            "\xc5\xbc" => "z", "\xc5\xbb" => "Z", "\xc5\xba" => "z", "\xc5\xb9" => "Z",
            "\xc5\x84" => "n", "\xc5\x83" => "N",
            //ISO
            "\xb1"     => "a", "\xa1" => "A", "\xe6" => "c", "\xc6" => "C",
            "\xea"     => "e", "\xca" => "E", "\xb3" => "l", "\xa3" => "L",
            "\xf3"     => "o", "\xd3" => "O", "\xb6" => "s", "\xa6" => "S",
            "\xbc"     => "z", "\xac" => "Z", "\xbf" => "z", "\xaf" => "Z",
            "\xf1"     => "n", "\xd1" => "N");

        return strtr($string, $table);
    }

    /**
     * @param $filters
     * @return array
     */
    public static function parseFilters($filters)
    {
        $parsedFilters = [];
        foreach ($filters as $key => $filter) {
            $parsedFilters[$key] = preg_replace('/[^a-zA-Z0-9_\=\,\.\-]+/ui', '', $filter);
        }

        return $parsedFilters;
    }

    /**
     * @param $filter
     * @return string
     */
    public static function parseFilter($filter)
    {
        return preg_replace('/[^a-zA-Z0-9_\-]+/ui', '', $filter);
    }

    /**
     * @param $value
     * @return string
     */
    public static function parseFilterValue($value)
    {
        return preg_replace('/[^a-zA-Z0-9_\,]+/ui', '', $value);
    }

    /**
     * @param $value
     * @return string
     */
    public static function parseFilterValueNumeric($value)
    {
        return preg_replace('/[^0-9\,\-]+/ui', '', $value);
    }

    /**
     * @param $value
     * @return string
     */
    public static function parseFilterOnlyNumbers($value)
    {
        return preg_replace('/[^0-9]+/ui', '', $value);
    }

    /**
     * @param $value
     * @return int|void
     */
    public static function parseFilterValueBoolean($value)
    {
        switch ($value) {
            case true:
                return 1;
            case false:
                return 0;
        }

        return;
    }

    /**
     * @param $value
     * @return int
     */
    public static function parseIsInt($value)
    {
        return (is_int($value) && $value > self::MAX_INT) ? self::MAX_INT : $value;
    }

    /**
     * @param $array
     * @return array
     */
    public static function parseArrayIsInt($array)
    {
        $array = array_map('intval', $array);
        $array = array_filter($array, function ($id) {
            return is_int($id) && $id < self::MAX_INT;
        });

        return $array;
    }

    /**
     * Quote and escape search strings
     *
     * @param string $string String to escape
     * @return string The escaped/quoted string
     */
    public static function escape($string)
    {
        if (!is_numeric($string)) {
            if (preg_match('/\W/', $string) == 1) {
                // multiple words

                $stringLength = strlen($string);
                if ($string[0] == '"' && $string[$stringLength - 1] == '"') {
                    // phrase
                    $string = trim($string, '"');
                    $string = self::escapePhrase($string);
                } else {
                    $string = self::escapeSpecialCharacters($string);
                }
            } else {
                $string = self::escapeSpecialCharacters($string);
            }
        }
        $string = str_replace('-', '', $string);

        return $string;
    }

    /**
     * Escapes characters with special meanings in Lucene query syntax.
     *
     * @param string $value Unescaped - "dirty" - string
     * @return string Escaped - "clean" - string
     */
    private static function escapeSpecialCharacters($value)
    {
        // list taken from http://lucene.apache.org/core/4_4_0/queryparser/org/apache/lucene/queryparser/classic/package-summary.html#package_description
        // which mentions: + - && || ! ( ) { } [ ] ^ " ~ * ? : \ /
        // of which we escape: ( ) { } [ ] ^ " ~ : \ /
        // and explicitly don't escape: + - && || ! * ?
        $pattern = '/(\\(|\\)|\\{|\\}|\\[|\\]|\\^|"|~|\:|\\\\|\\/)/';
        $replace = '\\\$1';

        return preg_replace($pattern, $replace, $value);
    }

    /**
     * Escapes a value meant to be contained in a phrase with characters with
     * special meanings in Lucene query syntax.
     *
     * @param string $value Unescaped - "dirty" - string
     * @return string Escaped - "clean" - string
     */
    private static function escapePhrase($value)
    {
        $pattern = '/("|\\\)/';
        $replace = '\\\$1';

        return '"' . preg_replace($pattern, $replace, $value) . '"';
    }
}
