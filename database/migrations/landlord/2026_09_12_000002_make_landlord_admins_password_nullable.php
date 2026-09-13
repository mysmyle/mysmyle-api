<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An invited admin has no password until they follow the emailed
        // set-password link; the column holds null in the meantime.
        Schema::table('landlord_admins', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('landlord_admins', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
