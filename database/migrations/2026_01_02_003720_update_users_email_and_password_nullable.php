<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // rendre email nullable et supprimer l'unicité
            $table->string('email')->nullable()->change();

            // rendre password nullable
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // remettre email non nullable et unique
            $table->string('email')->nullable(false)->unique()->change();

            // remettre password non nullable
            $table->string('password')->nullable(false)->change();
        });
    }
};
