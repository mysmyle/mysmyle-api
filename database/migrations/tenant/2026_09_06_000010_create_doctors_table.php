<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            // A doctor is a staff member with a licence — one record each, never
            // orphaned, so the link is unique and required rather than nullable.
            $table->foreignId('staff_id')->unique()->constrained('staff')->cascadeOnDelete();
            // The short calendar label ("Dr. Emadeldin"). The full legal name is
            // not repeated here — it lives on the staff record.
            $table->string('display_name');
            $table->string('license_number')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};
