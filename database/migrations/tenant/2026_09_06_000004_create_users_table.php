<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // A staff account is linked to its person; a guest account is not and
            // carries its own `name` instead.
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            // Null means "not set up yet" — the account is waiting on its
            // set-password link and cannot authenticate.
            $table->string('password')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            // Set on a system-generated temporary password; EnsurePasswordIsSet
            // blocks the account until it is cleared.
            $table->boolean('must_change_password')->default(false);
            // Bumped to force every existing session for this user to be rejected
            // on its next request.
            $table->timestamp('sessions_invalidated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
