<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A TRANSAÇÃO. Uma venda com 3 itens = UMA linha aqui e 3 em liv_sale_items.
 * É o que faz a taxa de cartão incidir por transação e não por item.
 *
 * `vendida_em` é separado de `created_at` para o lançamento retroativo: o
 * registro nasce hoje, mas a venda aconteceu ontem.
 * `cancelada_em` é o estorno, soft: a venda cancelada segue consultável e sai
 * das somas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liv_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('payment_method_id')->nullable()
                  ->constrained('liv_payment_methods')->nullOnDelete();
            $table->foreignId('registrado_por')->nullable()
                  ->constrained('users')->nullOnDelete();
            $table->string('comprador', 150)->nullable();
            $table->decimal('valor_bruto', 10, 2);
            $table->decimal('taxa_percentual', 5, 2)->default(0);   // snapshot
            $table->decimal('taxa_valor', 10, 2)->default(0);       // sobre a transação
            $table->dateTime('vendida_em');
            $table->dateTime('cancelada_em')->nullable();
            $table->foreignId('cancelada_por')->nullable()
                  ->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['event_id', 'vendida_em']);   // ritmo de vendas
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liv_sales');
    }
};
