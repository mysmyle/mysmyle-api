<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            // 'clinical' = a functional area shown in the product; 'system' = an app
            // capability (e.g. Control Panel) gated the same way but hidden from the nav.
            $table->string('kind', 20)->default('clinical')->after('abbreviation')->index();
        });
    }

    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
