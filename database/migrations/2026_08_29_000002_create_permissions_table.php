<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo global de ações do código (não é por igreja).
 * Convenção de slug: "area.acao" — o prefixo agrupa na tela de perfis, então
 * cada módulo novo entra como um prefixo novo, sem mexer no schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('description', 255);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
