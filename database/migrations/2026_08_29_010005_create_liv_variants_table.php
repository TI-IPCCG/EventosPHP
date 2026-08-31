<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P, M, G / Preta, Branca. UMA LINHA POR SALDO INDEPENDENTE.
 *
 * Atributo descreve; variação divide estoque. Se tamanho fosse um campo de
 * texto como "marca", não daria para responder "tem no M?" na mesa nem
 * "o M esgotou e o GG voltou inteiro?" no fechamento.
 *
 * Só existe para categorias com usa_variacao = true. Livro não tem nenhuma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liv_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('liv_products')->cascadeOnDelete();
            $table->string('nome', 30);                       // P, M, G, GG, Preta
            $table->unsignedTinyInteger('ordem')->default(0); // P antes de M antes de G
            $table->boolean('ativo')->default(true);
            $table->unique(['product_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liv_variants');
    }
};
