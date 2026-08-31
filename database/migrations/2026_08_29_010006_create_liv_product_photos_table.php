<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fotos do item, com uma CAPA.
 *
 * `caminho` é relativo ao disco `public` (storage/app/public/livraria/…),
 * servido pelo symlink do storage:link. `caminho_thumb` não é luxo: a mesa
 * opera em 4G e mandar a foto original em cada linha derruba a tela de venda.
 *
 * ⚠ `capa_unica` garante NO MÁXIMO UMA capa por item: vale product_id quando
 * capa = true e NULL quando não. Como NULL não colide em índice UNIQUE, o
 * banco impede duas capas sem precisar de trigger.
 *
 * ⚠ Ela é VIRTUAL, e não STORED como as outras colunas geradas do schema:
 * o InnoDB recusa ON DELETE CASCADE numa coluna que seja BASE de coluna gerada
 * STORED, e product_id é a base desta. Apagar o item precisa apagar as fotos,
 * então o cascade é que importa. VIRTUAL não tem a restrição.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liv_product_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('liv_products')->cascadeOnDelete();
            $table->string('caminho', 255);
            $table->string('caminho_thumb', 255)->nullable();
            $table->boolean('capa')->default(false);
            $table->unsignedTinyInteger('ordem')->default(0);
            $table->unsignedInteger('bytes')->nullable();
            $table->unsignedSmallInteger('largura')->nullable();
            $table->unsignedSmallInteger('altura')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unsignedBigInteger('capa_unica')->nullable()
                  ->virtualAs('IF(`capa` = 1, `product_id`, NULL)');
            $table->unique('capa_unica');
            $table->index(['product_id', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liv_product_photos');
    }
};
