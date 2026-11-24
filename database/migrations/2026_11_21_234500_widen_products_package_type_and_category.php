<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        if (Schema::hasColumn('products', 'package_type')) {
            DB::statement('ALTER TABLE products ALTER COLUMN package_type TYPE varchar(255)');
        }

        if (Schema::hasColumn('products', 'category')) {
            DB::statement('ALTER TABLE products ALTER COLUMN category TYPE varchar(255)');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        if (Schema::hasColumn('products', 'package_type')) {
            DB::statement('ALTER TABLE products ALTER COLUMN package_type TYPE varchar(100)');
        }

        if (Schema::hasColumn('products', 'category')) {
            DB::statement('ALTER TABLE products ALTER COLUMN category TYPE varchar(100)');
        }
    }
};
