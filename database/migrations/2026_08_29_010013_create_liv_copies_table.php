<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UM EXEMPLAR FÍSICO. Cada unidade na mesa é uma linha com código único no
 * evento. Como o exemplar aponta para o shipment_item, ele já carrega a
 * variação: a camiseta M e a GG têm exemplares distintos, e o saldo de cada
 * tamanho sai de um COUNT — não existe campo de quantidade para desencontrar
 * do físico.
 *
 * `status` é o estado atual e único do exemplar; o histórico de como ele
 * chegou nesse estado está em sales/writeoffs.
 *
 * `event_id` é denormalizado (dá para chegar por shipment_item → shipment).
 * É de propósito: o código é único DENTRO do evento e toda tela ao vivo
 * filtra por evento — sem isso, todo saldo vira JOIN triplo no atendimento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liv_copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('shipment_item_id')->constrained('liv_shipment_items')->cascadeOnDelete();
            $table->string('codigo', 20);   // ECC001, CAM042
            $table->enum('status', ['disponivel', 'reservado', 'vendido', 'baixado', 'devolvido'])
                  ->default('disponivel');
            $table->timestamps();

            $table->unique(['event_id', 'codigo']);
            $table->index(['event_id', 'status']);   // saldo e alertas do painel
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liv_copies');
    }
};
