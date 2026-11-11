<?php

namespace App\Services\Reports;

use App\Services\Audit\Auditor;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Streams a CSV download and audits the export (who exported what, with which filters). */
class CsvExport
{
    public static function download(string $report, array $headers, iterable $rows, array $filters = []): StreamedResponse
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('reports.export'), 403, 'You are not authorized to export reports.');
        Auditor::record('report.exported', null, [], ['report' => $report, 'filters' => $filters]);
        $file = \Illuminate\Support\Str::slug($report).'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : (is_array($v) ? json_encode($v) : $v), array_values((array) $row)));
            }
            fclose($out);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
