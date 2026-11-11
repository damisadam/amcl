<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Livewire\Ops\Admin\Audit;
use App\Services\Audit\Auditor;
use Illuminate\Http\Request;

/** CSV export of the tenant audit trail. The export itself is audited (sensitive data export). */
class AuditExportController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless($request->user()->hasPermission('audit.view') && $request->user()->hasPermission('audit.export'), 403);
        $filters = $request->validate([
            'actor' => 'nullable|integer', 'action' => 'nullable|string|max:100', 'subject' => 'nullable|alpha_dash|max:60',
            'from' => 'nullable|date', 'to' => 'nullable|date', 'correlation' => 'nullable|alpha_dash|max:64',
        ]);
        $query = Audit::filtered($filters)->with('actor')->orderBy('id');
        $count = (clone $query)->count();
        Auditor::record('audit.exported', null, [], ['filters' => $filters, 'rows' => $count]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'time', 'actor', 'actor_type', 'action', 'subject_type', 'subject_id', 'old_values', 'new_values', 'reason', 'correlation_id', 'ip']);
            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    fputcsv($out, [$r->id, $r->created_at?->toDateTimeString(), $r->actor?->name, $r->actor_type, $r->action, $r->auditable_type ? class_basename($r->auditable_type) : '',
                        $r->auditable_id, json_encode($r->old_values), json_encode($r->new_values), $r->reason, $r->correlation_id, $r->ip]);
                }
            });
            fclose($out);
        }, 'audit-log-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
