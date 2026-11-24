<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planogram_sheet_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('equipment_type', 16); // shelf|cooler|stand
            $table->uuid('equipment_id');
            $table->string('image_path')->nullable();
            $table->longText('raw_ocr_text')->nullable();
            $table->string('status', 32)->default('draft'); // draft|ready|committed|failed
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_matched')->default(0);
            $table->unsignedInteger('rows_committed')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'equipment_type', 'equipment_id']);
            $table->index('status');
        });

        Schema::create('planogram_sheet_import_rows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_id')->constrained('planogram_sheet_imports')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('level_number')->nullable();
            $table->string('barcode', 14)->nullable();
            $table->string('product_name_ocr', 255)->nullable();
            $table->unsignedInteger('facings')->default(1);
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->uuid('level_id')->nullable();
            $table->string('status', 32)->default('pending'); // ok|missing_product|missing_level|skipped|committed
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['import_id', 'sort_order']);
            $table->index('barcode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planogram_sheet_import_rows');
        Schema::dropIfExists('planogram_sheet_imports');
    }
};
