<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdatePaymentsMetadataColumn extends Migration
{
    public function up()
    {
        // Pour MySQL/MariaDB
        Schema::table('payments', function (Blueprint $table) {
            $table->longText('metadata')->change();
        });
        
        // OU pour PostgreSQL
        // Schema::table('payments', function (Blueprint $table) {
        //     $table->json('metadata')->change();
        // });
    }

    public function down()
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->text('metadata')->change();
        });
    }
}