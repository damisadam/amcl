<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class LoanCategory extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];
}
