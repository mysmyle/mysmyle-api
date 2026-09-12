<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;

/**
 * A single named blob of configuration in the landlord DB, edited by super
 * admins. Read through App\Support\SettingsRepository (cached), not directly.
 */
class Setting extends Model
{
    protected $connection = 'landlord';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'array'];
}
