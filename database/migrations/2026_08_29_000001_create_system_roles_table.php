<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfis de acesso, POR congregação. church_id é vínculo LÓGICO cross-db
 * (aponta para churches no banco central) — só índice, sem FK física.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('church_id')->index();
            $table->string('name', 50);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_roles');
    }
};
