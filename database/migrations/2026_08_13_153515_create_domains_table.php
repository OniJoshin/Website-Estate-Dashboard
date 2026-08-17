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
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cpanel_account_id')->constrained();
            $table->string('domain');
            $table->string('type');
            $table->foreignId('parent_domain_id')->nullable()->constrained('domains')->nullOnDelete();
            $table->string('document_root')->nullable();
            $table->string('classification');
            $table->string('classification_source')->default('auto');
            $table->boolean('monitoring_enabled')->default(true)->index();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamp('discovered_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['cpanel_account_id', 'domain']);
            $table->index(['cpanel_account_id', 'removed_at']);
            $table->index('classification');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
