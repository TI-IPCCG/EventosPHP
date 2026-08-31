<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desconto NO ITEM — exceção ao padrão do fornecedor.
 *
 * A editora costuma dar 40% em tudo, mas negocia diferente num título ou numa
 * linha específica. NULL significa "usa o do fornecedor", que é o caso comum:
 * assim mudar a condição geral não exige tocar em item nenhum.
 *
 * ⚠ É só SUGESTÃO de custo na hora de montar a remessa. O valor que vale para
 * o acerto continua sendo o `custo_unitario` gravado na linha da remessa, que
 * é snapshot — renegociar o desconto amanhã não pode mexer no que já fechou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('liv_products', function (Blueprint $table) {
            $table->decimal('desconto', 5, 2)->nullable()->after('preco_referencia');
        });
    }

    public function down(): void
    {
        Schema::table('liv_products', function (Blueprint $table) {
            $table->dropColumn('desconto');
        });
    }
};
