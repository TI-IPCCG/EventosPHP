<?php

namespace App\Services\Participantes;

use App\Models\Participantes\Checkin;
use App\Models\Participantes\EventDay;
use App\Models\Participantes\Registration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registrar entrada — e garantir que ela aconteça UMA vez por dia.
 *
 * Duas camadas somadas, como a RN09 da livraria:
 *
 *   1. UNIQUE em par_checkins.registration_ativa → o banco recusa o segundo
 *      check-in ativo da mesma inscrição no mesmo dia.
 *   2. lockForUpdate aqui → resolve a CORRIDA que o índice sozinho não resolve:
 *      dois leitores de QR na porta podem ler "ainda não entrou" antes de
 *      qualquer um gravar.
 *
 * A segunda leitura do mesmo crachá é rotina na portaria, não erro — e por isso
 * a resposta não é um estouro, é informação: "já entrou às 19h12, por Maria".
 * É assim que se percebe crachá compartilhado.
 */
class CheckinService
{
    /**
     * @throws RuntimeException quando já existe entrada ativa, com a hora e
     *                          quem registrou — a mensagem é para a portaria ler
     */
    public function registrar(
        Registration $inscricao,
        EventDay $dia,
        string $canal = 'qr',
        ?int $operadorId = null,
    ): Checkin {
        if ($inscricao->cancelada) {
            throw new RuntimeException(
                'A inscrição de '.$inscricao->nome.' está cancelada. Confira na lista antes de liberar a entrada.',
            );
        }

        if (! $dia->ativo) {
            throw new RuntimeException('Este dia do evento está desativado e não aceita entrada.');
        }

        if ($dia->event_id !== $inscricao->event_id) {
            throw new RuntimeException('Este dia não pertence ao evento desta inscrição.');
        }

        // O horário vem do evento, que resolve pelo fuso da CONGREGAÇÃO.
        // now() erraria 3 horas em qualquer chamada fora do navegador.
        $agora = $inscricao->event->agora();

        return DB::transaction(function () use ($inscricao, $dia, $canal, $operadorId, $agora) {
            // Trava a inscrição: é o que serializa dois leitores simultâneos.
            Registration::whereKey($inscricao->id)->lockForUpdate()->first();

            $existente = Checkin::where('registration_id', $inscricao->id)
                ->where('event_day_id', $dia->id)
                ->whereNull('cancelado_em')
                ->with('operador')
                ->first();

            if ($existente) {
                throw new RuntimeException($this->jaEntrou($existente));
            }

            try {
                return Checkin::create([
                    'registration_id' => $inscricao->id,
                    'event_day_id'    => $dia->id,
                    'canal'           => $canal,
                    'registrado_em'   => $agora,
                    'registrado_por'  => $operadorId,
                    'created_at'      => $agora,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // O índice pegou o que o lock não pegou (transações em conexões
                // diferentes). A mensagem continua sendo a útil, não a do banco.
                $outro = Checkin::where('registration_id', $inscricao->id)
                    ->where('event_day_id', $dia->id)
                    ->whereNull('cancelado_em')
                    ->with('operador')
                    ->first();

                throw new RuntimeException(
                    $outro ? $this->jaEntrou($outro) : 'Esta pessoa já entrou hoje.',
                );
            }
        });
    }

    /**
     * Desfaz uma entrada — sem apagar.
     *
     * `cancelado_em` preenchido tira a linha do índice (a coluna gerada vira
     * NULL), então a pessoa pode ser marcada de novo, e o engano fica no
     * histórico em vez de sumir.
     */
    public function cancelar(Checkin $checkin, ?int $operadorId = null): void
    {
        if ($checkin->cancelado_em) {
            return;
        }

        $checkin->update([
            'cancelado_em'  => $checkin->registration->event->agora(),
            'cancelado_por' => $operadorId,
        ]);
    }

    private function jaEntrou(Checkin $checkin): string
    {
        $hora = $checkin->registrado_em?->format('H:i');
        $quem = $checkin->operador?->name;

        return 'Já entrou'
            .($hora ? " às {$hora}" : '')
            .($quem ? ", registrado por {$quem}" : '')
            .'.';
    }
}
