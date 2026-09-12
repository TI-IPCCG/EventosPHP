<?php

use App\Models\Event;
use App\Models\Participantes\Checkin;
use App\Models\Participantes\EventDay;
use App\Models\Participantes\Registration;
use App\Services\Participantes\CheckinService;
use App\Services\Participantes\DayService;
use App\Services\Participantes\PersonResolver;
use App\Services\Participantes\RegistrationService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A PORTARIA. É a tela que decide se o módulo presta.
 *
 * As mesmas restrições que moldaram a Venda: uma das mãos, em pé, com fila
 * esperando. Daí:
 *  · uma caixa de busca só, que decide sozinha o que recebeu (token, código,
 *    e-mail, telefone, CPF ou nome) — o operador nunca escolhe "buscar por quê"
 *  · DOIS TOQUES por pessoa: achar e confirmar
 *  · o dia fica em #[Url]: recarregar no meio da fila não perde o contexto
 *  · confirmar não recarrega a busca inteira, só o contador e a lista recente
 *
 * O QR aponta para /p/{token}, que cai aqui com a busca preenchida. Assim o
 * operador vê sempre a MESMA tela, venha do scan, da digitação ou do nome.
 */
new
#[Layout('components.layouts.admin')]
#[Title('Check-in — Eventos IPCCG')]
class extends Component {
    #[Url(except: '')]
    public string $busca = '';

    #[Url(except: null)]
    public ?int $dia_id = null;

    /** Walk-in: quem apareceu sem estar inscrito. */
    public bool $mostrarWalkin = false;
    public string $w_nome = '';
    public string $w_contato = '';

    public function mount(DayService $dias): void
    {
        abort_unless(auth()->user()->can('ver-participantes'), 403);

        // A portaria não pode travar porque ninguém cadastrou os dias.
        if ($this->evento) {
            $dias->garantir($this->evento);
        }

        $this->dia_id ??= EventDay::deHoje($this->evento?->id ?? 0)?->id
            ?? EventDay::where('event_id', $this->evento?->id)->ativos()->value('id');
    }

    #[Computed]
    public function evento(): ?Event
    {
        return Event::atual();
    }

    #[Computed]
    public function dias()
    {
        return $this->evento
            ? EventDay::where('event_id', $this->evento->id)->ativos()->get()
            : collect();
    }

    #[Computed]
    public function dia(): ?EventDay
    {
        return $this->dias->firstWhere('id', $this->dia_id);
    }

    /** O dia escolhido é mesmo o de hoje? Senão a tela avisa em vermelho. */
    #[Computed]
    public function ehHoje(): bool
    {
        return $this->dia && $this->evento
            && $this->dia->data->isSameDay($this->evento->agora());
    }

    /**
     * Uma caixa, várias chaves. O formato da entrada decide a busca — teclado
     * numérico do celular digita "42", etiqueta traz "INS0042", o QR manda o
     * token, e quem não tem nada é achado pelo nome.
     */
    #[Computed]
    public function resultados()
    {
        $termo = trim($this->busca);

        if (! $this->evento || mb_strlen($termo) < 2) {
            return collect();
        }

        $q = Registration::where('event_id', $this->evento->id)
            ->with(['checkins' => fn ($c) => $c->whereNull('cancelado_em')]);

        // Token: 32 caracteres, vindo do QR. Casamento exato, nada de LIKE.
        if (preg_match('/^[A-Za-z0-9]{32}$/', $termo)) {
            return $q->where('token', $termo)->get();
        }

        $digitos = preg_replace('/\D/', '', $termo);

        return $q->where(function ($w) use ($termo, $digitos) {
            $w->where('nome', 'like', '%'.$termo.'%')
              ->orWhere('codigo', 'like', '%'.$termo.'%');

            if (str_contains($termo, '@')) {
                $w->orWhere('email', 'like', '%'.$termo.'%');
            }

            // Só dígitos: pode ser o número do código, o telefone ou o CPF.
            if ($digitos !== '' && strlen($digitos) >= 2) {
                $w->orWhere('telefone', 'like', '%'.$digitos.'%')
                  ->orWhere('cpf', 'like', '%'.$digitos.'%')
                  ->orWhere('codigo', 'like', '%'.$digitos);
            }
        })
        ->orderBy('nome')
        ->limit(12)
        ->get();
    }

    #[Computed]
    public function presentes(): int
    {
        return $this->dia
            ? Checkin::where('event_day_id', $this->dia->id)->whereNull('cancelado_em')->count()
            : 0;
    }

    #[Computed]
    public function inscritos(): int
    {
        return $this->evento
            ? Registration::where('event_id', $this->evento->id)->ativas()->count()
            : 0;
    }

    #[Computed]
    public function recentes()
    {
        return $this->dia
            ? Checkin::where('event_day_id', $this->dia->id)
                ->whereNull('cancelado_em')
                ->with('registration')
                ->orderByDesc('id')
                ->limit(8)
                ->get()
            : collect();
    }

    public function confirmar(int $registrationId, CheckinService $checkins): void
    {
        abort_unless(auth()->user()->can('participantes.checkin'), 403);

        if (! $this->dia) {
            $this->dispatch('toast', tipo: 'erro', mensagem: 'Escolha o dia antes de confirmar.');

            return;
        }

        $inscricao = Registration::where('event_id', $this->evento->id)->findOrFail($registrationId);

        try {
            $checkins->registrar(
                $inscricao,
                $this->dia,
                $this->canalDaBusca(),
                auth()->id(),
            );
        } catch (\RuntimeException $e) {
            // "Já entrou às 19h12" não é falha: é a informação que a portaria
            // precisa para decidir. Por isso aviso, e não erro vermelho.
            $this->dispatch('toast', tipo: 'aviso', titulo: $inscricao->nome,
                mensagem: $e->getMessage());
            $this->recarregarContadores();

            return;
        }

        $this->busca = '';
        $this->recarregarContadores();

        $this->dispatch('toast', tipo: 'ok', titulo: 'Entrada registrada',
            mensagem: $inscricao->nome.' · '.$inscricao->codigo);
    }

    public function desfazer(int $checkinId, CheckinService $checkins): void
    {
        abort_unless(auth()->user()->can('participantes.gerenciar'), 403);

        $checkin = Checkin::whereKey($checkinId)->with('registration')->firstOrFail();
        $checkins->cancelar($checkin, auth()->id());

        $this->recarregarContadores();
        $this->dispatch('toast', tipo: 'info',
            mensagem: 'Entrada de '.$checkin->registration->nome.' desfeita.');
    }

    /**
     * Quem apareceu sem estar inscrito. Sem isto a portaria anota no papel e o
     * dado nunca chega ao sistema.
     */
    public function inscreverEEntrar(RegistrationService $inscricoes, CheckinService $checkins): void
    {
        abort_unless(auth()->user()->can('participantes.checkin'), 403);

        $this->validate([
            'w_nome'    => ['required', 'string', 'min:3', 'max:150'],
            'w_contato' => ['nullable', 'string', 'max:191'],
        ], attributes: ['w_nome' => 'nome', 'w_contato' => 'contato']);

        $contato = trim($this->w_contato);
        $ehEmail = str_contains($contato, '@');

        $inscricao = $inscricoes->inscrever($this->evento, [
            'nome'     => $this->w_nome,
            'email'    => $ehEmail ? $contato : null,
            'telefone' => $ehEmail ? null : ($contato ?: null),
        ], origem: 'manual', operadorId: auth()->id());

        $checkins->registrar($inscricao, $this->dia, 'manual', auth()->id());

        $this->reset(['w_nome', 'w_contato', 'mostrarWalkin']);
        $this->recarregarContadores();

        $this->dispatch('toast', tipo: 'ok', titulo: 'Inscrito e liberado',
            mensagem: $inscricao->nome.' · '.$inscricao->codigo);
    }

    /** De onde veio a identificação — responde "quantos foram manuais?" depois. */
    private function canalDaBusca(): string
    {
        $termo = trim($this->busca);

        return match (true) {
            preg_match('/^[A-Za-z0-9]{32}$/', $termo) === 1 => 'qr',
            preg_match('/^[A-Za-z]{0,4}\d+$/', $termo) === 1 => 'codigo',
            default => 'busca',
        };
    }

    /** Só o que mudou: a busca continua na tela, a fila não espera. */
    private function recarregarContadores(): void
    {
        unset($this->presentes, $this->recentes, $this->resultados);
    }
}; ?>

<div class="checkin">
    @if (! $this->evento)
        <div class="empty-state">
            <i class="bi bi-calendar-x"></i>
            <h2>Nenhum evento ativo</h2>
            <p>Escolha um evento em <a href="{{ route('eventos') }}">Eventos</a> para abrir a portaria.</p>
        </div>
    @elseif ($this->dias->isEmpty())
        <div class="empty-state">
            <i class="bi bi-calendar-week"></i>
            <h2>Este evento não tem dias</h2>
            <p>Cadastre os dias em <a href="{{ route('participantes.dias') }}">Dias do evento</a>.</p>
        </div>
    @else
        <header class="painel-header">
            <div>
                <span class="painel-evento">{{ $this->evento->nome }}</span>
                <h1>Check-in</h1>
            </div>
            <span class="pill {{ $this->ehHoje ? 'vaga' : 'danger' }}">
                {{ $this->ehHoje ? 'hoje' : 'outro dia' }}
            </span>
        </header>

        {{-- ── o dia, grande: marcar no dia errado é o engano mais caro ── --}}
        <section class="card checkin-dia">
            <label for="c-dia">Dia</label>
            <select id="c-dia" wire:model.live="dia_id">
                @foreach ($this->dias as $d)
                    <option value="{{ $d->id }}">{{ $d->rotulo() }}</option>
                @endforeach
            </select>

            @unless ($this->ehHoje)
                <p class="checkin-alerta">
                    <i class="bi bi-exclamation-triangle"></i>
                    Este <strong>não</strong> é o dia de hoje. Confira antes de registrar entradas.
                </p>
            @endunless
        </section>

        <section class="stat-grid">
            <div class="stat-card">
                <span>Presentes {{ $this->dia?->rotulo() }}</span>
                <strong>{{ $this->presentes }} <small>de {{ $this->inscritos }}</small></strong>
            </div>
            <div class="stat-card">
                <span>Ainda não chegaram</span>
                <strong>{{ max(0, $this->inscritos - $this->presentes) }}</strong>
            </div>
        </section>

        {{-- ── a busca: uma caixa, várias chaves ── --}}
        <div class="venda-busca">
            <i class="bi bi-search"></i>
            <input type="search" wire:model.live.debounce.250ms="busca"
                   placeholder="Nome, código, e-mail, telefone ou QR"
                   autofocus enterkeyhint="search" autocomplete="off">
            @if ($busca !== '')
                <button type="button" wire:click="$set('busca', '')" aria-label="Limpar busca">
                    <i class="bi bi-x-lg"></i>
                </button>
            @endif
        </div>

        @if ($busca !== '' && mb_strlen(trim($busca)) >= 2)
            <ul class="venda-resultados">
                @forelse ($this->resultados as $r)
                    @php($presente = $r->checkins->firstWhere('event_day_id', $this->dia?->id))
                    <li wire:key="r-{{ $r->id }}">
                        <button type="button" wire:click="confirmar({{ $r->id }})"
                                wire:loading.attr="disabled">
                            <div class="res-info">
                                <strong>
                                    {{ $r->nome }}
                                    @if ($presente) <span class="pill open">já entrou</span> @endif
                                    @if ($r->cancelada) <span class="pill closed">cancelada</span> @endif
                                </strong>
                                <small class="bloco">
                                    {{ $r->codigo }}@if ($r->email) · {{ $r->email }} @endif
                                </small>
                            </div>
                            <span class="res-preco">
                                <i class="bi bi-{{ $presente ? 'check-circle-fill' : 'box-arrow-in-right' }}"></i>
                            </span>
                        </button>
                    </li>
                @empty
                    <li class="vazio">
                        Ninguém encontrado com “{{ $busca }}”.
                        @can('participantes.checkin')
                            <button type="button" class="btn-sm secondary"
                                    wire:click="$set('mostrarWalkin', true)">
                                Inscrever na hora
                            </button>
                        @endcan
                    </li>
                @endforelse
            </ul>
        @else
            <p class="venda-dica">
                Escaneie o QR, digite o código da credencial ou busque pelo nome.
            </p>
        @endif

        {{-- ── walk-in ── --}}
        @can('participantes.checkin')
            @if ($mostrarWalkin)
                <section class="card">
                    <h2 class="card-titulo">Inscrever na hora</h2>
                    <form class="form" wire:submit="inscreverEEntrar">
                        <div>
                            <label for="w-nome">Nome completo</label>
                            <input id="w-nome" type="text" wire:model="w_nome" maxlength="150" required>
                            @error('w_nome') <span class="field-error">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label for="w-contato">E-mail ou telefone</label>
                            <input id="w-contato" type="text" wire:model="w_contato" maxlength="191">
                            <small class="ajuda">Opcional — serve para achar a pessoa depois.</small>
                            @error('w_contato') <span class="field-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="form-acoes">
                            <button type="button" class="btn-sm secondary"
                                    wire:click="$set('mostrarWalkin', false)">Cancelar</button>
                            <button type="submit" wire:loading.attr="disabled" wire:target="inscreverEEntrar">
                                Inscrever e liberar
                            </button>
                        </div>
                    </form>
                </section>
            @endif
        @endcan

        {{-- ── últimas entradas ── --}}
        <section class="card">
            <h2 class="card-titulo"><i class="bi bi-clock-history"></i> Últimas entradas</h2>

            @forelse ($this->recentes as $c)
                <div class="linha-registro" wire:key="c-{{ $c->id }}">
                    <div>
                        <strong>{{ $c->registration->nome }}</strong>
                        <small class="bloco">
                            {{ $c->registrado_em?->format('H:i') }} · {{ $c->registration->codigo }}
                        </small>
                    </div>
                    @can('participantes.gerenciar')
                        <div class="card-acoes">
                            <button type="button" class="btn-sm btn-ghost"
                                    wire:click="desfazer({{ $c->id }})"
                                    wire:confirm="Desfazer a entrada de {{ $c->registration->nome }}?">
                                Desfazer
                            </button>
                        </div>
                    @endcan
                </div>
            @empty
                <p class="vazio">Ninguém entrou ainda {{ $this->dia?->rotulo() }}.</p>
            @endforelse
        </section>
    @endif
</div>
