<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->setStatusValues(['active', 'suspended', 'provisioning', 'failed']);

        Schema::table('tenants', function (Blueprint $table) {
            $table->text('provision_error')->nullable()->after('status');
            $table->timestamp('provisioned_at')->nullable()->after('provision_error');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['provision_error', 'provisioned_at']);
        });

        $this->setStatusValues(['active', 'suspended', 'provisioning']);
    }

    /**
     * Widen the status enum on MySQL/MariaDB. Other drivers store the column as
     * a plain string already, so there is nothing to alter.
     */
    private function setStatusValues(array $values): void
    {
        $connection = DB::connection('landlord');

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'])) {
            return;
        }

        $list = implode(', ', array_map(fn ($v) => "'{$v}'", $values));

        $connection->statement(
            "ALTER TABLE tenants MODIFY status ENUM({$list}) NOT NULL DEFAULT 'provisioning'"
        );
    }
};
