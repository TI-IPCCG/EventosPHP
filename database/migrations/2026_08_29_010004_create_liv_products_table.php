<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo permanente. `nome` e não "titulo", `preco_referencia` e não
 * "preco_capa": camiseta não tem título nem capa.
 *
 * `atributos` guarda os campos definidos pela categoria. Ex.:
 *   livro    → {"autor":"John Piper","editora":"Fiel","num_paginas":224}
 *   camiseta → {"modelo":"Básica","marca":"Malwee"}
 *
 * Para filtrar/ordenar por um atributo com índice, promova-o a coluna gerada
 * quando a necessidade aparecer (ver Docs/arquitetura.md §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liv_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('church_id')->index();
            $table->foreignId('category_id')->constrained('liv_categories')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('liv_suppliers')->restrictOnDelete();
            $table->string('nome', 200)->index();       // busca na mesa
            $table->decimal('preco_referencia', 10, 2)->nullable();
            $table->json('atributos')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liv_products');
    }
};
