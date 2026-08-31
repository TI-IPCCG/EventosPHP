<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remessa de um fornecedor para um evento. Sem UNIQUE(event_id, supplier_id):
 * segunda entrega no meio do evento é caso real, e cada uma tem seu frete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liv_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('liv_suppliers')->restrictOnDelete();
            $table->enum('condicao', ['consignado', 'firme'])->default('consignado');
            $table->string('observacao', 255)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liv_shipments');
    }
};
