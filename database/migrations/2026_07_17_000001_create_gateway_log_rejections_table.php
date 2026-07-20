<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_log_rejections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('log_source_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('source_offset');
            $table->unsignedBigInteger('source_line');
            $table->text('reason');
            $table->dateTime('processed_at', 3)->index();

            $table->unique(['log_source_id', 'source_offset']);
            $table->index(['log_source_id', 'source_line']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_log_rejections');
    }
};
