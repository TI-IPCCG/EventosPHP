<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O que o módulo acrescenta a um evento.
 *
 * event_id como PK (1:1), espelho exato de liv_event_settings. Fica FORA de
 * `events` de propósito: núcleo não recebe coluna de módulo. Se cada módulo
 * pudesse pôr a sua, `events` viraria depósito no segundo módulo.
 *
 * `slug_publico` é a URL do formulário aberto (fase 2). UNIQUE global porque a
 * URL tem de ser inequívoca; NULL não colide, então evento sem formulário
 * público simplesmente não tem slug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('par_event_settings', function (Blueprint $table) {
            $table->foreignId('event_id')->primary()->constrained('events')->cascadeOnDelete();
            $table->foreignId('form_id')->nullable()->constrained('par_forms')->nullOnDelete();
            $table->string('slug_publico', 60)->nullable()->unique();   // fase 2
            $table->string('prefixo_codigo', 4)->default('INS');        // INS + 0042
            $table->unsignedSmallInteger('vagas')->nullable();          // NULL = sem limite
            $table->dateTime('inscricoes_de')->nullable();
            $table->dateTime('inscricoes_ate')->nullable();
            $table->text('texto_confirmacao')->nullable();              // entra no e-mail do QR
            $table->string('observacao', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('par_event_settings');
    }
};
