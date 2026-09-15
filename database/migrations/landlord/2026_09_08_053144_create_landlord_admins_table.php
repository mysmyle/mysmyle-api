<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landlord_admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            // An invited admin has no password until they follow the emailed
            // set-password link; the column holds null in the meantime.
            $table->string('password')->nullable();
            $table->enum('status', ['active', 'disabled'])->default('active');
            // super_admin: full access. support: view + resend-setup-link +
            // impersonate only — no tenant lifecycle, no admin management,
            // no platform settings.
            $table->enum('role', ['super_admin', 'support'])->default('super_admin');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landlord_admins');
    }
};
