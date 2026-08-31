<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A baixa sem venda. Cabeçalho + itens pelo mesmo motivo da venda: sortear
 * três livros de uma vez informa motivo e autorização UMA vez.
 *
 * `autorizado_por` é texto livre porque quem autoriza nem sempre tem login
 * (o preletor, o pastor) — a regra pede o registro, não o usuário.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liv_writeoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('reason_id')->constrained('liv_writeoff_reasons')->restrictOnDelete();
            $table->string('autorizado_por', 150);
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('observacao', 255)->nullable();
            $table->dateTime('registrada_em');
            $table->dateTime('cancelada_em')->nullable();
            $table->foreignId('cancelada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liv_writeoffs');
    }
};
