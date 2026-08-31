<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo pessoa ↔ congregação: perfil e status ATIVO são POR igreja.
 * O cadastro público entra com status = false (aguardando ativação).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('church_id')->index();
            $table->foreignId('system_role_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('status')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->unique(['user_id', 'church_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
