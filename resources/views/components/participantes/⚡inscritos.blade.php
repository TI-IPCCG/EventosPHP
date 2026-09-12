<?php

use App\Models\Event;
use App\Models\Participantes\EventDay;
use App\Models\Participantes\Registration;
use App\Services\Participantes\PersonResolver;
use App\Services\Participantes\RegistrationService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Quem está inscrito no evento, e quem já veio.
 *
 * É também o caminho de entrada enquanto a importação não existe: dá para
 * cadastrar à mão, e é por aqui que se descobre, ANTES do evento, quem está com
 * e-mail inválido e não vai receber a credencial.
 */
new
#[Layout('components.layouts.admin')]
#[Title('Inscritos — Eventos IPCCG')]
class extends Component {
    #[Url(except: '')]
    public string $busca = '';

    /** todos | presentes | faltantes | sem_email | conflito */
    #[Url(except: 'todos')]
    public string $filtro = 'todos';

    public bool $mostrarForm = false;
    public string $nome = '';
    public string $email = '';
    public string $telefone = '';
    public string $cpf = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('ver-participantes'), 403);
    }

    #[Computed]
    public function evento(): ?Event
    {
        return Event::atual();
    }

    #[Computed]
    public function diaDeHoje(): ?EventDay
    {
        return $this->evento ? EventDay::deHoje($this->evento->id) : null;
    }

    #[Computed]
    public function inscritos()
    {
        if (! $this->evento) {
            return collect();
        }

        $hoje = $this->diaDeHoje?->id;

        return Registration::where('event_id', $this->evento->id)
            ->when($this->busca !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('nome', 'like', '%'.$this->busca.'%')
                ->orWhere('email', 'like', '%'.$this->busca.'%')
                ->orWhere('codigo', 'like', '%'.$this->busca.'%')))
            ->when($this->filtro === 'sem_email', fn ($q) => $q->where('email_valido', false))
            ->when($this->filtro === 'conflito', fn ($q) => $q->where('conflito_identidade', true))
            ->when($this->filtro === 'presentes' && $hoje, fn ($q) => $q
                ->whereHas('checkins', fn ($c) => $c->where('event_day_id', $hoje)->whereNull('cancelado_em')))
            ->when($this->filtro === 'faltantes' && $hoje, fn ($q) => $q
                ->whereDoesntHave('checkins', fn ($c) => $c->where('event_day_id', $hoje)->whereNull('cancelado_em')))
            ->with(['checkins' => fn ($c) => $c->whereNull('cancelado_em')])
            ->orderBy('nome')
            ->limit(300)
            ->get();
    }

    #[Computed]
    public function totais(): array
    {
        if (! $this->evento) {
            return ['inscritos' => 0, 'sem_email' => 0, 'conflito' => 0];
        }

        $base = Registration::where('event_id', $this->evento->id)->ativas();

        return [
            'inscritos' => (clone $base)->count(),
            'sem_email' => (clone $base)->where('email_valido', false)->count(),
            'conflito'  => (clone $base)->where('conflito_identidade', true)->count(),
        ];
    }

    public function novo(): void
    {
        abort_unless(auth()->user()->can('participantes.gerenciar'), 403);

        $this->reset(['nome', 'email', 'telefone', 'cpf']);
        $this->resetValidation();
        $this->mostrarForm = true;
    }

    public function salvar(RegistrationService $inscricoes): void
    {
        abort_unless(auth()->user()->can('participantes.gerenciar'), 403);

        $this->validate([
            'nome'     => ['required', 'string', 'min:3', 'max:150'],
            'email'    => ['nullable', 'email:rfc', 'max:191'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'cpf'      => ['nullable', 'string', 'max:14'],
        ], attributes: ['nome' => 'nome', 'email' => 'e-mail']);

        try {
            $inscricao = $inscricoes->inscrever($this->evento, [
                'nome'     => $this->nome,
                'email'    => $this->email ?: null,
                'telefone' => $this->telefone ?: null,
                'cpf'      => $this->cpf ?: null,
            ], origem: 'manual', operadorId: auth()->id());
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', tipo: 'erro', titulo: 'Não deu', mensagem: $e->getMessage());

            return;
        }

        $this->reset(['nome', 'email', 'telefone', 'cpf']);
        $this->mostrarForm = false;
        unset($this->inscritos, $this->totais);

        $this->dispatch('toast', tipo: 'ok', titulo: 'Inscrito',
            mensagem: $inscricao->nome.' · '.$inscricao->codigo);
    }

    public function cancelar(int $id, RegistrationService $inscricoes): void
    {
        abort_unless(auth()->user()->can('participantes.gerenciar'), 403);

        $inscricao = Registration::where('event_id', $this->evento->id)->findOrFail($id);
        $inscricoes->cancelar($inscricao, auth()->id());

        unset($this->inscritos, $this->totais);
        $this->dispatch('toast', tipo: 'info',
            mensagem: 'Inscrição de '.$inscricao->nome.' cancelada. O histórico fica.');
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
                <h1>Inscritos</h1>
            </div>
            @can('participantes.gerenciar')
                <button type="button" class="btn-sm secondary" wire:click="novo">Inscrever alguém</button>
            @endcan
        </header>

        <section class="stat-grid">
            <div class="stat-card">
                <span>Inscritos</span>
                <strong>{{ $this->totais['inscritos'] }}</strong>
            </div>
            <div class="stat-card">
                <span>Sem e-mail válido</span>
                <strong>{{ $this->totais['sem_email'] }}</strong>
            </div>
            <div class="stat-card">
                <span>Identidade em conflito</span>
                <strong>{{ $this->totais['conflito'] }}</strong>
            </div>
        </section>

        @if ($this->totais['sem_email'] > 0)
            <div class="alert info" role="alert" style="margin-bottom:14px">
                <div class="alert-content">
                    <div class="alert-title">{{ $this->totais['sem_email'] }} sem e-mail válido</div>
                    <div class="alert-message">
                        Essas pessoas <strong>não vão receber a credencial</strong>. Avise por outro
                        canal antes do evento — na portaria elas entram pelo nome.
                    </div>
                </div>
            </div>
        @endif

        @if ($mostrarForm)
            <section class="card">
                <h2 class="card-titulo">Inscrever alguém</h2>
                <form class="form" wire:submit="salvar">
                    <div>
                        <label for="i-nome">Nome completo</label>
                        <input id="i-nome" type="text" wire:model="nome" maxlength="150" required>
                        @error('nome') <span class="field-error">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label for="i-email">E-mail</label>
                        <input id="i-email" type="email" wire:model="email" maxlength="191">
                        <small class="ajuda">É para cá que a credencial com o QR vai.</small>
                        @error('email') <span class="field-error">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label for="i-tel">Telefone</label>
                        <input id="i-tel" type="text" wire:model="telefone" inputmode="tel" maxlength="20">
                    </div>
                    <div>
                        <label for="i-cpf">CPF</label>
                        <input id="i-cpf" type="text" wire:model="cpf" inputmode="numeric" maxlength="14">
                        <small class="ajuda">Opcional — ajuda a reconhecer a pessoa no próximo evento.</small>
                        @error('cpf') <span class="field-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-acoes">
                        <button type="button" class="btn-sm secondary"
                                wire:click="$set('mostrarForm', false)">Cancelar</button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="salvar">Inscrever</button>
                    </div>
                </form>
            </section>
        @endif

        <section class="card">
            <h2 class="card-titulo">Lista</h2>

            <div class="table-toolbar">
                <input type="search" class="table-search" wire:model.live.debounce.300ms="busca"
                       placeholder="Filtrar por nome, e-mail ou código">
                <select wire:model.live="filtro" aria-label="Filtro">
                    <option value="todos">Todos</option>
                    <option value="presentes">Presentes hoje</option>
                    <option value="faltantes">Ainda não chegaram</option>
                    <option value="sem_email">Sem e-mail válido</option>
                    <option value="conflito">Identidade em conflito</option>
                </select>
            </div>

            @forelse ($this->inscritos as $i)
                @php($presente = $this->diaDeHoje && $i->checkins->firstWhere('event_day_id', $this->diaDeHoje->id))
                <div class="linha-registro {{ $i->cancelada ? 'inativo' : '' }}" wire:key="i-{{ $i->id }}">
                    <div>
                        <strong>{{ $i->nome }}</strong>
                        @if ($presente) <span class="pill open">presente</span> @endif
                        @if ($i->cancelada) <span class="pill closed">cancelada</span> @endif
                        @unless ($i->email_valido) <span class="pill warn">sem e-mail</span> @endunless
                        @if ($i->conflito_identidade) <span class="pill draft">conferir</span> @endif
                        <small class="bloco">
                            {{ $i->codigo }}@if ($i->email) · {{ $i->email }}@endif
                            @if ($i->telefone) · {{ $i->telefone }} @endif
                        </small>
                    </div>
                    @can('participantes.gerenciar')
                        @unless ($i->cancelada)
                            <div class="card-acoes">
                                <button type="button" class="btn-sm btn-ghost"
                                        wire:click="cancelar({{ $i->id }})"
                                        wire:confirm="Cancelar a inscrição de {{ $i->nome }}? Ela deixa de valer, mas o histórico fica.">
                                    Cancelar
                                </button>
                            </div>
                        @endunless
                    @endcan
                </div>
            @empty
                <p class="vazio">
                    {{ $busca !== '' || $filtro !== 'todos'
                        ? 'Ninguém com esse filtro.'
                        : 'Ninguém inscrito ainda.' }}
                </p>
            @endforelse
        </section>
    @endif
</div>
