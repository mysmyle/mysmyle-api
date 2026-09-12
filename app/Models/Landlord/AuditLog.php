<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $connection = 'landlord';

    protected $table = 'landlord_audit_logs';

    protected $fillable = ['landlord_admin_id', 'tenant_id', 'action', 'subject_type', 'subject_id', 'meta'];

    protected $casts = [
        'meta' => 'array',
    ];

    public static function record(
        ?int $adminId,
        string $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $meta = [],
        ?int $tenantId = null,
    ): void {
        static::create([
            'landlord_admin_id' => $adminId,
            'tenant_id' => $tenantId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'meta' => $meta,
        ]);
    }

    public function admin()
    {
        return $this->belongsTo(LandlordAdmin::class, 'landlord_admin_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
