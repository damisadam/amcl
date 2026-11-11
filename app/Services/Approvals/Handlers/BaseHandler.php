<?php

namespace App\Services\Approvals\Handlers;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Approvals\ApprovalHandler;
use Illuminate\Database\Eloquent\Model;

abstract class BaseHandler implements ApprovalHandler
{
    public function stageAdvanced(Model $subject, ApprovalRequest $request, User $actor): void {}

    public function returned(Model $subject, ApprovalRequest $request, User $actor, string $comment): void
    {
        $this->rejected($subject, $request, $actor, $comment);
    }
}
