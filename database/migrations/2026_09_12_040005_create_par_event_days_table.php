<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Os DIAS do evento — conceito que o núcleo não tem (`events` só sabe de
 * `inicio` e `fim`).
 *
 * Tabela própria, e não um intervalo derivado, por quatro razões — a terceira
 * decide sozinha:
 *
 * 1. `events` não recebe coluna de módulo, e nomear o dia é requisito.
 * 2. Dias NÃO CONSECUTIVOS são caso real (os dois sábados do mês).
 * 3. O check-in aponta para o dia por FK. Pelo critério do projeto — "se
 *    precisa ser referenciável por outra tabela ou ter contagem própria, é
 *    entidade, não JSON" — o dia é entidade. É o análogo direto de "variação
 *    divide estoque": O DIA DIVIDE A PRESENÇA, e cada dia esgota sozinho.
 * 4. Dia cancelado precisa parar de aceitar check-in sem apagar a presença já
 *    registrada — daí `ativo`.
 *
 * Derivar é o DEFAULT DE CRIAÇÃO, não o modelo: ao preparar o evento, semeia-se
 * uma linha por data de inicio..fim (ou uma só, se `fim` for NULL) e o
 * coordenador então apaga, acrescenta e nomeia. Ninguém digita dia à mão e o
 * modelo continua honesto.
 *
 * Sem coluna `ordem`: data se ordena sozinha.
 *
 * Duas sessões no mesmo dia (manhã/noite) não cabem hoje, de propósito — a
 * expansão é indolor e sem perda: ADD COLUMN periodo DEFAULT 'dia_inteiro' e
 * trocar a UNIQUE por (event_id, data, periodo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('par_event_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->date('data');
            $table->string('nome', 60)->nullable();     // "Sábado — manhã"; NULL usa a data
            $table->boolean('ativo')->default(true);    // 0 = dia cancelado
            $table->timestamp('created_at')->nullable();

            $table->unique(['event_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('par_event_days');
    }
};
