<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NÚCLEO DO APP, compartilhado por todos os módulos. Um evento é o simpósio,
 * o retiro, o congresso; a livraria é UM módulo que acontece dentro dele.
 *
 * Nada específico de módulo entra aqui. A meta financeira da livraria, por
 * exemplo, vive em liv_event_settings — se cada módulo pudesse acrescentar a
 * sua coluna, `events` viraria depósito no segundo módulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('church_id')->index();
            $table->string('nome', 150);
            $table->string('local', 150)->nullable();
            $table->date('inicio')->index();
            $table->date('fim')->nullable();     // NULL = evento de um dia só
            $table->enum('status', ['planejamento', 'em_andamento', 'encerrado'])
                  ->default('planejamento');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
