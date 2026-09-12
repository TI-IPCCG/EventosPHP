<?php

namespace App\Services\Participantes;

use App\Models\Event;
use App\Models\Participantes\EventSetting;
use App\Models\Participantes\Registration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Inscrever alguém num evento: resolve a identidade, gera código e token, e
 * grava o snapshot.
 *
 * ⚠ O snapshot (nome, email, telefone, cpf) é o que foi declarado NESTE
 * evento, e nunca é recarregado da pessoa depois. Corrigir um telefone hoje não
 * pode mexer na lista de presença de um evento encerrado — é a mesma decisão
 * de liv_shipment_items.custo_unitario.
 */
class RegistrationService
{
    public function __construct(private PersonResolver $resolver) {}

    /**
     * @param  array  $dados  nome, email, telefone, cpf, data_nascimento, respostas
     *
     * @throws RuntimeException se a pessoa já tem inscrição ativa no evento
     */
    public function inscrever(
        Event $evento,
        array $dados,
        string $origem = 'manual',
        ?int $operadorId = null,
    ): Registration {
        return DB::transaction(function () use ($evento, $dados, $origem, $operadorId) {
            $resultado = $this->resolver->resolver($dados, $evento->church_id);

            $jaInscrita = Registration::where('event_id', $evento->id)
                ->where('person_id', $resultado->pessoa->id)
                ->ativas()
                ->exists();

            if ($jaInscrita) {
                throw new RuntimeException(
                    $resultado->pessoa->nome.' já está inscrita neste evento.',
                );
            }

            return $this->gravar($evento, $resultado, $dados, $origem, $operadorId);
        });
    }

    /**
     * Tenta gravar, e refaz o código se outro processo tomou o número.
     *
     * O sequencial é lido como MAX+1 e não é seguro contra corrida — dois
     * walk-ins no mesmo instante calculam o mesmo. Quem protege é o UNIQUE, e é
     * mais barato tentar de novo do que serializar toda inscrição.
     */
    private function gravar($evento, $resultado, array $dados, string $origem, ?int $operadorId): Registration
    {
        foreach (range(1, 5) as $tentativa) {
            try {
                return Registration::create([
                    'event_id'            => $evento->id,
                    'person_id'           => $resultado->pessoa->id,
                    'codigo'              => $this->proximoCodigo($evento),
                    'token'               => Str::random(32),
                    'status'              => Registration::CONFIRMADA,
                    'origem'              => $origem,
                    'nome'                => $dados['nome'] ?? $resultado->pessoa->nome,
                    'email'               => $dados['email'] ?? null,
                    'telefone'            => $dados['telefone'] ?? null,
                    'cpf'                 => $dados['cpf'] ?? null,
                    'respostas'           => $dados['respostas'] ?? null,
                    'conflito_identidade' => $resultado->conflito,
                    'email_valido'        => $this->emailValido($dados['email'] ?? null),
                    'inscrita_em'         => $dados['inscrita_em'] ?? $evento->agora(),
                    'registrada_por'      => $operadorId,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                if ($tentativa === 5) {
                    throw new RuntimeException(
                        'Não foi possível gerar o código da inscrição. Tente de novo.',
                    );
                }
            }
        }

        throw new RuntimeException('Não foi possível gravar a inscrição.');
    }

    /**
     * O próximo número da sequência DESTE evento.
     *
     * Lê o MAIOR sufixo já usado, não conta linhas: inscrição apagada abriria
     * buraco na contagem e devolveria um código que já existe. É o mesmo
     * raciocínio de Livraria\CodeGenerator.
     */
    public function proximoCodigo(Event $evento): string
    {
        $prefixo = EventSetting::where('event_id', $evento->id)->value('prefixo_codigo') ?: 'INS';
        $offset  = mb_strlen($prefixo) + 1;

        $maior = (int) Registration::where('event_id', $evento->id)
            ->where('codigo', 'like', $prefixo.'%')
            ->selectRaw('MAX(CAST(SUBSTRING(codigo, ?) AS UNSIGNED)) as m', [$offset])
            ->value('m');

        return $prefixo.str_pad((string) ($maior + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * E-mail inválido NÃO descarta a pessoa — ela continua inscrita e entra na
     * portaria pelo nome. O que a marca faz é permitir saber, ANTES do evento,
     * quem não vai receber a credencial e precisa ser avisado por outro canal.
     */
    private function emailValido(?string $email): bool
    {
        return filled($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function cancelar(Registration $inscricao, ?int $operadorId = null): void
    {
        $inscricao->update([
            'status'        => Registration::CANCELADA,
            'cancelada_em'  => $inscricao->event->agora(),
            'cancelada_por' => $operadorId,
        ]);
    }
}
