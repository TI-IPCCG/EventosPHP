<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Os exemplares baixados.
 *
 * `gera_custo` é SNAPSHOT do motivo: se amanhã alguém desmarcar o custo do
 * motivo "Sorteio", o acerto de um evento passado não pode mudar.
 *
 * Mesma trava de exemplar da venda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liv_writeoff_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('writeoff_id')->constrained('liv_writeoffs')->cascadeOnDelete();
            $table->foreignId('copy_id')->constrained('liv_copies')->restrictOnDelete();
            $table->decimal('custo_unitario', 10, 2);       // snapshot
            $table->boolean('gera_custo')->default(true);   // snapshot do motivo
            $table->dateTime('cancelado_em')->nullable();   // espelha liv_writeoffs.cancelada_em

            $table->unsignedBigInteger('copy_ativo')->nullable()
                  ->storedAs('IF(`cancelado_em` IS NULL, `copy_id`, NULL)');
            $table->unique('copy_ativo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liv_writeoff_items');
    }
};
