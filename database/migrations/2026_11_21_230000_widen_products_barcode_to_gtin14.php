<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'barcode')) {
            return;
        }

        // EAN-8 … GTIN-14; was varchar(13). Native ALTER — no doctrine/dbal.
        DB::statement('ALTER TABLE products ALTER COLUMN barcode TYPE varchar(14)');

        if (Schema::hasColumn('products', 'package_type')) {
            DB::statement('ALTER TABLE products ALTER COLUMN package_type TYPE varchar(100)');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'barcode')) {
            return;
        }

        DB::statement('ALTER TABLE products ALTER COLUMN barcode TYPE varchar(13)');

        if (Schema::hasColumn('products', 'package_type')) {
            DB::statement('ALTER TABLE products ALTER COLUMN package_type TYPE varchar(50)');
        }
    }
};
