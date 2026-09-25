<?php

use App\Models\Event;
use App\Models\Participantes\Checkin;
use App\Models\Participantes\EventDay;
use App\Models\Participantes\Registration;
use App\Services\Participantes\CheckinService;
use App\Services\Participantes\DayService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A CONFIRMAÇÃO da entrada — uma tela por pessoa.
 *
 * É para onde o QR aponta. Existe separada da busca por um motivo: quem está na
 * porta precisa CONFERIR antes de liberar. Escanear e registrar no mesmo toque
 * significa marcar presença de quem passou o crachá de outra pessoa, e ninguém
 * percebe até o relatório sair errado.
 *
 * Por isso a tela inteira é um cartão só, com o nome grande, e o DIA em
 * destaque. O mesmo QR vale para os dois dias do evento: o que define a qual
 * deles a entrada pertence é o dia de hoje — e isso tem de estar escrito, não
 * subentendido.
 *
 * Não há seletor de dia aqui de propósito. Esta é a tela de quem está na porta
 * DURANTE o evento; deixar escolher abriria espaço para marcar no dia errado no
 * meio da fila. Lançamento retroativo é outra história, e outra tela.
 */
new
#[Layout('components.layouts.admin')]
#[Title('Confirmar entrada — Eventos IPCCG')]
class extends Component {
    public string $token = '';

    /**
     * O dia a registrar. Vazio = hoje, que é o caso da portaria durante o
     * evento. Vem preenchido quando a pessoa chegou pela busca tendo escolhido
     * outro dia — e é o que permite lançar entrada fora do dia (o dia seguinte
     * ainda não chegou, alguém ficou no papel, ou você está testando na véspera).
     */
    #[Url(except: null)]
    public ?int $dia_id = null;

    /** Vira true depois de registrar, para a tela virar confirmação. */
    public bool $registrado = false;
    public ?string $horaRegistrada = null;

    public function mount(string $token, DayService $dias): void
    {
        abort_unless(auth()->user()->can('participantes.checkin'), 403);

        $this->token = $token;

        if ($this->evento) {
            $dias->garantir($this->evento);
        }

        // Hoje é o padrão. Se hoje não é dia de evento, cai no primeiro dia
        // ativo — a tela avisa em vermelho que não é hoje, e quem está na porta
        // vê isso antes de confirmar.
        $this->dia_id ??= EventDay::deHoje($this->evento?->id ?? 0)?->id
            ?? EventDay::where('event_id', $this->evento?->id)->ativos()->value('id');
    }

    #[Computed]
    public function evento(): ?Event
    {
        return Event::atual();
    }

    #[Computed]
    public function inscricao(): ?Registration
    {
        if (! $this->evento) {
            return null;
        }

        return Registration::where('event_id', $this->evento->id)
            ->where('token', $this->token)
            ->first();
    }

    /** Os dias do evento, para trocar quando não for hoje. */
    #[Computed]
    public function dias()
    {
        return $this->evento
            ? EventDay::where('event_id', $this->evento->id)->ativos()->get()
            : collect();
    }

    /** O dia que será registrado. */
    #[Computed]
    public function dia(): ?EventDay
    {
        return $this->dias->firstWhere('id', $this->dia_id);
    }

    /**
     * O dia escolhido é mesmo hoje?
     *
     * Quando não é, a tela avisa em vermelho: marcar presença no dia errado é o
     * engano mais caro da portaria e o mais fácil de cometer.
     */
    #[Computed]
    public function ehHoje(): bool
    {
        return $this->dia && $this->evento
            && $this->dia->data->isSameDay($this->evento->agora());
    }

    /** A entrada que já existe hoje, se existir. */
    #[Computed]
    public function jaEntrou(): ?Checkin
    {
        if (! $this->inscricao || ! $this->dia) {
            return null;
        }

        return Checkin::where('registration_id', $this->inscricao->id)
            ->where('event_day_id', $this->dia->id)
            ->whereNull('cancelado_em')
            ->with('operador')
            ->first();
    }

    /** Em quais outros dias esta pessoa já entrou — contexto útil na porta. */
    #[Computed]
    public function outrosDias()
    {
        if (! $this->inscricao) {
            return collect();
        }

        return Checkin::where('registration_id', $this->inscricao->id)
            ->whereNull('cancelado_em')
            ->when($this->dia, fn ($q) => $q->where('event_day_id', '!=', $this->dia->id))
            ->with('day')
            ->get();
    }

    public function confirmar(CheckinService $checkins): void
    {
        abort_unless(auth()->user()->can('participantes.checkin'), 403);

        if (! $this->inscricao || ! $this->dia) {
            return;
        }

        try {
            $checkin = $checkins->registrar($this->inscricao, $this->dia, $this->ehHoje ? 'qr' : 'retroativo', auth()->id());
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', tipo: 'aviso', titulo: $this->inscricao->nome,
                mensagem: $e->getMessage());
            unset($this->jaEntrou);

            return;
        }

        $this->registrado = true;
        $this->horaRegistrada = $checkin->registrado_em?->format('H:i');
        unset($this->jaEntrou, $this->outrosDias);
    }
}; ?>

<div class="confirmar">
    @if (! $this->evento)
        <div class="empty-state">
            <i class="bi bi-calendar-x"></i>
            <h2>Nenhum evento ativo</h2>
            <p>Escolha um evento em <a href="{{ route('eventos') }}">Eventos</a>.</p>
        </div>

    @elseif (! $this->inscricao)
        {{-- Crachá de outro evento, ou QR inventado. --}}
        <div class="empty-state">
            <i class="bi bi-x-octagon"></i>
            <h2>Credencial não encontrada</h2>
            <p>
                Este código não pertence a nenhuma inscrição de
                <strong>{{ $this->evento->nome }}</strong>.
                Procure a pessoa pelo nome no <a href="{{ route('participantes.checkin') }}">check-in</a>.
            </p>
        </div>

    @elseif (! $this->dia)
        {{-- O evento não tem dia nenhum cadastrado. --}}
        <div class="empty-state">
            <i class="bi bi-calendar-x"></i>
            <h2>Este evento não tem dias</h2>
            <p>
                Cadastre em <a href="{{ route('participantes.dias') }}">Dias do evento</a>
                antes de registrar entradas.
            </p>
        </div>

    @else
        {{-- ── o cartão da pessoa ── --}}
        <section class="conf-cartao {{ $registrado || $this->jaEntrou ? 'conf-ok' : '' }}">
            @if ($registrado)
                <div class="conf-selo conf-selo-ok">
                    <i class="bi bi-check-circle-fill"></i> Entrada registrada às {{ $horaRegistrada }}
                </div>
            @elseif ($this->jaEntrou)
                <div class="conf-selo conf-selo-aviso">
                    <i class="bi bi-info-circle-fill"></i>
                    Já entrou às {{ $this->jaEntrou->registrado_em?->format('H:i') }}
                    @if ($this->jaEntrou->operador) · por {{ $this->jaEntrou->operador->name }} @endif
                </div>
            @endif

            <p class="conf-nome">{{ $this->inscricao->nome }}</p>
            <p class="conf-codigo">{{ $this->inscricao->codigo }}</p>

            @php($resp = $this->inscricao->respostas ?? [])
            @if ($resp)
                <p class="conf-extra">
                    @foreach (array_slice($resp, 0, 2) as $valor)
                        @if (filled($valor) && is_string($valor)) {{ $valor }} @endif
                    @endforeach
                </p>
            @endif

            @if ($this->outrosDias->isNotEmpty())
                <p class="conf-extra">
                    Já esteve em:
                    {{ $this->outrosDias->map(fn ($c) => $c->day?->rotulo())->filter()->implode(', ') }}
                </p>
            @endif
        </section>

        {{-- ── O RECADO DA PORTARIA ──────────────────────────────────
             Camiseta encomendada é TAREFA, não dado de cadastro: alguém
             tem de separar a peça e entregar, e a hora de lembrar é esta —
             com a pessoa na frente.

             Fica FORA do cartão e antes do botão, porque dentro do cartão
             viraria mais uma linha cinza entre outras. E aparece tanto
             antes quanto depois de confirmar: quem já bateu o check-in
             ainda precisa receber a camiseta. --}}
        @if (filled($this->inscricao->observacao))
            <section class="conf-recado" role="note">
                <i class="bi bi-bag-check-fill"></i>
                <div>
                    <strong>Tem item para entregar</strong>
                    <span>{{ $this->inscricao->observacao }}</span>
                </div>
            </section>
        @endif

        {{-- ── o dia: grande, porque é o que decide a qual dia a entrada pertence ── --}}
        <section class="conf-dia {{ $this->ehHoje ? '' : 'conf-dia-alerta' }}">
            <span class="conf-dia-rotulo">Entrada para</span>
            <strong class="conf-dia-nome">{{ $this->dia->rotulo() }}</strong>
            <span class="conf-dia-data">
                {{ $this->dia->data->format('d/m/Y') }}
                @if ($this->ehHoje)
                    · hoje
                @else
                    · <strong>NÃO é hoje</strong>
                @endif
            </span>

            @unless ($this->ehHoje)
                <p class="conf-dia-aviso">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    Hoje é {{ $this->evento->agora()->format('d/m/Y') }}. Confira antes de
                    confirmar — a entrada vai para o dia acima.
                </p>
            @endunless

            {{-- Trocar o dia fica aqui, discreto: durante o evento ninguém mexe,
                 e fora dele é o que permite lançar o que ficou no papel. --}}
            @if ($this->dias->count() > 1 && ! $registrado)
                <select wire:model.live="dia_id" class="conf-dia-troca"
                        aria-label="Trocar o dia da entrada">
                    @foreach ($this->dias as $d)
                        <option value="{{ $d->id }}">{{ $d->rotulo() }}</option>
                    @endforeach
                </select>
            @endif
        </section>

        {{-- ── a ação ── --}}
        <div class="conf-acoes">
            @if ($registrado)
                <a class="btn btn-accent conf-botao" href="{{ route('participantes.checkin') }}">
                    Próxima pessoa
                </a>
            @elseif ($this->jaEntrou)
                <a class="btn btn-ghost conf-botao" href="{{ route('participantes.checkin') }}">
                    Voltar ao check-in
                </a>
            @else
                <button type="button" class="conf-botao" wire:click="confirmar"
                        wire:loading.attr="disabled" wire:target="confirmar">
                    <span wire:loading.remove wire:target="confirmar">Confirmar entrada</span>
                    <span wire:loading wire:target="confirmar">Registrando…</span>
                </button>
                <a class="link conf-voltar" href="{{ route('participantes.checkin') }}">Cancelar</a>
            @endif
        </div>
    @endif
</div>
