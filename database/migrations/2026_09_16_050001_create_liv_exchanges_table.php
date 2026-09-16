<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A TROCA. O comprador voltou e levou outra coisa.
 *
 * ── POR QUE É TABELA, E NÃO UM UPDATE NA VENDA ────────────────────────
 * Quem pagou R$ 50 no PIX aparece no extrato como R$ 50, e a operadora já
 * reteve a taxa sobre 50 — mesmo que depois troque por um item de R$ 40 e
 * receba R$ 10 de volta em dinheiro. Reescrever `liv_sales.valor_bruto`
 * faria o relatório divergir do extrato e subestimar a taxa devida.
 *
 * Então a venda original fica INTACTA, e a diferença é um movimento próprio:
 * tem valor, forma de pagamento (o troco saiu do caixa em dinheiro; a
 * cobrança extra entrou no PIX) e taxa própria quando há cobrança.
 *
 * ── A DIFERENÇA ENTRE TROCA E CORREÇÃO ────────────────────────────────
 * Correção é "foi digitado errado, ninguém trocou nada" — o registro estava
 * errado desde o início e é reescrito, valor_bruto e taxa junto. Troca é "a
 * venda estava certa e o comprador voltou" — o passado fica, e o que muda é
 * o presente. São operações distintas porque o efeito financeiro é oposto.
 *
 * `diferenca` é COM SINAL: positivo = cobrado a mais, negativo = devolvido.
 * Uma coluna só em vez de duas porque nenhuma troca tem as duas coisas, e
 * duas colunas nullable convidariam a preencher as duas por engano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liv_exchanges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('liv_sales')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();

            // Como o dinheiro da diferença se moveu. NULL quando a troca saiu
            // par (diferenca = 0) e nada trocou de mãos.
            $table->foreignId('payment_method_id')->nullable()
                  ->constrained('liv_payment_methods')->nullOnDelete();

            $table->decimal('diferenca', 10, 2)
                  ->comment('com sinal: positivo = cobrado a mais, negativo = devolvido');

            // Taxa só existe sobre COBRANÇA adicional: devolver troco em
            // dinheiro não paga taxa a operadora nenhuma.
            $table->decimal('taxa_percentual', 5, 2)->default(0);
            $table->decimal('taxa_valor', 10, 2)->default(0);

            $table->string('motivo', 200)->nullable();
            $table->foreignId('registrado_por')->nullable()
                  ->constrained('users')->nullOnDelete();

            // DATETIME e nunca TIMESTAMP, como liv_sales.vendida_em: o valor
            // vem do PHP no fuso da congregação, não do time_zone da sessão.
            $table->dateTime('realizada_em');
            $table->timestamp('created_at')->nullable();

            $table->index(['event_id', 'realizada_em'], 'liv_exchanges_event_id_realizada_em_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liv_exchanges');
    }
};
