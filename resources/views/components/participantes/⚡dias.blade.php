<?php

use App\Models\Event;
use App\Models\Participantes\Checkin;
use App\Models\Participantes\EventDay;
use App\Services\Participantes\DayService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Os dias do evento.
 *
 * Nascem semeados de inicio..fim — ninguém digita dia à mão. Esta tela existe
 * para nomear ("Sábado — manhã"), acrescentar o que está fora do intervalo
 * (retirada de kit na véspera) e suspender um dia cancelado sem apagar a
 * presença já registrada.
 */
new
#[Layout('components.layouts.admin')]
#[Title('Dias do evento — Eventos IPCCG')]
class extends Component {
    public ?int $editando = null;
    public string $nome = '';

    public string $nova_data = '';
    public string $novo_nome = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('participantes.gerenciar'), 403);
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
            ? EventDay::where('event_id', $this->evento->id)
                ->withCount(['checkins' => fn ($c) => $c->whereNull('cancelado_em')])
                ->orderBy('data')->orderBy('id')->get()
            : collect();
    }

    public function semear(DayService $dias): void
    {
        abort_unless(auth()->user()->can('participantes.gerenciar'), 403);

        try {
            $criados = $dias->sincronizar($this->evento);
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', tipo: 'erro', titulo: 'Confira as datas', mensagem: $e->getMessage());

            return;
        }

        unset($this->dias);

        $this->dispatch('toast', tipo: $criados ? 'ok' : 'info',
            mensagem: $criados
                ? $criados.($criados == 1 ? ' dia criado.' : ' dias criados.')
                : 'Os dias do intervalo já existem. Nada mudou.');
    }

    public function editar(int $id): void
    {
        $dia = EventDay::where('event_id', $this->evento->id)->findOrFail($id);
        $this->editando = $dia->id;
        $this->nome = (string) $dia->nome;
    }

    public function salvarNome(): void
    {
        abort_unless(auth()->user()->can('participantes.gerenciar'), 403);

        $this->validate(['nome' => ['nullable', 'string', 'max:60']]);

        EventDay::where('event_id', $this->evento->id)->findOrFail($this->editando)
            ->update(['nome' => $this->nome ?: null]);

        $this->cancelarEdicao();
        unset($this->dias);
        $this->dispatch('toast', tipo: 'ok', mensagem: 'Dia renomeado.');
    }

    public function cancelarEdicao(): void
    {
        $this->editando = null;
        $this->nome = '';
    }

    public function acrescentar(): void
    {
        abort_unless(auth()->user()->can('participantes.gerenciar'), 403);

        $this->validate([
            'nova_data' => ['required', 'date'],
            'novo_nome' => ['nullable', 'string', 'max:60'],
        ], attributes: ['nova_data' => 'data']);

        $existe = EventDay::where('event_id', $this->evento->id)
            ->whereDate('data', $this->nova_data)->exists();

        if ($existe) {
            $this->dispatch('toast', tipo: 'aviso', mensagem: 'Este dia já está na lista.');

            return;
        }

        EventDay::create([
            'event_id'   => $this->evento->id,
            'data'       => $this->nova_data,
            'nome'       => $this->novo_nome ?: null,
            'ativo'      => true,
            'created_at' => $this->evento->agora(),
        ]);

        $this->reset(['nova_data', 'novo_nome']);
        unset($this->dias);
        $this->dispatch('toast', tipo: 'ok', mensagem: 'Dia acrescentado.');
    }

    public function alternarAtivo(int $id): void
    {
        abort_unless(auth()->user()->can('participantes.gerenciar'), 403);

        $dia = EventDay::where('event_id', $this->evento->id)->findOrFail($id);
        $dia->update(['ativo' => ! $dia->ativo]);

        unset($this->dias);
        $this->dispatch('toast', tipo: $dia->ativo ? 'ok' : 'info',
            mensagem: $dia->rotulo().($dia->ativo
                ? ' reativado.'
                : ' desativado — não aceita mais entrada, e a presença registrada fica.'));
    }

    public function remover(int $id, DayService $dias): void
    {
        abort_unless(auth()->user()->can('participantes.gerenciar'), 403);

        $dia = EventDay::where('event_id', $this->evento->id)->findOrFail($id);

        try {
            $dias->apagar($dia);
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', tipo: 'erro', titulo: 'Não dá', mensagem: $e->getMessage());

            return;
        }

        unset($this->dias);
        $this->dispatch('toast', tipo: 'info', mensagem: 'Dia removido.');
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
                <h1>Dias do evento</h1>
                <p class="subtitulo">
                    O check-in é por dia: cada um tem a sua contagem de presença.
                </p>
            </div>
            <button type="button" class="btn-sm secondary" wire:click="semear">
                Gerar do período
            </button>
        </header>

        <div class="cadastro-grid">
            <section class="card">
                <h2 class="card-titulo">Acrescentar um dia</h2>
                <p class="ajuda" style="margin-top:0">
                    Para o que está fora do período do evento — retirada de kit na véspera,
                    por exemplo.
                </p>

                <form class="form" wire:submit="acrescentar">
                    <div>
                        <label for="d-data">Data</label>
                        <input id="d-data" type="date" wire:model="nova_data" required>
                        @error('nova_data') <span class="field-error">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label for="d-nome">Nome</label>
                        <input id="d-nome" type="text" wire:model="novo_nome" maxlength="60"
                               placeholder="Sábado — manhã">
                        <small class="ajuda">Opcional — sem nome, aparece a data.</small>
                    </div>
                    <div class="form-acoes">
                        <button type="submit" wire:loading.attr="disabled" wire:target="acrescentar">
                            Acrescentar
                        </button>
                    </div>
                </form>
            </section>

            <section class="card">
                <h2 class="card-titulo">Dias <span class="pill">{{ $this->dias->count() }}</span></h2>

                @forelse ($this->dias as $d)
                    <div class="linha-registro {{ $d->ativo ? '' : 'inativo' }}" wire:key="d-{{ $d->id }}">
                        @if ($editando === $d->id)
                            <input type="text" wire:model="nome" maxlength="60"
                                   wire:keydown.enter="salvarNome" placeholder="{{ $d->data->format('d/m/Y') }}"
                                   style="flex:1">
                            <div class="card-acoes">
                                <button type="button" class="btn-sm" wire:click="salvarNome">Salvar</button>
                                <button type="button" class="btn-sm secondary" wire:click="cancelarEdicao">Cancelar</button>
                            </div>
                        @else
                            <div>
                                <strong>{{ $d->rotulo() }}</strong>
                                @unless ($d->ativo) <span class="pill closed">desativado</span> @endunless
                                <small class="bloco">
                                    {{ $d->data->format('d/m/Y') }} ·
                                    {{ $d->checkins_count }}
                                    {{ $d->checkins_count == 1 ? 'presença' : 'presenças' }}
                                </small>
                            </div>
                            <div class="card-acoes">
                                <button type="button" class="btn-sm secondary" wire:click="editar({{ $d->id }})">
                                    Renomear
                                </button>
                                <button type="button" class="btn-sm btn-ghost" wire:click="alternarAtivo({{ $d->id }})"
                                        @if ($d->ativo)
                                            wire:confirm="Desativar {{ $d->rotulo() }}? Ele deixa de aceitar entrada; a presença registrada não muda."
                                        @endif>
                                    {{ $d->ativo ? 'Desativar' : 'Reativar' }}
                                </button>
                                @if ($d->checkins_count === 0)
                                    <button type="button" class="btn-sm btn-danger" wire:click="remover({{ $d->id }})"
                                            wire:confirm="Remover {{ $d->rotulo() }}?">
                                        Remover
                                    </button>
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="vazio">Nenhum dia ainda. Toque em “Gerar do período”.</p>
                @endforelse
            </section>
        </div>
    @endif
</div>
