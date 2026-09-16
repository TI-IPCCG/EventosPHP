<?php

use App\Models\Event;
use App\Models\Participantes\Registration;
use App\Services\Participantes\EnvioService;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Enviar as credenciais com QR.
 *
 * O envio roda em BLOCOS, por wire:poll: cada tique é uma requisição nova, com
 * timeout novo — é o que torna viável mandar 60 e-mails sem fila nem worker,
 * que produção não tem.
 *
 * Fechar o navegador no meio não perde nada: o que já foi enviado está marcado
 * na linha, e clicar de novo continua de onde parou.
 */
new
#[Layout('components.layouts.admin')]
#[Title('Credenciais — Eventos IPCCG')]
class extends Component {
    public bool $rodando = false;
    public int $enviadosAgora = 0;
    public int $falhasAgora = 0;

    public string $emailTeste = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('participantes.enviar'), 403);

        $this->emailTeste = (string) auth()->user()->email;
    }

    #[Computed]
    public function evento(): ?Event
    {
        return Event::atual();
    }

    #[Computed]
    public function numeros(): array
    {
        if (! $this->evento) {
            return ['pendentes' => 0, 'enviados' => 0, 'falharam' => 0, 'sem_email' => 0];
        }

        $envio = app(EnvioService::class);

        return [
            'pendentes' => $envio->pendentes($this->evento),
            'enviados'  => $envio->enviados($this->evento),
            'falharam'  => $envio->falharam($this->evento),
            'sem_email' => Registration::where('event_id', $this->evento->id)
                ->ativas()->where('email_valido', false)->count(),
        ];
    }

    #[Computed]
    public function comProblema()
    {
        return $this->evento
            ? Registration::where('event_id', $this->evento->id)
                ->ativas()
                ->where(fn ($q) => $q->whereNotNull('qr_erro')->orWhere('email_valido', false))
                ->orderBy('nome')->limit(50)->get()
            : collect();
    }

    public function iniciar(): void
    {
        abort_unless(auth()->user()->can('participantes.enviar'), 403);

        $this->rodando = true;
        $this->enviadosAgora = 0;
        $this->falhasAgora = 0;
    }

    public function parar(): void
    {
        $this->rodando = false;
    }

    /** Chamado pelo wire:poll enquanto $rodando. */
    public function enviarLote(EnvioService $envio): void
    {
        if (! $this->rodando || ! $this->evento) {
            return;
        }

        abort_unless(auth()->user()->can('participantes.enviar'), 403);

        $r = $envio->enviarLote($this->evento);

        $this->enviadosAgora += $r['enviados'];
        $this->falhasAgora += $r['falhas'];

        unset($this->numeros, $this->comProblema);

        if ($r['restam'] === 0) {
            $this->rodando = false;

            $this->dispatch('toast', tipo: 'ok', titulo: 'Envio concluído',
                mensagem: $this->enviadosAgora.' enviadas'
                    .($this->falhasAgora ? ', '.$this->falhasAgora.' falharam' : '').'.');
        }
    }

    /**
     * O teste para si mesmo, antes de disparar para todo mundo.
     *
     * Vale o botão inteiro: manda a credencial de uma inscrição de verdade, com
     * o QR que a pessoa vai receber — é assim que se descobre que o e-mail cai
     * no spam ANTES de 60 pessoas não receberem.
     */
    public function enviarTeste(EnvioService $envio): void
    {
        abort_unless(auth()->user()->can('participantes.enviar'), 403);

        $this->validate(['emailTeste' => ['required', 'email:rfc']],
            attributes: ['emailTeste' => 'e-mail']);

        $amostra = Registration::where('event_id', $this->evento->id)->ativas()->first();

        if (! $amostra) {
            $this->dispatch('toast', tipo: 'aviso',
                mensagem: 'Inscreva alguém primeiro — o teste usa uma credencial de verdade.');

            return;
        }

        try {
            Mail::to($this->emailTeste)->send(new \App\Mail\CredencialMail(
                $amostra,
                route('participantes.credencial', ['token' => $amostra->token]),
                null,
            ));
        } catch (\Throwable $e) {
            $this->dispatch('toast', tipo: 'erro', titulo: 'O envio falhou',
                mensagem: mb_substr($e->getMessage(), 0, 160));

            return;
        }

        $this->dispatch('toast', tipo: 'ok', titulo: 'Teste enviado',
            mensagem: 'Confira a caixa de '.$this->emailTeste.' — e o spam.');
    }

    public function reenviar(int $id, EnvioService $envio): void
    {
        abort_unless(auth()->user()->can('participantes.enviar'), 403);

        $inscricao = Registration::where('event_id', $this->evento->id)->findOrFail($id);
        $envio->reabrir($inscricao);

        unset($this->numeros, $this->comProblema);
        $this->dispatch('toast', tipo: 'info',
            mensagem: $inscricao->nome.' volta para a fila de envio.');
    }
}; ?>

<div class="cadastro">
    @if (! $this->evento)
        <div class="empty-state">
            <i class="bi bi-calendar-x"></i>
            <h2>Nenhum evento ativo</h2>
            <p>Escolha um evento em <a href="{{ route('eventos') }}">Eventos</a>.</p>
        </div>
    @else
        <header class="painel-header">
            <div>
                <span class="painel-evento">{{ $this->evento->nome }}</span>
                <h1>Credenciais</h1>
                <p class="subtitulo">Envia o QR de entrada por e-mail, em blocos.</p>
            </div>
        </header>

        <section class="stat-grid">
            <div class="stat-card">
                <span>Enviadas</span>
                <strong>{{ $this->numeros['enviados'] }}</strong>
            </div>
            <div class="stat-card">
                <span>Na fila</span>
                <strong>{{ $this->numeros['pendentes'] }}</strong>
            </div>
            <div class="stat-card">
                <span>Falharam</span>
                <strong>{{ $this->numeros['falharam'] }}</strong>
            </div>
            <div class="stat-card">
                <span>Sem e-mail válido</span>
                <strong>{{ $this->numeros['sem_email'] }}</strong>
            </div>
        </section>

        {{-- ── o envio ── --}}
        <section class="card" @if ($rodando) wire:poll.2s="enviarLote" @endif>
            <h2 class="card-titulo"><i class="bi bi-send"></i> Envio</h2>

            @if ($rodando)
                <p class="ajuda" style="margin-top:0">
                    <strong>Enviando…</strong> {{ $this->enviadosAgora }} nesta sessão,
                    {{ $this->numeros['pendentes'] }} restando.
                    Pode fechar a página — o que já saiu não é reenviado, e clicar
                    em iniciar de novo continua daqui.
                </p>
                <div class="form-acoes">
                    <button type="button" class="btn-sm secondary" wire:click="parar">Pausar</button>
                </div>
            @elseif ($this->numeros['pendentes'] === 0)
                <p class="vazio">
                    Ninguém na fila.
                    @if ($this->numeros['enviados'] > 0)
                        Todas as {{ $this->numeros['enviados'] }} credenciais já foram enviadas.
                    @endif
                </p>
            @else
                <p class="ajuda" style="margin-top:0">
                    {{ $this->numeros['pendentes'] }}
                    {{ $this->numeros['pendentes'] == 1 ? 'pessoa aguarda' : 'pessoas aguardam' }}
                    a credencial. O envio vai em blocos e pode ser pausado a qualquer momento.
                </p>
                <div class="form-acoes">
                    <button type="button" wire:click="iniciar">Iniciar envio</button>
                </div>
            @endif
        </section>

        {{-- ── teste antes de disparar para todos ── --}}
        <section class="card">
            <h2 class="card-titulo">Enviar um teste</h2>
            <p class="ajuda" style="margin-top:0">
                Manda uma credencial de verdade para você, com o QR que as pessoas vão
                receber. <strong>Faça isso antes do envio geral</strong> — é assim que se
                descobre que o e-mail cai no spam.
            </p>
            <form class="form" wire:submit="enviarTeste">
                <div>
                    <label for="t-email">E-mail</label>
                    <input id="t-email" type="email" wire:model="emailTeste" maxlength="191" required>
                    @error('emailTeste') <span class="field-error">{{ $message }}</span> @enderror
                </div>
                <div class="form-acoes">
                    <button type="submit" class="btn-sm secondary"
                            wire:loading.attr="disabled" wire:target="enviarTeste">
                        Enviar teste
                    </button>
                </div>
            </form>
        </section>

        {{-- ── quem não vai receber ── --}}
        @if ($this->comProblema->isNotEmpty())
            <section class="card">
                <h2 class="card-titulo">
                    <i class="bi bi-exclamation-triangle"></i> Precisam de atenção
                    <span class="pill warn">{{ $this->comProblema->count() }}</span>
                </h2>
                <p class="ajuda" style="margin-top:0">
                    Estas pessoas <strong>não vão receber a credencial</strong>. Avise por
                    outro canal — na portaria elas entram pelo nome ou pelo código.
                </p>

                @foreach ($this->comProblema as $i)
                    <div class="linha-registro" wire:key="p-{{ $i->id }}">
                        <div>
                            <strong>{{ $i->nome }}</strong>
                            @unless ($i->email_valido) <span class="pill warn">e-mail inválido</span> @endunless
                            <small class="bloco">
                                {{ $i->codigo }}@if ($i->email) · {{ $i->email }} @endif
                                @if ($i->qr_erro) · {{ $i->qr_erro }} @endif
                            </small>
                        </div>
                        @if ($i->email_valido)
                            <div class="card-acoes">
                                <button type="button" class="btn-sm btn-ghost" wire:click="reenviar({{ $i->id }})">
                                    Tentar de novo
                                </button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </section>
        @endif
    @endif
</div>
