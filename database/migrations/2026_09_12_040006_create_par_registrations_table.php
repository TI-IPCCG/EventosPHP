<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A INSCRIÇÃO NO EVENTO. O participante não é `users` — ele é esta linha.
 *
 * ── SNAPSHOT ──────────────────────────────────────────────────────────
 * nome, email, telefone e cpf são COPIADOS da pessoa no momento da inscrição,
 * e nunca recarregados de par_people. Mesma razão de
 * liv_shipment_items.custo_unitario: corrigir o telefone hoje não pode mexer na
 * lista de presença de um evento encerrado. O critério:
 *
 *   Snapshot o que pode MUDAR e foi usado para AGIR naquele evento;
 *   referencia o que não muda (data_nascimento) ou o que preciso alcançar HOJE.
 *
 * O snapshot ganha um segundo salário: é a CHAVE HISTÓRICA da deduplicação.
 * E-mail antigo continua encontrando a pessoa porque toda inscrição guarda o
 * e-mail que foi usado naquele dia — sem tabela de aliases nenhuma. Daí os
 * índices em `email` e `cpf`.
 *
 * ⚠ TODO ENVIO usa este `email`, não o de par_people. É o que deixa o casal com
 * um e-mail só funcionar, e o que responde "para onde mandamos?" meses depois.
 *
 * ── codigo × token ────────────────────────────────────────────────────
 * `codigo` é humano e SEQUENCIAL (INS0042), para ditar e digitar. `token` é
 * aleatório e é o que vai no QR. Se o QR levasse o código, qualquer um faria
 * check-in como outra pessoa digitando INS0043 — sequencial é ótimo para ler e
 * péssimo como segredo (o docblock de CodeGenerator já avisa).
 * UM token por inscrição, não por dia: quatro QRs por e-mail é hostil e
 * multiplica a chance de apresentar o errado.
 *
 * ⚠ `pessoa_ativa` é STORED, e pode ser: as bases são `status` (sem FK) e
 * `person_id` (FK RESTRICT). O InnoDB só recusa STORED sobre base com CASCADE.
 * `event_id` cascateia mas NÃO entra na expressão — se um dia entrar, a coluna
 * tem de virar VIRTUAL.
 * Efeito: no máximo UMA inscrição ativa por pessoa por evento. É o que torna
 * reimportar a planilha idempotente sem código esperto, e reimportar por engano
 * é o erro operacional mais provável de todos.
 *
 * `respostas` nasce vazia e é da fase 2 — mas a IMPORTAÇÃO já escreve nela, com
 * a MESMA convenção de chave (Str::slug('_')) que par_questions vai gerar. Uma
 * pergunta "Restrição alimentar" criada depois vira `restricao_alimentar` e
 * ENCONTRA o dado importado hoje. É essa a ponte que dispensa migração de dados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('par_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('par_people')->restrictOnDelete();
            $table->string('codigo', 20);       // INS0042 — humano, NÃO é credencial
            $table->char('token', 32);          // segredo do QR
            $table->enum('status', ['confirmada', 'lista_espera', 'cancelada'])->default('confirmada');
            $table->enum('origem', ['formulario', 'manual', 'importacao'])->default('formulario');

            // snapshot do que foi declarado NESTE evento
            $table->string('nome', 150);
            $table->string('email', 191)->nullable();
            $table->string('telefone', 20)->nullable();
            $table->char('cpf', 11)->nullable();

            $table->json('respostas')->nullable();              // fase 2 + importação
            $table->boolean('conflito_identidade')->default(false);  // e-mail e CPF discordam
            $table->boolean('email_valido')->default(true);     // marcado na importação
            $table->dateTime('inscrita_em');                    // aceita lançamento retroativo
            $table->dateTime('qr_enviado_em')->nullable();
            $table->unsignedTinyInteger('qr_envios')->default(0);
            $table->dateTime('qr_reservado_em')->nullable();    // reserva do lote, expira no filtro
            $table->string('qr_erro', 255)->nullable();
            $table->foreignId('registrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelada_em')->nullable();
            $table->foreignId('cancelada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('observacao', 255)->nullable();
            $table->timestamps();

            $table->unsignedBigInteger('pessoa_ativa')->nullable()
                  ->storedAs("IF(`status` <> 'cancelada', `person_id`, NULL)");

            $table->unique(['event_id', 'codigo']);
            $table->unique('token');
            $table->unique(['event_id', 'pessoa_ativa']);
            $table->index('email');                     // chave histórica da dedup
            $table->index('cpf');                       // idem
            $table->index(['event_id', 'nome']);        // busca na porta
            $table->index(['event_id', 'qr_enviado_em', 'qr_envios']);   // o lote de e-mail
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('par_registrations');
    }
};
