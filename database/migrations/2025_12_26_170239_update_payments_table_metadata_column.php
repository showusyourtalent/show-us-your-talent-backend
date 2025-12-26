<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdatePaymentsTableMetadataColumn extends Migration
{
    public function up()
    {
        // Pour MySQL
        if (Schema::hasColumn('payments', 'metadata')) {
            Schema::table('payments', function (Blueprint $table) {
                // Changer de text à longText (supporte jusqu'à 4GB)
                $table->longText('metadata')->nullable()->change();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('payments', 'metadata')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->text('metadata')->nullable()->change();
            });
        }
    }
}