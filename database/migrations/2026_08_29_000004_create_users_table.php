<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IDENTIDADE. Própria deste app (decisão de 29/08/2026 — ver Docs/arquitetura §4).
 * Sem church_id: a pessoa pode servir em mais de uma congregação, e o vínculo
 * (perfil + ativo) mora em `memberships`.
 * email em 191 chars por causa do limite de chave utf8mb4 no cPanel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('email', 191)->unique();
            $table->string('password', 255);
            $table->string('telefone', 20)->nullable();
            $table->boolean('is_super')->default(false);
            $table->rememberToken();   // "continuar conectado": o voluntário na mesa
                                       // não quer refazer login no meio do evento
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
