<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $connection = 'tenant';

    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'meta'];

    protected $casts = [
        'meta' => 'array',
    ];

    public static function record(
        ?int $userId,
        string $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $meta = [],
    ): void {
        static::create([
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'meta' => $meta,
        ]);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
