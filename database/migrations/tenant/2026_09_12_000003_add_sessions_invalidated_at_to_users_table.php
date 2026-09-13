<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // A session issued before this timestamp is rejected, even if the
            // session store itself hasn't expired it yet. Set on force-logout
            // and on a whole tenant's suspend.
            $table->timestamp('sessions_invalidated_at')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sessions_invalidated_at');
        });
    }
};
