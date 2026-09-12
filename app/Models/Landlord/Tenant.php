<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Tenant extends Model
{
    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_FAILED = 'failed';

    protected $connection = 'landlord';

    protected $fillable = [
        'name', 'slug', 'db_name', 'db_host', 'db_port',
        'db_username', 'db_password', 'status', 'provision_error', 'provisioned_at',
    ];

    protected $hidden = ['db_username', 'db_password'];

    protected $casts = [
        'db_username' => 'encrypted',
        'db_password' => 'encrypted',
        'provisioned_at' => 'datetime',
    ];

    public function markActive(): void
    {
        $this->update([
            'status' => self::STATUS_ACTIVE,
            'provision_error' => null,
            'provisioned_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'provision_error' => Str::limit($error, 1000, ''),
        ]);
    }
}
