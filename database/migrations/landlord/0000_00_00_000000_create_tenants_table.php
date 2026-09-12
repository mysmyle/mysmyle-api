<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('db_name')->unique();
            $table->string('db_host')->default('127.0.0.1');
            $table->string('db_port')->default('3306');
            $table->text('db_username'); // encrypted cast in model
            $table->text('db_password'); // encrypted cast in model
            $table->enum('status', ['active', 'suspended', 'provisioning'])
                ->default('provisioning');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
