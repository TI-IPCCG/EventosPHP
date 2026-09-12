<?php

namespace App\Services\Participantes;

use App\Models\Event;
use App\Models\Participantes\Checkin;
use App\Models\Participantes\EventDay;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Os dias do evento.
 *
 * Derivar do intervalo é o DEFAULT DE CRIAÇÃO, não o modelo: semeia-se uma
 * linha por data de inicio..fim e o coordenador então apaga, acrescenta e
 * nomeia. Ninguém digita dia à mão, e dias fora do intervalo (retirada de kit
 * na véspera) continuam possíveis.
 */
class DayService
{
    /**
     * Cria os dias que faltam a partir de inicio..fim. Idempotente: rodar de
     * novo não duplica nem desfaz ajuste manual.
     *
     * @return int quantos dias foram criados agora
     */
    public function sincronizar(Event $evento): int
    {
        $inicio = Carbon::parse($evento->inicio)->startOfDay();
        $fim    = $evento->fim ? Carbon::parse($evento->fim)->startOfDay() : $inicio->copy();

        if ($fim->lt($inicio)) {
            $fim = $inicio->copy();
        }

        // Rede contra data digitada errada: um evento com "fim" em 2030 criaria
        // milhares de linhas antes de alguém perceber.
        if ($inicio->diffInDays($fim) > 60) {
            throw new RuntimeException(
                'O intervalo do evento passa de 60 dias. Confira as datas antes de gerar os dias.',
            );
        }

        $criados = 0;

        for ($data = $inicio->copy(); $data->lte($fim); $data->addDay()) {
            $novo = EventDay::firstOrCreate(
                ['event_id' => $evento->id, 'data' => $data->toDateString()],
                ['ativo' => true, 'created_at' => now()],
            );

            $criados += $novo->wasRecentlyCreated ? 1 : 0;
        }

        return $criados;
    }

    /**
     * Garante que o evento tem pelo menos um dia.
     *
     * Chamado pela tela de check-in: a portaria não pode travar porque ninguém
     * lembrou de cadastrar os dias antes.
     */
    public function garantir(Event $evento): void
    {
        if (! EventDay::where('event_id', $evento->id)->exists()) {
            $this->sincronizar($evento);
        }
    }

    /**
     * Apaga um dia — só enquanto ele não tem presença registrada.
     *
     * A FK é CASCADE no evento e RESTRICT aqui de propósito: apagar o EVENTO
     * leva tudo junto, mas apagar um DIA que já tem gente marcada seria apagar
     * história. Para suspender um dia existe `ativo`.
     */
    public function apagar(EventDay $dia): void
    {
        $presencas = Checkin::where('event_day_id', $dia->id)->whereNull('cancelado_em')->count();

        if ($presencas > 0) {
            throw new RuntimeException(
                "Este dia já tem {$presencas} ".($presencas == 1 ? 'presença registrada' : 'presenças registradas')
                .'. Desative o dia em vez de apagar — o histórico não pode sumir.',
            );
        }

        $dia->delete();
    }
}
