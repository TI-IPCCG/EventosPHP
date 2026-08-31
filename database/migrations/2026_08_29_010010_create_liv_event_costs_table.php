<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custos do evento. Genérica de propósito: frete é só o primeiro caso, e
 * criar "estacionamento" é INSERT, não desenvolvimento.
 *
 * `rateio` NÃO afeta o resultado — este soma o custo uma vez, pelo valor real.
 * O rateio serve só à SUGESTÃO de preço de venda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liv_event_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()
                  ->constrained('liv_suppliers')->nullOnDelete();  // frete é de um fornecedor
            $table->string('descricao', 150);
            $table->decimal('valor', 10, 2);
            $table->enum('rateio', [
                'direto', 'por_unidade_enviada', 'por_unidade_vendida', 'percentual_venda',
            ])->default('direto');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liv_event_costs');
    }
};
