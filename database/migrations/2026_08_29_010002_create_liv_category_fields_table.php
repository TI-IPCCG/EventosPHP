<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quais campos cada categoria pede. É este cadastro que permite acrescentar
 * "ISBN" ou "Gramatura" sem desenvolvimento: a tela do item se monta a partir
 * daqui, e os valores caem no JSON liv_products.atributos.
 *
 * `chave` é a chave dentro do JSON (sem acento, sem espaço).
 * `mostrar_na_lista` marca o campo que aparece embaixo do nome na listagem —
 * autor no livro, modelo na camiseta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liv_category_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('liv_categories')->cascadeOnDelete();
            $table->string('chave', 40);
            $table->string('rotulo', 60);
            $table->enum('tipo', ['texto', 'texto_longo', 'inteiro', 'decimal', 'data', 'selecao', 'booleano'])
                  ->default('texto');
            $table->json('opcoes')->nullable();   // valores quando tipo = selecao
            $table->boolean('obrigatorio')->default(false);
            $table->boolean('mostrar_na_lista')->default(false);
            $table->unsignedTinyInteger('ordem')->default(0);
            $table->unique(['category_id', 'chave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liv_category_fields');
    }
};
