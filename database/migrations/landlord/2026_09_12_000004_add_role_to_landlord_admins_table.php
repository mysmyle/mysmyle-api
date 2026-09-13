<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landlord_admins', function (Blueprint $table) {
            // super_admin: full access. support: view + resend-setup-link +
            // impersonate only — no tenant lifecycle, no admin management,
            // no platform settings.
            $table->enum('role', ['super_admin', 'support'])->default('super_admin')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('landlord_admins', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
