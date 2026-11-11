<?php

namespace App\Services\Approvals;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Business side effects of an approval decision for one approvable type. */
interface ApprovalHandler
{
    /** Called when the final stage approves. */
    public function approved(Model $subject, ApprovalRequest $request, User $actor): void;

    public function rejected(Model $subject, ApprovalRequest $request, User $actor, string $comment): void;

    public function returned(Model $subject, ApprovalRequest $request, User $actor, string $comment): void;

    /** Optional hook when a non-final stage approves (e.g. to update a status label). */
    public function stageAdvanced(Model $subject, ApprovalRequest $request, User $actor): void;
}
