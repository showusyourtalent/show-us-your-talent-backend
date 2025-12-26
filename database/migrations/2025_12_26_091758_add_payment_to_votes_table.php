<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('votes')) {

            Schema::table('votes', function (Blueprint $table) {

                if (!Schema::hasColumn('votes', 'payment_id')) {
                    $table->foreignId('payment_id')
                        ->nullable()
                        ->constrained()
                        ->nullOnDelete();
                }

                if (!Schema::hasColumn('votes', 'is_paid')) {
                    $table->boolean('is_paid')->default(false);
                }

                if (!Schema::hasColumn('votes', 'vote_price')) {
                    $table->decimal('vote_price', 10, 2)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('votes')) {

            Schema::table('votes', function (Blueprint $table) {

                if (Schema::hasColumn('votes', 'payment_id')) {
                    $table->dropForeign(['payment_id']);
                    $table->dropColumn('payment_id');
                }

                if (Schema::hasColumn('votes', 'is_paid')) {
                    $table->dropColumn('is_paid');
                }

                if (Schema::hasColumn('votes', 'vote_price')) {
                    $table->dropColumn('vote_price');
                }
            });
        }
    }
};
