<?php

function export_excel_rows(string $filename, array $headers, array $rows): void
{
    $safeFilename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
    if ($safeFilename === null || $safeFilename === '') {
        $safeFilename = 'export.xls';
    }
    if (!str_ends_with(strtolower($safeFilename), '.xls')) {
        $safeFilename .= '.xls';
    }

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    echo implode("\t", array_map('export_excel_escape_cell', $headers)) . "\r\n";
    foreach ($rows as $row) {
        echo implode("\t", array_map('export_excel_escape_cell', $row)) . "\r\n";
    }
    exit;
}

function export_excel_escape_cell($value): string
{
    $text = (string) ($value ?? '');
    $text = str_replace(["\t", "\r", "\n"], ' ', $text);
    return trim($text);
}
