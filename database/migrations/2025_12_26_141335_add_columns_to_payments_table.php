<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnsToPaymentsTable extends Migration
{
    public function up()
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'edition_id')) {
                $table->foreignId('edition_id')->constrained()->onDelete('cascade')->after('id');
            }
            if (!Schema::hasColumn('payments', 'candidat_id')) {
                $table->foreignId('candidat_id')->constrained('users')->onDelete('cascade')->after('edition_id');
            }
            if (!Schema::hasColumn('payments', 'category_id')) {
                $table->foreignId('category_id')->constrained()->onDelete('cascade')->after('candidat_id');
            }
            if (!Schema::hasColumn('payments', 'amount')) {
                $table->decimal('amount', 10, 2)->after('category_id');
            }
            if (!Schema::hasColumn('payments', 'currency')) {
                $table->string('currency', 3)->default('XOF')->after('amount');
            }
            if (!Schema::hasColumn('payments', 'status')) {
                $table->enum('status', ['pending','approved','failed','expired','cancelled'])->default('pending')->after('currency');
            }
            if (!Schema::hasColumn('payments', 'payment_token')) {
                $table->string('payment_token')->unique()->after('status');
            }
            if (!Schema::hasColumn('payments', 'payment_method')) {
                $table->string('payment_method')->nullable()->after('payment_token');
            }
            if (!Schema::hasColumn('payments', 'customer_email')) {
                $table->string('customer_email')->after('payment_method');
            }
            if (!Schema::hasColumn('payments', 'customer_phone')) {
                $table->string('customer_phone')->after('customer_email');
            }
            if (!Schema::hasColumn('payments', 'customer_firstname')) {
                $table->string('customer_firstname')->after('customer_phone');
            }
            if (!Schema::hasColumn('payments', 'customer_lastname')) {
                $table->string('customer_lastname')->after('customer_firstname');
            }
            if (!Schema::hasColumn('payments', 'metadata')) {
                $table->json('metadata')->nullable()->after('customer_lastname');
            }
            if (!Schema::hasColumn('payments', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('metadata');
            }
            if (!Schema::hasColumn('payments', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('paid_at');
            }
        });
    }

    public function down()
    {
        Schema::table('payments', function (Blueprint $table) {
            $columns = [
                'edition_id','candidat_id','category_id','amount','currency','status','payment_token',
                'payment_method','customer_email','customer_phone','customer_firstname','customer_lastname',
                'metadata','paid_at','expires_at'
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
