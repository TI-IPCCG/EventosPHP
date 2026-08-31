<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fornecedores. `prefixo` gera o código do exemplar: ECC + 001 = ECC001.
 * `condicao_padrao` é a condição comercial (consignado x compra firme).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liv_suppliers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('church_id')->index();
            $table->string('nome', 150);
            $table->string('prefixo', 4);
            $table->enum('condicao_padrao', ['consignado', 'firme'])->default('consignado');
            $table->decimal('desconto_padrao', 5, 2)->nullable(); // NULL = varia por item
            $table->string('contato', 150)->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->unique(['church_id', 'prefixo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liv_suppliers');
    }
};
