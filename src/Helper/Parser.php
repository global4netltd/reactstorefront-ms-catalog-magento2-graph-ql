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
        $searchText = strtolower(preg_replace('/[^a-zA-Z0-9_\- ]+/ui', '', $text));
        $searchText = trim(str_replace('-', ' ', $searchText));
        $searchText = Parser::escape(str_replace('\\', '', $searchText));
        $searchText = Parser::parseIsInt($searchText);

        return $searchText;
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
                if ($string{0} == '"' && $string{$stringLength - 1} == '"') {
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
