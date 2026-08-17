<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('server_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained();
            $table->timestamp('checked_at');
            $table->boolean('reachable');
            $table->decimal('load_1m', 8, 2)->nullable();
            $table->decimal('load_5m', 8, 2)->nullable();
            $table->decimal('load_15m', 8, 2)->nullable();
            $table->json('partitions')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'checked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_checks');
    }
};
