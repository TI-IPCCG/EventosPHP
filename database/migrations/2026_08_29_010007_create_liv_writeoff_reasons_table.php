<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motivos de baixa sem venda: Sorteio, Cortesia, Doação, Perda…
 * `gera_custo` é a regra de negócio virada dado: exemplar consignado que sai
 * não volta ao fornecedor, logo é devido a ele — a exceção é o exemplar que a
 * própria editora doou para o sorteio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liv_writeoff_reasons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('church_id')->index();
            $table->string('nome', 60);
            $table->boolean('gera_custo')->default(true);
            $table->boolean('ativo')->default(true);
            $table->unique(['church_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liv_writeoff_reasons');
    }
};
