<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Observações do item — campo universal, vale para qualquer categoria.
 *
 * Fica FORA do JSON `atributos` de propósito: atributo é o que a categoria
 * define, e observação existe sempre, para todo item. Deixá-la no JSON faria
 * cada categoria precisar declarar um campo "observações" igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('liv_products', function (Blueprint $table) {
            $table->text('observacoes')->nullable()->after('atributos');
        });
    }

    public function down(): void
    {
        Schema::table('liv_products', function (Blueprint $table) {
            $table->dropColumn('observacoes');
        });
    }
};
