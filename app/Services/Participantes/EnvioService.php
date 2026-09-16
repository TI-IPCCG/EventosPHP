<?php

namespace App\Services\Participantes;

use App\Mail\CredencialMail;
use App\Models\Event;
use App\Models\Participantes\EventSetting;
use App\Models\Participantes\Registration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Enviar as credenciais — em lote, sem fila e sem cron.
 *
 * ── POR QUE NÃO TEM FILA ──────────────────────────────────────────────
 * Produção é cPanel: QUEUE_CONNECTION=sync, sem tabela `jobs`, sem worker e
 * sem cron (DEPLOY.md §7). Um laço de 60 e-mails a ~1-2s cada estoura o
 * timeout do PHP-FPM no meio, e o operador não sabe quem já recebeu.
 *
 * ── COMO FUNCIONA NO LUGAR ────────────────────────────────────────────
 * O estado mora na PRÓPRIA LINHA (qr_enviado_em, qr_envios, qr_reservado_em,
 * qr_erro) — a fila é a tabela ordenada, e não precisa de mais nada.
 *
 * A tela chama isto repetidamente com wire:poll. Cada chamada é uma REQUISIÇÃO
 * HTTP NOVA, com timeout novo: é isso que torna o lote viável sem worker.
 *
 * A reserva expira NO FILTRO, não por rotina de limpeza — mesmo truque de
 * liv_cart_holds. Um lote interrompido no meio (navegador fechado, queda de
 * luz, deploy) se cura sozinho em 5 minutos, e ninguém precisa saber disso.
 *
 * Retomável por construção: fechar tudo e clicar de novo continua de onde
 * parou, porque o que já foi enviado tem qr_enviado_em preenchido.
 */
class EnvioService
{
    /** Quanto tempo uma reserva vale antes de outro processo poder pegá-la. */
    private const RESERVA_MINUTOS = 5;

    /** Teto de tentativas: e-mail que falha sempre não pode travar a fila. */
    private const MAX_TENTATIVAS = 3;

    /** Segundos por requisição. Abaixo do timeout típico de PHP-FPM, com folga. */
    private const ORCAMENTO = 20;

    public function __construct(private QrService $qr) {}

    /** Quantos ainda esperam envio. */
    public function pendentes(Event $evento): int
    {
        return $this->fila($evento)->count();
    }

    public function enviados(Event $evento): int
    {
        return Registration::where('event_id', $evento->id)
            ->whereNotNull('qr_enviado_em')->count();
    }

    public function falharam(Event $evento): int
    {
        return Registration::where('event_id', $evento->id)
            ->whereNull('qr_enviado_em')
            ->where('qr_envios', '>=', self::MAX_TENTATIVAS)
            ->count();
    }

    /**
     * Envia o que couber em ORCAMENTO segundos.
     *
     * @return array{enviados:int, falhas:int, restam:int}
     */
    public function enviarLote(Event $evento): array
    {
        $limite = microtime(true) + self::ORCAMENTO;
        $enviados = 0;
        $falhas = 0;

        while (microtime(true) < $limite) {
            $inscricao = $this->reivindicar($evento);

            if (! $inscricao) {
                break;      // acabou a fila
            }

            $this->enviarUma($inscricao, $evento) ? $enviados++ : $falhas++;
        }

        return ['enviados' => $enviados, 'falhas' => $falhas, 'restam' => $this->pendentes($evento)];
    }

    /**
     * Toma UMA inscrição para si, numa transação curta.
     *
     * O incremento de qr_envios acontece ANTES do envio, de propósito: se o
     * processo morrer no meio do SMTP, a tentativa já está contada e o e-mail
     * não fica em laço infinito de reenvio.
     */
    private function reivindicar(Event $evento): ?Registration
    {
        return DB::transaction(function () use ($evento) {
            $inscricao = $this->fila($evento)->lockForUpdate()->first();

            if (! $inscricao) {
                return null;
            }

            $inscricao->update([
                'qr_envios'       => $inscricao->qr_envios + 1,
                'qr_reservado_em' => $evento->agora(),
            ]);

            return $inscricao;
        });
    }

    /** O envio em si acontece FORA da transação: SMTP lento seguraria o lock. */
    private function enviarUma(Registration $inscricao, Event $evento): bool
    {
        try {
            $texto = EventSetting::where('event_id', $evento->id)->value('texto_confirmacao');

            Mail::to($inscricao->email)->send(new CredencialMail(
                $inscricao,
                route('participantes.credencial', ['token' => $inscricao->token]),
                $texto,
            ));

            $inscricao->update([
                'qr_enviado_em'   => $evento->agora(),
                'qr_reservado_em' => null,
                'qr_erro'         => null,
            ]);

            return true;
        } catch (\Throwable $e) {
            // Falha de SMTP não derruba o lote: registra na linha e segue.
            //
            // ⚠ A reserva é MANTIDA de propósito. Limpá-la devolveria a pessoa
            // à fila no mesmo instante, e com o servidor de e-mail fora do ar o
            // lote queimaria as três tentativas dela em três segundos — antes
            // de o SMTP ter qualquer chance de voltar. Mantendo, a próxima
            // tentativa só acontece quando a reserva expira, daqui a 5 minutos.
            $inscricao->update([
                'qr_erro' => mb_substr($e->getMessage(), 0, 250),
            ]);

            Log::error('Falha ao enviar credencial', [
                'registration' => $inscricao->id,
                'erro'         => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * A fila: quem tem e-mail válido, ainda não recebeu, não estourou as
     * tentativas, e não está reservado por outro processo AGORA.
     */
    private function fila(Event $evento)
    {
        $expirou = $evento->agora()->subMinutes(self::RESERVA_MINUTOS);

        return Registration::where('event_id', $evento->id)
            ->ativas()
            ->where('email_valido', true)
            ->whereNotNull('email')
            ->whereNull('qr_enviado_em')
            ->where('qr_envios', '<', self::MAX_TENTATIVAS)
            ->where(fn ($q) => $q
                ->whereNull('qr_reservado_em')
                ->orWhere('qr_reservado_em', '<', $expirou))
            ->orderBy('qr_envios')
            ->orderBy('id');
    }

    /**
     * Manda a credencial de UMA pessoa, agora.
     *
     * Existe separado do lote porque a necessidade é outra: alguém trocou de
     * e-mail, chegou atrasado na lista, ou pediu de novo no dia. Pôr essa
     * pessoa na fila e esperar o lote seria pedir que o operador rode uma
     * campanha inteira para atender um caso.
     *
     * Não passa pela reserva: é ação de uma pessoa clicando, não há corrida.
     *
     * @return bool false quando o envio falhou — o motivo fica em qr_erro
     */
    public function enviarIndividual(Registration $inscricao): bool
    {
        $evento = $inscricao->event;

        $inscricao->update([
            'qr_envios'       => $inscricao->qr_envios + 1,
            'qr_reservado_em' => null,
        ]);

        return $this->enviarUma($inscricao, $evento);
    }
}
