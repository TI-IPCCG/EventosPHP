<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MÓDULO LIVRARIA — categorias de item: Livro, Camiseta, Caneca…
 * É o que faz o catálogo NÃO ser só de livros.
 *
 * usa_variacao = true marca a categoria cujos itens têm saldo por tamanho/cor.
 * Livro não usa: um livro é um saldo só.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liv_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('church_id')->index();
            $table->string('nome', 60);
            $table->string('slug', 60);
            $table->boolean('usa_variacao')->default(false);
            $table->string('rotulo_variacao', 30)->nullable(); // "Tamanho", "Cor"
            $table->unsignedTinyInteger('ordem')->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->unique(['church_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liv_categories');
    }
};
