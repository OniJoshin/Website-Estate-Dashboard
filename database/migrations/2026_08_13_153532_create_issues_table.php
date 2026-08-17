<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->nullable()->constrained();
            $table->foreignId('cpanel_account_id')->nullable()->constrained();
            $table->foreignId('domain_id')->nullable()->constrained();
            $table->string('type');
            $table->string('severity');
            $table->string('title');
            $table->text('details')->nullable();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['resolved_at', 'severity']);
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER issues_exactly_one_target_insert
                BEFORE INSERT ON issues
                WHEN (
                    (NEW.server_id IS NOT NULL)
                    + (NEW.cpanel_account_id IS NOT NULL)
                    + (NEW.domain_id IS NOT NULL) != 1
                )
                BEGIN
                    SELECT RAISE(ABORT, 'An issue must target exactly one estate entity');
                END
                SQL);

            DB::statement(<<<'SQL'
                CREATE TRIGGER issues_exactly_one_target_update
                BEFORE UPDATE OF server_id, cpanel_account_id, domain_id ON issues
                WHEN (
                    (NEW.server_id IS NOT NULL)
                    + (NEW.cpanel_account_id IS NOT NULL)
                    + (NEW.domain_id IS NOT NULL) != 1
                )
                BEGIN
                    SELECT RAISE(ABORT, 'An issue must target exactly one estate entity');
                END
                SQL);
        } else {
            DB::statement(<<<'SQL'
                ALTER TABLE issues
                ADD CONSTRAINT issues_exactly_one_target_check
                CHECK (
                    (server_id IS NOT NULL)
                    + (cpanel_account_id IS NOT NULL)
                    + (domain_id IS NOT NULL) = 1
                )
                SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('issues');
    }
};
