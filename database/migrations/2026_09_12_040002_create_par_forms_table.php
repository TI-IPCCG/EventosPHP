<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O formulário de inscrição — estrutura da FASE 2, criada já.
 *
 * Cadastro permanente e reusável entre eventos: "Formulário do Retiro" é
 * montado uma vez e reaproveitado todo ano. É essa a dor real do Google Forms
 * — remontar o formulário a cada edição.
 *
 * Espelha liv_categories: o "tipo" é quem possui a definição dos campos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('par_forms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('church_id')->index();
            $table->string('nome', 120);
            $table->string('descricao', 255)->nullable();   // texto no topo do formulário
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->unique(['church_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('par_forms');
    }
};
