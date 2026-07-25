<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class CandidateSpreadsheetPreviewImport implements ToArray, WithHeadingRow, SkipsEmptyRows
{
    public function array(array $array): void
    {
        // Excel::toArray() returns the parsed rows. No persistence is performed here.
    }
}
