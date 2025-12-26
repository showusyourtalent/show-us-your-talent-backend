<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('vote_settings')) {

            Schema::create('vote_settings', function (Blueprint $table) {
                $table->id();

                $table->foreignId('edition_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->foreignId('category_id')
                    ->nullable()
                    ->constrained()
                    ->cascadeOnDelete();

                $table->decimal('vote_price', 10, 2)->default(100);
                $table->boolean('is_paid')->default(true);
                $table->integer('free_votes_per_user')->default(0);
                $table->integer('max_votes_per_candidat')->nullable();
                $table->integer('max_votes_per_user')->nullable();

                $table->timestamp('vote_start')->nullable();
                $table->timestamp('vote_end')->nullable();

                $table->boolean('allow_mobile_money')->default(true);
                $table->boolean('allow_card')->default(true);
                $table->boolean('allow_bank_transfer')->default(true);

                $table->timestamps();

                $table->unique(['edition_id', 'category_id']);
            });

        } else {

            Schema::table('vote_settings', function (Blueprint $table) {

                if (!Schema::hasColumn('vote_settings', 'vote_price')) {
                    $table->decimal('vote_price', 10, 2)->default(100);
                }

                if (!Schema::hasColumn('vote_settings', 'is_paid')) {
                    $table->boolean('is_paid')->default(true);
                }

                if (!Schema::hasColumn('vote_settings', 'free_votes_per_user')) {
                    $table->integer('free_votes_per_user')->default(0);
                }

                if (!Schema::hasColumn('vote_settings', 'max_votes_per_candidat')) {
                    $table->integer('max_votes_per_candidat')->nullable();
                }

                if (!Schema::hasColumn('vote_settings', 'max_votes_per_user')) {
                    $table->integer('max_votes_per_user')->nullable();
                }

                if (!Schema::hasColumn('vote_settings', 'vote_start')) {
                    $table->timestamp('vote_start')->nullable();
                }

                if (!Schema::hasColumn('vote_settings', 'vote_end')) {
                    $table->timestamp('vote_end')->nullable();
                }

                if (!Schema::hasColumn('vote_settings', 'allow_mobile_money')) {
                    $table->boolean('allow_mobile_money')->default(true);
                }

                if (!Schema::hasColumn('vote_settings', 'allow_card')) {
                    $table->boolean('allow_card')->default(true);
                }

                if (!Schema::hasColumn('vote_settings', 'allow_bank_transfer')) {
                    $table->boolean('allow_bank_transfer')->default(true);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vote_settings');
    }
};
