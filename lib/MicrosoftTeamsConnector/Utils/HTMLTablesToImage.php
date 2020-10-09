<?php

namespace Inbenta\MicrosoftTeamsConnector\Utils;

use DOMElement;

class HTMLTablesToImage
{

    /**
     * Create an image from a table HTML Element
     *
     * @param DOMElement $table  DomElement of a table to convert into an image
     *
     * @return bool true on success or false on failure.
     */
    public static function createImageFromTable($table)
    {
        $array = [];
        $cols = [];
        $colvals = [];
        foreach($table->getElementsByTagName('tr') as $td) {
            $array[] = array_values(array_filter(explode("\n", str_replace(array("\n", "\r"), "\n", trim($td->nodeValue)))));
        }

        self::formatData($array, $cols, $colvals);

        return self::buildImageResource($array, $cols, $colvals);
    }

    /**
     * Format Table data
     *
     * @param array $array array to convert into image
     * @param array$cols array to convert into image
     * @param array $colvals array to convert into image
     */
    public static function formatData($array, &$cols, &$colvals)
    {
        foreach ($array as $key => $row ) {
            foreach ($row as $field => $val) {
                $val = trim($val);
                if(empty($val)){
                    continue;
                }
                if (!isset($cols[$field])) {
                    $cols[$field] = strlen($val);
                }
                else {
                    $cols[$field] = max($cols[$field], strlen($val));
                }
                $colvals[$key][] = $val;
            }
        }
    }

    /**
     * Return image resource
     *
     * @param array $array
     * @param array$cols
     * @param array $colvals
     *
     * @return bool|resource true on success or false on failure.
     */
    public static function buildImageResource($array, $cols, $colvals)
    {
        /**
         * calculate text widths in pixels
         */
        $pad = 5;
        $colnames = array_keys($cols);
        $colwidths = array_values($cols);

        foreach ($colwidths as $k => $v) {
            $colwidths[$k] = $v * \imagefontwidth(3) + 2 * $pad;
        }
        /**
         * calc image size and create
         */
        $rowheight = imagefontheight(3) + 2 * $pad;
        $numrows = count($array);
        $ih = ($numrows * $rowheight) + 1;
        $iw = array_sum($colwidths) + 2;

        $image = imagecreate($iw, $ih);
        $bg = imagecolorallocate($image, 0xFF, 0xFF, 0xFF);
        $bdr = imagecolorallocate($image, 0xAA, 0xAA, 0xAA);
        $tcol = imagecolorallocate($image, 0, 0, 0);

        /**
         * draw cell borders
         */
        $x = $y = 0;
        for ($y = 0; $y < $ih; $y += $rowheight) {
            imageline($image, 0, $y, $iw, $y, $bdr);
        }
        for ($i = 0, $n = count($colnames); $i <= $n; $i++) {
            if(isset($colwidths[$i])){
                imageline($image, $x, 0, $x, $ih, $bdr);
                $x += $colwidths[$i];
            }
        }
        imageline($image, $iw-1, 0, $iw-1, $ih, $bdr);
        /**
         * data
         */
        foreach ($colvals as $row => $items) {
            foreach ($items as $col => $text) {
                $y = ($row) * $rowheight + $pad;
                $x = self::textCenter($col, $text, $pad, 3, $colwidths);
                imagestring($image, 3, $x, $y, $text, $tcol);
            }
        }
        return $image;

    }

    /******************************************
     * text positioning functions
     *******************************************/
    public static function textLeft ($col, $text, $pad, $font, &$colwidths) {
        $x = array_sum(array_slice($colwidths, 0, $col)) + $pad;
        return $x;
    }

    public static function textRight ($col, $text, $pad, $font, &$colwidths) {
        $tw = strlen($text) * imagefontwidth($font);
        $x = array_sum(array_slice($colwidths, 0, $col+1)) - $pad - $tw;
        return $x;
    }

    public static function textCenter ($col, $text, $pad, $font, &$colwidths) {
        $tw = strlen($text) * imagefontwidth($font);
        $x1 = array_sum(array_slice($colwidths, 0, $col)) ;
        $x2 = array_sum(array_slice($colwidths, 0, $col+1)) ;
        return ($x1 + $x2 - $tw)/2;
    }

    /**
     * Convert given resource image to base64
     *
     * @param resource $image
     * @return string image base64 encoded
     */
    public static function imageResourceToJpgBase64($image){
        ob_start(); // Let's start output buffering.
        imagejpeg($image); //This will normally output the image, but because of ob_start(), it won't.
        $contents = ob_get_contents(); //Instead, output above is saved to $contents
        ob_end_clean(); //End the output buffer.

        return (base64_encode($contents));
    }

    public static function slugify($string, $delimiter = '-') {
        $clean = preg_replace("/[^a-zA-Z0-9\/_|+ -]/", '', $string);
        $clean = strtolower($clean);
        $clean = preg_replace("/[\/_|+ -]+/", $delimiter, $clean);
        $clean = trim($clean, $delimiter);
        return $clean;
    }

}
