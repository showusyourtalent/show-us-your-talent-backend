<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE payments
            DROP CONSTRAINT payments_status_check
        ");

        DB::statement("
            ALTER TABLE payments
            ADD CONSTRAINT payments_status_check
            CHECK (status IN (
                'pending',
                'processing',
                'success',
                'failed',
                'cancelled',
                'approved', 
                'completed', 
                'paid'
            ))
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE payments
            DROP CONSTRAINT payments_status_check
        ");

        DB::statement("
            ALTER TABLE payments
            ADD CONSTRAINT payments_status_check
            CHECK (status IN (
                'pending',
                'success',
                'failed'
            ))
        ");
    }
};
