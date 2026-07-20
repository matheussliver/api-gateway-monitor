<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_sources', function (Blueprint $table): void {
            $table->char('processed_prefix_hash', 64)->nullable()->after('file_size');
        });
    }

    public function down(): void
    {
        Schema::table('log_sources', function (Blueprint $table): void {
            $table->dropColumn('processed_prefix_hash');
        });
    }
};
