<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As perguntas do formulário — estrutura da FASE 2, criada já.
 *
 * Espelha liv_category_fields, com quatro diferenças que existem porque aqui a
 * resposta é DADO HISTÓRICO DE UMA PESSOA, e não descrição de um item:
 *
 * 1. `escopo` decide ONDE o valor mora. `inscricao` (qual oficina) fica só no
 *    snapshot da inscrição; `pessoa` (restrição alimentar) vai também para
 *    par_people.perfil, e é isso que pré-preenche o próximo evento.
 *      → O perfil é o valor de HOJE. A resposta da inscrição é o valor DAQUELE
 *        DIA. A lista da cozinha de 2026 tem de continuar dizendo "vegetariana"
 *        depois que ela voltar a comer carne em 2027.
 *
 * 2. `arquivada_em` em vez de DELETE. Na livraria, apagar o campo deixa o valor
 *    órfão no JSON — guardado, mas INVISÍVEL, porque toda leitura é dirigida
 *    pela definição. Para catálogo é inofensivo; para a alergia de uma pessoa é
 *    perda de informação.
 *
 * 3. `chave` é IMUTÁVEL depois de criada; `rotulo` é editável. É o que permite
 *    corrigir o texto da pergunta sem nunca mover a chave do JSON — a lacuna
 *    que o padrão da livraria tem (lá não dá para editar campo já criado).
 *
 * 4. `ordem` é SMALLINT com passo de 10: reordenar por arrasto insere entre
 *    dois sem reescrever a lista. TINYINT esgotaria em 25 perguntas.
 *
 * ⚠ O ENUM mantém os 7 tipos de liv_category_fields NA MESMA ORDEM (as regras
 * de validação portam verbatim) e acrescenta 4 no FIM. Tipo novo entra sempre
 * no fim: no meio, o ALTER reescreve a tabela inteira.
 *
 * ⚠ `unica` é validado no Service, NÃO no banco: unicidade sobre chave JSON
 * arbitrária não cabe num índice. Quando tiver de ser inquebrável, o caminho é
 * promover a resposta a coluna gerada + UNIQUE (Docs/arquitetura.md §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('par_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained('par_forms')->cascadeOnDelete();
            $table->string('chave', 40);        // chave no JSON — IMUTÁVEL
            $table->string('rotulo', 120);      // a pergunta como a pessoa lê — editável
            $table->string('ajuda', 255)->nullable();
            $table->enum('tipo', [
                'texto', 'texto_longo', 'inteiro', 'decimal', 'data', 'selecao', 'booleano',
                'multipla_escolha', 'email', 'telefone', 'cpf',
            ])->default('texto');
            $table->json('opcoes')->nullable();              // selecao | multipla_escolha
            $table->boolean('obrigatorio')->default(false);
            $table->enum('escopo', ['inscricao', 'pessoa'])->default('inscricao');
            $table->boolean('mostrar_na_lista')->default(false);
            $table->boolean('unica')->default(false);        // validado no Service
            $table->smallInteger('ordem', false, true)->default(0);
            $table->dateTime('arquivada_em')->nullable();    // em vez de DELETE
            $table->timestamps();

            $table->unique(['form_id', 'chave']);
            $table->index(['form_id', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('par_questions');
    }
};
