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
        Schema::create('cpanel_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained();
            $table->string('username');
            $table->string('primary_domain')->nullable();
            $table->string('home_directory')->nullable();
            $table->string('package')->nullable();
            $table->string('owner')->nullable();
            $table->boolean('suspended')->default(false)->index();
            $table->text('suspension_reason')->nullable();
            $table->unsignedBigInteger('disk_used_bytes')->nullable();
            $table->unsignedBigInteger('disk_limit_bytes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('discovered_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'username']);
            $table->index(['server_id', 'removed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cpanel_accounts');
    }
};
