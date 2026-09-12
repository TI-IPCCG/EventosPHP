<?php

namespace App\Services\Participantes;

use App\Models\Participantes\Person;
use App\Models\Participantes\Registration;

/**
 * Encontra ou cria a pessoa — o ÚNICO caminho de escrita em par_people.
 *
 * É a peça que cumpre o requisito de "reencontrar pelo mesmo e-mail ou CPF": a
 * pessoa que já veio num evento não é cadastrada de novo, e os dados dela
 * voltam preenchidos.
 *
 * ── A ORDEM DAS CHAVES, E POR QUÊ ─────────────────────────────────────
 * 1. CPF é chave FORTE (documento único por lei) → casa e pronto.
 * 2. E-mail casa, mas só vincula sozinho se o primeiro nome bater. E-mail é
 *    compartilhado em família com frequência; sem essa conferência, o filho
 *    entraria na conta da mãe.
 * 3. CHAVE HISTÓRICA: procura o e-mail nas INSCRIÇÕES antigas. É isso que faz o
 *    e-mail antigo continuar encontrando quem já trocou de endereço — e sai de
 *    graça, porque o snapshot da inscrição já guarda todo e-mail já usado.
 * 4. Não achou nada → cria.
 *
 * ── O QUE ELE NUNCA FAZ ───────────────────────────────────────────────
 * · Casar por NOME: homônimo é regra, não exceção. "Maria Silva" na igreja são
 *   várias, e fundir duas seria irreversível na prática.
 * · Casar por TELEFONE: a família inteira usa o mesmo.
 * · FUNDIR automaticamente quando as chaves discordam. Se o CPF aponta para a
 *   pessoa A e o e-mail para a B, vincula pelo CPF e marca `conflito` — quem
 *   decide é gente, com as duas fichas à vista.
 * · SOBRESCREVER dado preenchido com vazio. O upsert só preenche buraco, ou
 *   troca por valor novo não-vazio: uma inscrição sem telefone não pode apagar
 *   o telefone que já tínhamos.
 */
class PersonResolver
{
    public function resolver(array $dados, int $churchId): ResultadoDaPessoa
    {
        $cpf      = $this->soDigitos($dados['cpf'] ?? null);
        $email    = $this->email($dados['email'] ?? null);
        $nome     = trim((string) ($dados['nome'] ?? ''));

        $porCpf   = $cpf ? $this->buscarPorCpf($cpf, $churchId) : null;
        $porEmail = $email ? $this->buscarPorEmail($email, $churchId) : null;

        // Chaves discordam: a decisão é humana, mas a inscrição não pode parar.
        if ($porCpf && $porEmail && $porCpf->id !== $porEmail->id) {
            $this->completar($porCpf, $dados);

            return new ResultadoDaPessoa($porCpf, 'cpf', conflito: true);
        }

        if ($porCpf) {
            $this->completar($porCpf, $dados);

            return new ResultadoDaPessoa($porCpf, 'cpf');
        }

        if ($porEmail) {
            // Mesmo e-mail, primeiro nome diferente: provavelmente outra pessoa
            // da mesma casa. Cria separada, sem tomar o e-mail de ninguém.
            if ($this->primeiroNome($nome) !== $this->primeiroNome($porEmail->nome)) {
                return new ResultadoDaPessoa($this->criar($dados, $churchId, semEmail: true), 'nova');
            }

            $this->completar($porEmail, $dados);

            return new ResultadoDaPessoa($porEmail, 'email');
        }

        if ($email && $historica = $this->buscarNoHistorico($email, $churchId)) {
            $this->completar($historica, $dados);

            return new ResultadoDaPessoa($historica, 'historico');
        }

        return new ResultadoDaPessoa($this->criar($dados, $churchId), 'nova');
    }

    private function buscarPorCpf(string $cpf, int $churchId): ?Person
    {
        $achada = Person::withoutGlobalScopes()
            ->where('church_id', $churchId)
            ->where('cpf', $cpf)
            ->first();

        return $achada ? $this->sobrevivente($achada) : null;
    }

    private function buscarPorEmail(string $email, int $churchId): ?Person
    {
        $achada = Person::withoutGlobalScopes()
            ->where('church_id', $churchId)
            ->where('email', $email)
            ->first();

        return $achada ? $this->sobrevivente($achada) : null;
    }

    /**
     * Segue a lápide da fusão até quem sobreviveu.
     *
     * A chave (CPF ou e-mail) pode ter ficado na pessoa fundida. Ignorá-la
     * levaria o resolver a tentar criar outra pessoa com a mesma chave — e
     * tomar uma violação de UNIQUE na cara do usuário. Seguir a lápide é o
     * comportamento certo: quem foi fundido virou aquela outra pessoa.
     *
     * O limite de saltos é rede contra ciclo: o merger achata as cadeias, mas
     * um dado torto não pode travar a inscrição de ninguém.
     */
    private function sobrevivente(Person $pessoa, int $saltos = 0): Person
    {
        if (! $pessoa->fundida_em_id || $saltos >= 5) {
            return $pessoa;
        }

        $proxima = Person::withoutGlobalScopes()->find($pessoa->fundida_em_id);

        return $proxima ? $this->sobrevivente($proxima, $saltos + 1) : $pessoa;
    }

    /**
     * O e-mail nas inscrições antigas. O snapshot guarda todo endereço já
     * usado, então ele funciona como lista de e-mails anteriores da pessoa —
     * sem que exista uma tabela para isso.
     */
    private function buscarNoHistorico(string $email, int $churchId): ?Person
    {
        $personId = Registration::query()
            ->where('email', $email)
            ->orderByDesc('inscrita_em')
            ->value('person_id');

        if (! $personId) {
            return null;
        }

        return Person::withoutGlobalScopes()
            ->where('church_id', $churchId)
            ->whereKey($personId)
            ->whereNull('fundida_em_id')
            ->first();
    }

    private function criar(array $dados, int $churchId, bool $semEmail = false): Person
    {
        return Person::create([
            'church_id'       => $churchId,          // explícito: pode não haver sessão
            'nome'            => $dados['nome'] ?? '',
            'email'           => $semEmail ? null : $this->email($dados['email'] ?? null),
            'cpf'             => $this->soDigitos($dados['cpf'] ?? null),
            'telefone'        => $dados['telefone'] ?? null,
            'data_nascimento' => $dados['data_nascimento'] ?? null,
        ]);
    }

    /**
     * Preenche só o que está vazio. Nunca apaga o que já sabíamos, e nunca
     * TOMA uma chave que já é de outra pessoa.
     *
     * O segundo cuidado é o que impede o caso de conflito de estourar: se o
     * e-mail digitado pertence à pessoa B e estamos vinculando à pessoa A (pelo
     * CPF), gravá-lo em A violaria a UNIQUE — e, pior, roubaria a identidade de
     * B se não houvesse índice. O e-mail fica só no snapshot da inscrição, que
     * é onde ele descreve um fato: foi este o endereço declarado naquele dia.
     */
    private function completar(Person $pessoa, array $dados): void
    {
        $novos = [];

        foreach (['email', 'cpf', 'telefone', 'data_nascimento'] as $campo) {
            $valor = $dados[$campo] ?? null;

            if (blank($valor) || filled($pessoa->{$campo})) {
                continue;
            }

            if (in_array($campo, ['email', 'cpf'], true)
                && $this->chaveEhDeOutra($campo, $valor, $pessoa)) {
                continue;
            }

            $novos[$campo] = $valor;
        }

        if ($novos) {
            $pessoa->fill($novos)->save();
        }
    }

    private function chaveEhDeOutra(string $campo, string $valor, Person $pessoa): bool
    {
        $normalizado = $campo === 'email' ? $this->email($valor) : $this->soDigitos($valor);

        return Person::withoutGlobalScopes()
            ->where('church_id', $pessoa->church_id)
            ->where($campo, $normalizado)
            ->whereKeyNot($pessoa->id)
            ->exists();
    }

    private function soDigitos(?string $valor): ?string
    {
        return preg_replace('/\D/', '', (string) $valor) ?: null;
    }

    private function email(?string $valor): ?string
    {
        return mb_strtolower(trim((string) $valor)) ?: null;
    }

    private function primeiroNome(string $nome): string
    {
        return \Illuminate\Support\Str::of($nome)->trim()->explode(' ')->first()
            ? \Illuminate\Support\Str::slug(\Illuminate\Support\Str::of($nome)->trim()->explode(' ')->first())
            : '';
    }
}
