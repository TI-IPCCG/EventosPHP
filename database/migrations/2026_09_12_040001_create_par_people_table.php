<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A pessoa ENTRE eventos — o "master_customer" do escopo.
 *
 * Cadastro PERMANENTE da congregação, como Supplier e Product: é o que faz os
 * dados voltarem preenchidos quando a mesma pessoa se inscreve no ano seguinte.
 *
 * ⚠ O participante NÃO é `users`: não faz login, não tem senha nem perfil de
 * acesso. Ele é inscrito num evento (par_registrations).
 *
 * ⚠ `email` e `cpf` são CHAVES DE IDENTIDADE, não canais de contato — quem
 * recebe o QR é o e-mail gravado na INSCRIÇÃO. A separação é o que deixa um
 * casal usar um e-mail só: o segundo cônjuge fica sem e-mail aqui e ainda
 * recebe a credencial dele.
 *
 * ⚠ NULL EM UNIQUE, AQUI DE PROPÓSITO. Em liv_shipment_items o NULL era o
 * problema, resolvido com a coluna gerada `variant_key = IFNULL(variant_id,0)`.
 * Aqui é exatamente a semântica desejada: duas pessoas sem CPF NÃO são a mesma
 * pessoa — "não informou" é ausência de chave, não um valor. Usar IFNULL aqui
 * impediria a segunda pessoa sem CPF de existir.
 *
 * O índice só vale se o dado for normalizado: `cpf` guarda 11 dígitos e nada
 * mais, `email` vai em minúsculas. Quem normaliza é o model (mutators), e o
 * CPF ainda passa por dígito verificador — CPF errado não gera erro de
 * validação, gera PESSOA errada, e pode colidir com o CPF real de outra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('par_people', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('church_id')->index();   // lógico → ModernApps.churches
            $table->string('nome', 150);
            $table->string('email', 191)->nullable();           // CHAVE; NULL = sem chave
            $table->char('cpf', 11)->nullable();                // CHAVE forte; só dígitos
            $table->string('telefone', 20)->nullable();
            $table->date('data_nascimento')->nullable();        // não tem snapshot: não muda
            $table->json('perfil')->nullable();                 // fase 2: respostas de escopo=pessoa
            $table->string('observacao', 255)->nullable();
            $table->boolean('ativo')->default(true);
            $table->unsignedBigInteger('fundida_em_id')->nullable();   // lápide de fusão
            $table->dateTime('fundida_em')->nullable();
            $table->timestamps();

            $table->unique(['church_id', 'email']);
            $table->unique(['church_id', 'cpf']);
            $table->index(['church_id', 'nome']);               // busca na portaria

            $table->foreign('fundida_em_id')->references('id')->on('par_people')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('par_people');
    }
};
