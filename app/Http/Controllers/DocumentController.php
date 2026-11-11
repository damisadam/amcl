<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Policies\RecordAccess;
use App\Services\Audit\Auditor;
use App\Services\Documents\DocumentStore;
use Illuminate\Http\Request;

/** Authenticated, authorized document download. Files are never served from a public path. */
class DocumentController extends Controller
{
    public function show(Request $request, Document $document, DocumentStore $store)
    {
        // Route-model binding is tenant-scoped; cross-tenant ids resolve to 404.
        abort_unless(RecordAccess::canDownload($request->user(), $document), 403);
        if (in_array($document->type?->code, ['CNIC_FRONT', 'CNIC_BACK', 'SELFIE', 'GUARANTOR_CNIC'], true) && $request->user()->isStaff()) {
            Auditor::record('document.sensitive_viewed', $document);
        }
        $content = $store->contents($document);
        $inline = in_array($document->mime, ['text/html', 'image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true);

        return response($content, 200, [
            'Content-Type' => $document->mime.($document->mime === 'text/html' ? '; charset=utf-8' : ''),
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '_', $document->original_name).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => $document->mime === 'text/html' ? "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src data:" : "default-src 'none'",
        ]);
    }
}
