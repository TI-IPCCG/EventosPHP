<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Os exemplares daquela venda.
 *
 * ⚠ `copy_ativo` é a trava de "um exemplar não sai duas vezes": vale copy_id
 * enquanto o item está ativo e NULL quando cancelado. Como NULL não colide em
 * UNIQUE, o banco garante no máximo UMA venda ativa por exemplar — mesmo que
 * dois voluntários toquem "vender" no mesmo segundo.
 *
 * Isto cobre a MESMA tabela. Vender e depois sortear o mesmo exemplar é
 * cross-tabela e cai nos gatilhos (migration ...020001).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liv_sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('liv_sales')->cascadeOnDelete();
            $table->foreignId('copy_id')->constrained('liv_copies')->restrictOnDelete();
            $table->decimal('preco', 10, 2);            // snapshot
            $table->decimal('custo_unitario', 10, 2);   // snapshot: base do acerto
            $table->dateTime('cancelado_em')->nullable();  // espelha liv_sales.cancelada_em

            $table->unsignedBigInteger('copy_ativo')->nullable()
                  ->storedAs('IF(`cancelado_em` IS NULL, `copy_id`, NULL)');
            $table->unique('copy_ativo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liv_sale_items');
    }
};
