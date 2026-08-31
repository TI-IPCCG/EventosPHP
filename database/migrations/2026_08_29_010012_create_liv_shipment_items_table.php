<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quanto de cada item veio, e a que preço. É AQUI que a variação entra no
 * estoque: uma linha por (item, variação).
 *   livro          → variant_id NULL, uma linha
 *   camiseta P/M/G → três linhas, três saldos
 *
 * custo_unitario e preco_venda são SNAPSHOT: preço e desconto mudam entre
 * eventos, e o acerto de um evento passado precisa do valor daquele dia.
 *
 * ⚠ `variant_key` existe por causa do NULL: em índice UNIQUE, NULL nunca
 * colide com NULL, então UNIQUE(shipment_id, product_id, variant_id) deixaria
 * cadastrar o mesmo livro duas vezes na mesma remessa. Trocando NULL por 0, a
 * unicidade passa a valer nos dois casos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liv_shipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('liv_shipments')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('liv_products')->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()
                  ->constrained('liv_variants')->restrictOnDelete();  // NULL = sem variação
            $table->unsignedSmallInteger('quantidade');
            $table->decimal('custo_unitario', 10, 2);
            $table->decimal('preco_venda', 10, 2);

            $table->unsignedBigInteger('variant_key')->storedAs('IFNULL(`variant_id`, 0)');
            $table->unique(['shipment_id', 'product_id', 'variant_key'], 'liv_shipment_items_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liv_shipment_items');
    }
};
