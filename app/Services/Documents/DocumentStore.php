<?php

namespace App\Services\Documents;

use App\Exceptions\DomainException;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Private document storage. Files live on the non-public "private" disk under a tenant folder with
 * randomised names; metadata, checksum and version chain are kept in `documents`. The disk name is
 * configuration, so switching to private object storage requires no business-logic change.
 */
class DocumentStore
{
    public const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public const MAX_KB = 5120;

    public function store(UploadedFile $file, Model $owner, ?DocumentType $type, ?User $by, ?string $title = null, ?string $expiresOn = null): Document
    {
        $mime = $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new DomainException('Only JPG, PNG, WEBP or PDF files are accepted.');
        }
        if ($file->getSize() > self::MAX_KB * 1024) {
            throw new DomainException('Files must be smaller than '.(self::MAX_KB / 1024).' MB.');
        }
        $this->assertOwnerInTenant($owner);
        $ext = match ($mime) { 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => 'pdf' };
        $path = $this->dir($owner).'/'.Str::uuid().'.'.$ext;
        Storage::disk($this->disk())->put($path, file_get_contents($file->getRealPath()));

        return $this->record($owner, $type, $by, $title ?? $type?->name ?? $file->getClientOriginalName(), $path, mb_substr($file->getClientOriginalName(), 0, 200), $mime,
            $file->getSize(), hash_file('sha256', $file->getRealPath()), $expiresOn);
    }

    /** Store system-generated content (KFS, agreements, receipts, NOC) as a private HTML document. */
    public function storeGenerated(Model $owner, ?DocumentType $type, string $title, string $html, ?User $by = null, bool $synthetic = false): Document
    {
        $this->assertOwnerInTenant($owner);
        $path = $this->dir($owner).'/'.Str::uuid().'.html';
        Storage::disk($this->disk())->put($path, $html);
        $doc = $this->record($owner, $type, $by, $title, $path, Str::slug($title).'.html', 'text/html', strlen($html), hash('sha256', $html), null, $synthetic);
        $doc->forceFill(['status' => 'verified', 'verified_at' => now()])->save();

        return $doc;
    }

    private function record(Model $owner, ?DocumentType $type, ?User $by, string $title, string $path, string $original, string $mime, int $size, string $sha, ?string $expiresOn, bool $synthetic = false): Document
    {
        $previous = $type ? Document::where('documentable_type', $owner->getMorphClass())->where('documentable_id', $owner->getKey())
            ->where('document_type_id', $type->id)->where('status', '!=', 'superseded')->latest('id')->first() : null;

        $doc = Document::create([
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => $owner->getKey(),
            'document_type_id' => $type?->id,
            'title' => $title,
            'disk' => $this->disk(),
            'path' => $path,
            'original_name' => $original,
            'mime' => $mime,
            'size' => $size,
            'sha256' => $sha,
            'version' => $previous ? $previous->version + 1 : 1,
            'previous_id' => $previous?->id,
            'expires_on' => $expiresOn,
            'is_synthetic' => $synthetic,
            'uploaded_by' => $by?->id,
        ]);
        $previous?->forceFill(['status' => 'superseded'])->save();
        Auditor::record('document.uploaded', $doc, [], ['type' => $type?->code, 'version' => $doc->version, 'owner' => class_basename($owner).'#'.$owner->getKey()]);

        return $doc;
    }

    public function contents(Document $doc): string
    {
        return Storage::disk($doc->disk)->get($doc->path);
    }

    public function verify(Document $doc, User $by, bool $approve, ?string $reason = null): Document
    {
        if (! $approve && blank($reason)) {
            throw new DomainException('A reason is required to reject a document.');
        }
        $doc->forceFill(['status' => $approve ? 'verified' : 'rejected', 'verified_by' => $by->id, 'verified_at' => now(), 'rejection_reason' => $approve ? null : $reason])->save();
        Auditor::record($approve ? 'document.verified' : 'document.rejected', $doc, [], [], $reason);

        return $doc;
    }

    private function disk(): string
    {
        return config('filesystems.documents_disk', 'private');
    }

    private function dir(Model $owner): string
    {
        return 'tenants/'.TenantContext::id().'/'.Str::snake(class_basename($owner)).'/'.$owner->getKey();
    }

    private function assertOwnerInTenant(Model $owner): void
    {
        if ((int) $owner->getAttribute('tenant_id') !== (int) TenantContext::id()) {
            throw new \App\Exceptions\TenantIsolationViolation(class_basename($owner), $owner->getKey());
        }
    }
}
