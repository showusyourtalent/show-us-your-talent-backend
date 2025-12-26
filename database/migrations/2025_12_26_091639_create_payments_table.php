<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payments')) {

            Schema::create('payments', function (Blueprint $table) {
                $table->id();
                $table->string('reference')->unique();

                $table->foreignId('user_id')->nullable()
                    ->constrained()->nullOnDelete();

                $table->foreignId('edition_id')
                    ->constrained()->cascadeOnDelete();

                $table->foreignId('candidat_id')
                    ->constrained('users')->cascadeOnDelete();

                $table->foreignId('category_id')
                    ->constrained()->cascadeOnDelete();

                $table->string('transaction_id')->nullable()->unique();
                $table->decimal('amount', 12, 2);
                $table->string('currency')->default('XOF');
                $table->string('status')->default('pending');
                $table->string('payment_method')->nullable();
                $table->string('payment_token')->unique();

                $table->string('customer_email')->nullable();
                $table->string('customer_phone')->nullable();
                $table->string('customer_firstname')->nullable();
                $table->string('customer_lastname')->nullable();

                $table->decimal('fees', 12, 2)->default(0);
                $table->decimal('net_amount', 12, 2)->nullable();

                $table->json('metadata')->nullable();

                $table->timestamp('paid_at')->nullable();
                $table->timestamp('expires_at')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->index('status');
                $table->index('created_at');
                $table->index(['edition_id', 'candidat_id']);
            });

        } else {

            Schema::table('payments', function (Blueprint $table) {

                if (!Schema::hasColumn('payments', 'payment_method')) {
                    $table->string('payment_method')->nullable();
                }

                if (!Schema::hasColumn('payments', 'payment_token')) {
                    $table->string('payment_token')->unique();
                }

                if (!Schema::hasColumn('payments', 'metadata')) {
                    $table->json('metadata')->nullable();
                }

                if (!Schema::hasColumn('payments', 'fees')) {
                    $table->decimal('fees', 12, 2)->default(0);
                }

                if (!Schema::hasColumn('payments', 'net_amount')) {
                    $table->decimal('net_amount', 12, 2)->nullable();
                }

                if (!Schema::hasColumn('payments', 'deleted_at')) {
                    $table->softDeletes();
                }
            });

        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
