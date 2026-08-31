<?php

use App\Models\Event;
use App\Models\Livraria\PaymentMethod;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Eventos da congregação: criar, editar e escolher qual está ATIVO.
 *
 * O evento ativo é o contexto de tudo que é operacional — remessa, venda,
 * baixa e painel olham para ele. Catálogo e fornecedores NÃO: são permanentes,
 * valem para todos os eventos.
 *
 * Os "parâmetros" ficam aqui junto porque é onde o coordenador pensa neles:
 * a meta e as formas de pagamento com suas taxas são do evento, não globais —
 * a maquininha muda de contrato entre um ano e outro.
 */
new
#[Layout('components.layouts.admin')]
#[Title('Eventos — Eventos IPCCG')]
class extends Component {
    #[Url(except: null)]
    public ?int $editando = null;

    public string $nome = '';
    public string $local = '';
    public string $inicio = '';
    public string $fim = '';
    public string $status = 'planejamento';

    public string $meta_tipo = 'zero_a_zero';
    public ?string $meta_valor = null;

    // forma de pagamento em edição
    public string $forma_nome = '';
    public ?string $forma_taxa = null;

    public function mount(): void
    {
        if ($this->editando) {
            $this->editar($this->editando);
        } else {
            $this->inicio = today()->toDateString();
        }
    }

    #[Computed]
    public function eventos()
    {
        return Event::withCount('copies')->orderByDesc('inicio')->get();
    }

    #[Computed]
    public function emEdicao(): ?Event
    {
        return $this->editando ? Event::with('livrariaSettings')->find($this->editando) : null;
    }

    #[Computed]
    public function formas()
    {
        return $this->editando
            ? PaymentMethod::where('event_id', $this->editando)->orderBy('ordem')->get()
            : collect();
    }

    protected function regras(): array
    {
        return [
            'nome'       => ['required', 'string', 'max:150'],
            'local'      => ['nullable', 'string', 'max:150'],
            'inicio'     => ['required', 'date'],
            'fim'        => ['nullable', 'date', 'after_or_equal:inicio'],
            'status'     => ['required', 'in:planejamento,em_andamento,encerrado'],
            'meta_tipo'  => ['required', 'in:zero_a_zero,valor'],
            'meta_valor' => [$this->meta_tipo === 'valor' ? 'required' : 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function novo(): void
    {
        $this->reset(['editando', 'nome', 'local', 'fim', 'meta_valor', 'forma_nome', 'forma_taxa']);
        $this->inicio    = today()->toDateString();
        $this->status    = 'planejamento';
        $this->meta_tipo = 'zero_a_zero';
        $this->resetErrorBag();
    }

    public function editar(int $id): void
    {
        $e = Event::with('livrariaSettings')->findOrFail($id);

        $this->editando   = $e->id;
        $this->nome       = $e->nome;
        $this->local      = (string) $e->local;
        $this->inicio     = $e->inicio->toDateString();
        $this->fim        = $e->fim?->toDateString() ?? '';
        $this->status     = $e->status;
        $this->meta_tipo  = $e->livrariaSettings->meta_tipo ?? 'zero_a_zero';
        $this->meta_valor = $e->livrariaSettings->meta_valor ?? null;
        $this->resetErrorBag();
    }

    public function salvar(): void
    {
        abort_unless(auth()->user()->can('eventos.gerenciar'), 403);

        $dados = $this->validate($this->regras(), attributes: [
            'inicio' => 'data de início', 'fim' => 'data de término', 'meta_valor' => 'valor da meta',
        ]);

        $evento = $this->editando
            ? tap(Event::findOrFail($this->editando))->update([
                'nome'   => $dados['nome'],
                'local'  => $dados['local'] ?: null,
                'inicio' => $dados['inicio'],
                'fim'    => $dados['fim'] ?: null,
                'status' => $dados['status'],
            ])
            : Event::create([
                'nome'   => $dados['nome'],
                'local'  => $dados['local'] ?: null,
                'inicio' => $dados['inicio'],
                'fim'    => $dados['fim'] ?: null,
                'status' => $dados['status'],
            ]);

        $evento->livrariaSettings()->updateOrCreate([], [
            'meta_tipo'  => $this->meta_tipo,
            'meta_valor' => $this->meta_tipo === 'valor' ? $this->meta_valor : null,
        ]);

        // Evento novo nasce com as formas de pagamento usuais — ninguém deveria
        // precisar cadastrar "PIX" do zero em todo evento.
        if (! $this->editando) {
            foreach ([['PIX', 0], ['Débito', 0], ['Crédito', 0]] as $i => [$nome, $taxa]) {
                PaymentMethod::create([
                    'event_id' => $evento->id, 'nome' => $nome,
                    'taxa_percentual' => $taxa, 'ordem' => $i + 1, 'ativo' => true,
                ]);
            }
        }

        $this->editando = $evento->id;
        unset($this->eventos, $this->emEdicao, $this->formas);

        $this->dispatch('toast', tipo: 'ok', titulo: 'Evento salvo',
            mensagem: $evento->nome.'. Confira as taxas de pagamento abaixo.');
    }

    public function ativar(int $id): void
    {
        $evento = Event::findOrFail($id);   // o ChurchScope já barra outra igreja
        session(['event_id' => $evento->id]);

        $this->dispatch('toast', tipo: 'info', titulo: 'Contexto trocado',
            mensagem: "Remessa, venda e painel passam a olhar para {$evento->nome}.");
    }

    public function salvarForma(): void
    {
        abort_unless(auth()->user()->can('eventos.gerenciar'), 403);

        $this->validate([
            'forma_nome' => ['required', 'string', 'max:50'],
            'forma_taxa' => ['required', 'numeric', 'min:0', 'max:100'],
        ], attributes: ['forma_nome' => 'forma de pagamento', 'forma_taxa' => 'taxa']);

        PaymentMethod::updateOrCreate(
            ['event_id' => $this->editando, 'nome' => $this->forma_nome],
            ['taxa_percentual' => $this->forma_taxa, 'ativo' => true,
             'ordem' => (PaymentMethod::where('event_id', $this->editando)->max('ordem') ?? 0) + 1],
        );

        $nome = $this->forma_nome;
        $this->reset(['forma_nome', 'forma_taxa']);
        unset($this->formas);

        $this->dispatch('toast', tipo: 'ok', mensagem: "{$nome} disponível na tela de venda.");
    }

    public function atualizarTaxa(int $id, string $taxa): void
    {
        abort_unless(auth()->user()->can('eventos.gerenciar'), 403);

        if (! is_numeric($taxa) || $taxa < 0 || $taxa > 100) {
            $this->dispatch('toast', tipo: 'erro', mensagem: 'A taxa precisa estar entre 0 e 100.');

            return;
        }

        $forma = PaymentMethod::where('event_id', $this->editando)->findOrFail($id);
        $forma->update(['taxa_percentual' => $taxa]);
        unset($this->formas);

        $this->dispatch('toast', tipo: 'ok',
            mensagem: "{$forma->nome}: taxa de ".number_format((float) $taxa, 2, ',', '.').'%. '
                .'Vale para as próximas vendas deste evento.');
    }

    public function removerForma(int $id): void
    {
        abort_unless(auth()->user()->can('eventos.gerenciar'), 403);

        $forma = PaymentMethod::where('event_id', $this->editando)->findOrFail($id);

        // Forma já usada numa venda não sai: a venda perderia o vínculo e a
        // conferência com o extrato da maquininha deixaria de fechar.
        if ($forma->event->sales()->where('payment_method_id', $forma->id)->exists()) {
            $this->dispatch('toast', tipo: 'erro', titulo: 'Não dá para remover',
                mensagem: "“{$forma->nome}” já foi usada numa venda. Desative em vez de remover, "
                    .'senão a conferência com o extrato deixa de fechar.');

            return;
        }

        $forma->delete();
        unset($this->formas);

        $this->dispatch('toast', tipo: 'info', mensagem: "{$forma->nome} removida.");
    }

    public function alternarForma(int $id): void
    {
        $f = PaymentMethod::where('event_id', $this->editando)->findOrFail($id);
        $f->update(['ativo' => ! $f->ativo]);
        unset($this->formas);

        $this->dispatch('toast', tipo: $f->ativo ? 'ok' : 'info',
            mensagem: $f->nome.($f->ativo ? ' reativada.' : ' desativada — some da tela de venda.'));
    }
}; ?>

<div class="cadastro">
    <header class="painel-header">
        <div>
            <h1>Eventos</h1>
            <p class="subtitulo">
                O evento <strong>ativo</strong> é o contexto da remessa, da venda e do painel.
                Catálogo e fornecedores são permanentes e valem para todos.
            </p>
        </div>
        @can('eventos.gerenciar')
            <button type="button" class="btn-sm secondary" wire:click="novo">Novo evento</button>
        @endcan
    </header>

    @if (session('ok'))
        <div class="alert ok" role="alert"><i class="bi bi-check-circle"></i> {{ session('ok') }}</div>
    @endif

    <div class="cadastro-grid">
        {{-- ── formulário ── --}}
        @can('eventos.gerenciar')
            <section class="card">
                <h2 class="card-titulo">{{ $editando ? 'Editar evento' : 'Novo evento' }}</h2>

                <form class="form" wire:submit="salvar">
                    <div>
                        <label for="e-nome">Nome</label>
                        <input id="e-nome" type="text" wire:model="nome" maxlength="150" required>
                        @error('nome') <span class="field-error">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="e-local">Local</label>
                        <input id="e-local" type="text" wire:model="local" maxlength="150" placeholder="Templo Central">
                        @error('local') <span class="field-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="dupla">
                        <div>
                            <label for="e-inicio">Início</label>
                            <input id="e-inicio" type="date" wire:model="inicio" required>
                            @error('inicio') <span class="field-error">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label for="e-fim">Término</label>
                            <input id="e-fim" type="date" wire:model="fim">
                            <small class="ajuda">Vazio = evento de um dia só.</small>
                            @error('fim') <span class="field-error">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div>
                        <label for="e-status">Situação</label>
                        <select id="e-status" wire:model.live="status">
                            <option value="planejamento">Planejamento — montando a remessa</option>
                            <option value="em_andamento">Em andamento — vendendo</option>
                            <option value="encerrado">Encerrado — fechando as contas</option>
                        </select>
                        @error('status') <span class="field-error">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="e-meta">Meta financeira</label>
                        <select id="e-meta" wire:model.live="meta_tipo">
                            <option value="zero_a_zero">Zero a zero — só cobrir os custos</option>
                            <option value="valor">Valor definido</option>
                        </select>
                        <small class="ajuda">
                            No zero a zero o painel mostra quanto falta para cobrir os custos.
                        </small>
                        @error('meta_tipo') <span class="field-error">{{ $message }}</span> @enderror
                    </div>

                    @if ($meta_tipo === 'valor')
                        <div>
                            <label for="e-meta-valor">Valor da meta</label>
                            <input id="e-meta-valor" type="number" step="0.01" min="0" wire:model="meta_valor">
                            @error('meta_valor') <span class="field-error">{{ $message }}</span> @enderror
                        </div>
                    @endif

                    <div class="form-acoes">
                        @if ($editando)
                            <button type="button" class="btn-sm secondary" wire:click="novo">Novo</button>
                        @endif
                        <button type="submit" wire:loading.attr="disabled" wire:target="salvar"
                                @if ($status === 'encerrado')
                                    wire:confirm="Encerrar este evento? A venda deixa de fazer sentido nele e o painel passa a mostrar o fechamento."
                                @endif>Salvar</button>
                    </div>
                </form>

                {{-- ── formas de pagamento: parâmetro DO evento ── --}}
                @if ($editando)
                    <div class="fotos-bloco">
                        <h3>Formas de pagamento e taxas</h3>
                        <p class="ajuda" style="margin-bottom:.8rem">
                            A taxa incide sobre o <strong>valor da transação</strong>, uma vez por venda.
                            São do evento porque a maquininha muda de contrato entre um ano e outro.
                        </p>

                        @error('formas') <div class="alert danger" role="alert">{{ $message }}</div> @enderror

                        @foreach ($this->formas as $f)
                            <div class="linha-registro {{ $f->ativo ? '' : 'inativo' }}" wire:key="forma-{{ $f->id }}">
                                <div style="flex:1">
                                    <strong>{{ $f->nome }}</strong>
                                    <small class="bloco">
                                        {{ $f->taxa_percentual > 0
                                            ? number_format($f->taxa_percentual, 2, ',', '.').'% de taxa'
                                            : 'sem taxa' }}
                                    </small>
                                </div>
                                <input type="number" step="0.01" min="0" max="100" style="width:90px"
                                       value="{{ $f->taxa_percentual }}"
                                       wire:change="atualizarTaxa({{ $f->id }}, $event.target.value)"
                                       aria-label="Taxa de {{ $f->nome }}">
                                <button type="button" class="btn-sm btn-ghost" wire:click="alternarForma({{ $f->id }})">
                                    {{ $f->ativo ? 'Desativar' : 'Reativar' }}
                                </button>
                                <button type="button" class="btn-sm btn-danger" wire:click="removerForma({{ $f->id }})"
                                        wire:confirm="Remover esta forma de pagamento?">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        @endforeach

                        <form class="linha-form" wire:submit="salvarForma" style="margin-top:.8rem">
                            <input type="text" wire:model="forma_nome" placeholder="Crédito parcelado" maxlength="50">
                            <input type="number" step="0.01" min="0" max="100" wire:model="forma_taxa" placeholder="% taxa">
                            <button type="submit">Adicionar</button>
                        </form>
                        @error('forma_nome') <span class="field-error">{{ $message }}</span> @enderror
                        @error('forma_taxa') <span class="field-error">{{ $message }}</span> @enderror
                    </div>
                @endif
            </section>
        @endcan

        {{-- ── lista ── --}}
        <section class="card">
            <h2 class="card-titulo">Eventos da congregação</h2>

            @forelse ($this->eventos as $e)
                <div class="linha-registro" wire:key="evento-{{ $e->id }}">
                    <div style="flex:1;min-width:0">
                        <strong>{{ $e->nome }}</strong>
                        <small class="bloco">
                            {{ $e->inicio->format('d/m/Y') }}
                            @if ($e->fim && ! $e->fim->isSameDay($e->inicio)) até {{ $e->fim->format('d/m/Y') }} @endif
                            @if ($e->local) · {{ $e->local }} @endif
                            · {{ $e->copies_count }} {{ $e->copies_count == 1 ? 'exemplar' : 'exemplares' }}
                        </small>
                    </div>

                    <span class="pill {{ $e->status === 'em_andamento' ? 'vaga' : ($e->status === 'encerrado' ? 'draft' : 'warn') }}">
                        {{ str($e->status)->replace('_', ' ') }}
                    </span>

                    @if (session('event_id') == $e->id)
                        <span class="pill ok">ativo</span>
                    @else
                        <button type="button" class="btn-sm secondary" wire:click="ativar({{ $e->id }})"
                                wire:confirm="Tornar “{{ $e->nome }}” o evento ativo? Remessa, venda e painel passam a olhar para ele.">
                            Tornar ativo
                        </button>
                    @endif

                    @can('eventos.gerenciar')
                        <button type="button" class="btn-sm btn-ghost" wire:click="editar({{ $e->id }})">
                            Editar
                        </button>
                    @endcan
                </div>
            @empty
                <div class="empty-state">
                    <i class="bi bi-calendar-plus"></i>
                    <h2>Nenhum evento ainda</h2>
                    <p>Crie o primeiro no formulário ao lado.</p>
                </div>
            @endforelse
        </section>
    </div>
</div>
