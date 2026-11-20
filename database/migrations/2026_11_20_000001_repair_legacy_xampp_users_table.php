<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add account fields that may be missing from older XAMPP database copies.
     */
    public function up(): void
    {
        $missing = array_values(array_filter(
            ['role', 'phone', 'branch', 'is_active'],
            fn (string $column): bool => ! Schema::hasColumn('users', $column),
        ));

        if ($missing === []) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($missing): void {
            if (in_array('role', $missing, true)) {
                $table->enum('role', ['customer', 'admin'])->default('customer')->index();
            }

            if (in_array('phone', $missing, true)) {
                $table->string('phone', 32)->nullable();
            }

            if (in_array('branch', $missing, true)) {
                $table->string('branch')->nullable();
            }

            if (in_array('is_active', $missing, true)) {
                $table->boolean('is_active')->default(true)->index();
            }
        });
    }

    public function down(): void
    {
        // Keep these fields: some databases already had them before this repair.
    }
};
