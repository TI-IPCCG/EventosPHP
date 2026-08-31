<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O que a livraria acrescenta a um evento. Uma linha por evento.
 * Fica FORA de `events` de propósito: núcleo não recebe coluna de módulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liv_event_settings', function (Blueprint $table) {
            $table->foreignId('event_id')->primary()->constrained('events')->cascadeOnDelete();
            $table->enum('meta_tipo', ['zero_a_zero', 'valor'])->default('zero_a_zero');
            $table->decimal('meta_valor', 10, 2)->nullable();
            $table->string('observacao', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liv_event_settings');
    }
};
