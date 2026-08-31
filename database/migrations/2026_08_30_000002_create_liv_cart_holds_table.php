<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RESERVA DE CARRINHO — exemplar que está no carrinho de alguém agora.
 *
 * Por que uma tabela e não o status 'reservado' do exemplar:
 *  · o status é o estado FÍSICO do exemplar (vendido, baixado, devolvido);
 *    estar no carrinho de alguém é estado de SESSÃO, e some sozinho
 *  · mexer no status exigiria mudar o gatilho e o StockGuard, que hoje só
 *    aceitam 'disponivel' — a venda passaria a ter dois caminhos
 *  · com `expira_em`, carrinho abandonado se cura sem cron: basta filtrar
 *    pelas reservas ainda válidas
 *
 * A reserva NÃO bloqueia ninguém. Ela só deixa visível que há outra venda em
 * andamento com aquele item, para os voluntários se acertarem na mesa. Quem
 * conclui primeiro continua levando — a trava de verdade é a de sempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liv_cart_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('copy_id')->constrained('liv_copies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('criado_em');
            $table->timestamp('expira_em')->index();

            // Um exemplar não fica em dois carrinhos ao mesmo tempo.
            $table->unique('copy_id');
            $table->index(['event_id', 'expira_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liv_cart_holds');
    }
};
