<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rastro da correção, e de qual troca trouxe/levou cada item.
 *
 * ── liv_sales ──
 * Correção reescreve valor_bruto e taxa. Sem registrar quem mexeu e quando,
 * a venda simplesmente passa a valer outro número e ninguém sabe explicar a
 * diferença no fechamento.
 *
 * ── liv_sale_items ──
 * Duas colunas resolvem a leitura inteira, sem coluna de "motivo":
 *   · exchange_in_id  preenchido → o item ENTROU nesta troca
 *   · exchange_out_id preenchido → o item SAIU nesta troca (com cancelado_em)
 *
 * E é por ausência que se lê o resto: item com `cancelado_em` e SEM
 * exchange_out_id saiu por estorno da venda inteira (quando a venda tem
 * cancelada_em) ou por correção de lançamento (quando não tem). Três motivos
 * distinguidos sem inventar um ENUM que precisaria ser mantido em sincronia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('liv_sales', function (Blueprint $table) {
            $table->dateTime('corrigida_em')->nullable()->after('cancelada_por');
            $table->foreignId('corrigida_por')->nullable()->after('corrigida_em')
                  ->constrained('users')->nullOnDelete();
        });

        Schema::table('liv_sale_items', function (Blueprint $table) {
            $table->foreignId('exchange_in_id')->nullable()->after('cancelado_em')
                  ->constrained('liv_exchanges')->nullOnDelete();
            $table->foreignId('exchange_out_id')->nullable()->after('exchange_in_id')
                  ->constrained('liv_exchanges')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('liv_sale_items', function (Blueprint $table) {
            $table->dropForeign(['exchange_in_id']);
            $table->dropForeign(['exchange_out_id']);
            $table->dropColumn(['exchange_in_id', 'exchange_out_id']);
        });

        Schema::table('liv_sales', function (Blueprint $table) {
            $table->dropForeign(['corrigida_por']);
            $table->dropColumn(['corrigida_em', 'corrigida_por']);
        });
    }
};
