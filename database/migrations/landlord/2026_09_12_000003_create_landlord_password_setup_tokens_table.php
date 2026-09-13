<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landlord_password_setup_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_admin_id')->constrained('landlord_admins')->cascadeOnDelete();
            $table->string('token', 64)->unique(); // sha256 hex of the emailed value
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landlord_password_setup_tokens');
    }
};
