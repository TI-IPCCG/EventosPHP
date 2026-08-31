<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formas de pagamento e taxas DO EVENTO. Por evento e não global: a
 * maquininha muda de contrato entre um ano e outro, e o resultado do evento
 * antigo precisa continuar batendo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liv_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('nome', 50);   // PIX, Débito, Crédito, Crédito parcelado
            $table->decimal('taxa_percentual', 5, 2)->default(0);  // 0 = isento (PIX)
            $table->unsignedTinyInteger('ordem')->default(0);
            $table->boolean('ativo')->default(true);
            $table->unique(['event_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liv_payment_methods');
    }
};
