<?php

namespace App\Support;

class Csv
{
    /**
     * One row ready for fputcsv. Spreadsheets run any cell that starts with
     * =, +, - or @ as a formula, and names and reference numbers come from
     * users — so those cells get a leading quote that Excel treats as text.
     */
    public static function row(array $cells): array
    {
        return array_map(function ($cell) {
            $cell = (string) $cell;

            // A plain number, including a negative one, is not a formula: it
            // stays a number so spreadsheets can total it.
            if (is_numeric($cell)) {
                return $cell;
            }

            return preg_match('/^[=+\-@\t\r]/', $cell) ? "'".$cell : $cell;
        }, $cells);
    }
}
