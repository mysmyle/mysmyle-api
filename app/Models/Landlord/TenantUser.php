<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;

class TenantUser extends Model
{
    protected $connection = 'landlord';

    protected $table = 'tenant_users';

    protected $fillable = ['email', 'tenant_id', 'status'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
