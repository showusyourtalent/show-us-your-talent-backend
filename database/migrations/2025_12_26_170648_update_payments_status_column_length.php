<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdatePaymentsStatusColumnLength extends Migration
{
    public function up()
    {
        Schema::table('payments', function (Blueprint $table) {
            // Augmenter la taille de la colonne status à VARCHAR(50)
            $table->string('status', 50)->default('pending')->change();
        });
    }

    public function down()
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });
    }
}