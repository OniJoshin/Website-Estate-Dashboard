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
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained();
            $table->string('type');
            $table->string('status');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('accounts_found')->default(0);
            $table->unsignedInteger('accounts_created')->default(0);
            $table->unsignedInteger('accounts_updated')->default(0);
            $table->unsignedInteger('accounts_removed')->default(0);
            $table->unsignedInteger('domains_found')->default(0);
            $table->unsignedInteger('domains_created')->default(0);
            $table->unsignedInteger('domains_updated')->default(0);
            $table->unsignedInteger('domains_removed')->default(0);
            $table->unsignedInteger('errors_count')->default(0);
            $table->text('error_summary')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'started_at']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
