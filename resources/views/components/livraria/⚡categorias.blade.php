<?php

use App\Models\Livraria\Category;
use App\Models\Livraria\CategoryField;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Categorias de item e os campos que cada uma pede.
 *
 * É esta tela que faz o catálogo não ser só de livros: criar "Caneca" com os
 * campos "Capacidade" e "Material" passa a ser cadastro, não desenvolvimento —
 * o formulário do item se monta a partir daqui.
 *
 * A distinção que a tela precisa deixar clara: CAMPO descreve o item;
 * VARIAÇÃO divide o estoque. Tamanho e cor são variação, com saldo próprio.
 */
new
#[Layout('components.layouts.admin')]
#[Title('Categorias — Eventos IPCCG')]
class extends Component {
    #[Url(except: null)]
    public ?int $editando = null;

    public string $nome = '';
    public bool $usa_variacao = false;
    public string $rotulo_variacao = '';

    // campo em edição
    public string $campo_rotulo = '';
    public string $campo_tipo = 'texto';
    public string $campo_opcoes = '';
    public bool $campo_obrigatorio = false;
    public bool $campo_na_lista = false;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('livraria.catalogo'), 403);

        if ($this->editando) {
            $this->editar($this->editando);
        }
    }

    #[Computed]
    public function categorias()
    {
        return Category::withCount(['fields', 'products'])->orderBy('ordem')->orderBy('nome')->get();
    }

    #[Computed]
    public function emEdicao(): ?Category
    {
        return $this->editando ? Category::with('fields')->find($this->editando) : null;
    }

    public function novo(): void
    {
        $this->reset(['editando', 'nome', 'usa_variacao', 'rotulo_variacao',
                      'campo_rotulo', 'campo_tipo', 'campo_opcoes', 'campo_obrigatorio', 'campo_na_lista']);
        $this->resetErrorBag();
    }

    public function editar(int $id): void
    {
        $c = Category::findOrFail($id);

        $this->editando        = $c->id;
        $this->nome            = $c->nome;
        $this->usa_variacao    = (bool) $c->usa_variacao;
        $this->rotulo_variacao = (string) $c->rotulo_variacao;
        $this->resetErrorBag();
    }

    public function salvar(): void
    {
        abort_unless(auth()->user()->can('livraria.catalogo'), 403);

        $this->validate([
            'nome'            => ['required', 'string', 'max:60'],
            'rotulo_variacao' => [$this->usa_variacao ? 'required' : 'nullable', 'string', 'max:30'],
        ], attributes: ['rotulo_variacao' => 'nome da variação']);

        $slug = Str::slug($this->nome);

        $repetido = Category::where('slug', $slug)
            ->when($this->editando, fn ($q) => $q->whereKeyNot($this->editando))
            ->exists();

        if ($repetido) {
            $this->dispatch('toast', tipo: 'erro', mensagem: 'Já existe uma categoria com esse nome.');

            return;
        }

        $dados = [
            'nome'            => $this->nome,
            'slug'            => $slug,
            'usa_variacao'    => $this->usa_variacao,
            'rotulo_variacao' => $this->usa_variacao ? $this->rotulo_variacao : null,
        ];

        if ($this->editando) {
            Category::findOrFail($this->editando)->update($dados);
        } else {
            $categoria = Category::create($dados + [
                'ordem'      => (Category::max('ordem') ?? 0) + 1,
                'ativo'      => true,
                'created_at' => now(),
            ]);
            $this->editando = $categoria->id;
        }

        unset($this->categorias, $this->emEdicao);
        $this->dispatch('toast', tipo: 'ok', titulo: 'Categoria salva',
            mensagem: $this->nome.'. Acrescente abaixo os campos que ela pede.');
    }

    public function adicionarCampo(): void
    {
        abort_unless(auth()->user()->can('livraria.catalogo'), 403);

        $this->validate([
            'campo_rotulo' => ['required', 'string', 'max:60'],
            'campo_tipo'   => ['required', 'in:texto,texto_longo,inteiro,decimal,data,selecao,booleano'],
            'campo_opcoes' => [$this->campo_tipo === 'selecao' ? 'required' : 'nullable', 'string', 'max:500'],
        ], [
            'campo_opcoes.required' => 'Liste as opções, separadas por vírgula.',
        ], attributes: ['campo_rotulo' => 'nome do campo', 'campo_opcoes' => 'opções']);

        // A chave do JSON vem do rótulo, sem acento nem espaço.
        $chave = Str::of($this->campo_rotulo)->slug('_')->limit(40, '')->toString();

        if (CategoryField::where('category_id', $this->editando)->where('chave', $chave)->exists()) {
            $this->dispatch('toast', tipo: 'erro', mensagem: 'Esta categoria já tem um campo com esse nome.');

            return;
        }

        CategoryField::create([
            'category_id'      => $this->editando,
            'chave'            => $chave,
            'rotulo'           => $this->campo_rotulo,
            'tipo'             => $this->campo_tipo,
            'opcoes'           => $this->campo_tipo === 'selecao'
                ? array_values(array_filter(array_map('trim', explode(',', $this->campo_opcoes))))
                : null,
            'obrigatorio'      => $this->campo_obrigatorio,
            'mostrar_na_lista' => $this->campo_na_lista,
            'ordem'            => (CategoryField::where('category_id', $this->editando)->max('ordem') ?? 0) + 1,
        ]);

        $this->reset(['campo_rotulo', 'campo_opcoes', 'campo_obrigatorio', 'campo_na_lista']);
        $this->campo_tipo = 'texto';
        unset($this->emEdicao, $this->categorias);

        $this->dispatch('toast', tipo: 'ok', mensagem: 'Campo adicionado. Já aparece no cadastro do item.');
    }

    public function removerCampo(int $id): void
    {
        abort_unless(auth()->user()->can('livraria.catalogo'), 403);

        $campo = CategoryField::where('category_id', $this->editando)->findOrFail($id);
        $rotulo = $campo->rotulo;
        $campo->delete();

        unset($this->emEdicao, $this->categorias);

        // O valor continua no JSON dos itens já cadastrados — só deixa de ser
        // pedido e exibido. Apagar dos itens seria perda silenciosa.
        $this->dispatch('toast', tipo: 'info',
            mensagem: "“{$rotulo}” não será mais pedido. O que já foi preenchido continua guardado.");
    }

    public function alternarAtiva(int $id): void
    {
        abort_unless(auth()->user()->can('livraria.catalogo'), 403);

        $c = Category::findOrFail($id);

        if ($c->ativo && $c->products()->where('ativo', true)->exists()) {
            $this->dispatch('toast', tipo: 'aviso', titulo: 'Categoria em uso',
                mensagem: "“{$c->nome}” tem itens ativos. Desativar some do seletor, "
                    .'mas os itens continuam funcionando.');
        }

        $c->update(['ativo' => ! $c->ativo]);
        unset($this->categorias);

        $this->dispatch('toast', tipo: $c->ativo ? 'ok' : 'info',
            mensagem: $c->nome.($c->ativo ? ' reativada.' : ' desativada.'));
    }

    public function rotuloDoTipo(string $tipo): string
    {
        return [
            'texto' => 'Texto', 'texto_longo' => 'Texto longo', 'inteiro' => 'Número inteiro',
            'decimal' => 'Número decimal', 'data' => 'Data', 'selecao' => 'Lista de opções',
            'booleano' => 'Sim / não',
        ][$tipo] ?? $tipo;
    }
}; ?>

<div class="cadastro">
    <header class="painel-header">
        <div>
            <h1>Categorias</h1>
            <p class="subtitulo">
                Cada categoria define os campos que o item pede. É assim que o catálogo
                atende livro, camiseta e o que mais aparecer.
            </p>
        </div>
        <button type="button" class="btn-sm secondary" wire:click="novo">Nova categoria</button>
    </header>

    <div class="cadastro-grid">
        {{-- ── formulário ── --}}
        <section class="card">
            <h2 class="card-titulo">{{ $editando ? 'Editar categoria' : 'Nova categoria' }}</h2>

            <form class="form" wire:submit="salvar">
                <div>
                    <label for="cat-nome">Nome</label>
                    <input id="cat-nome" type="text" wire:model="nome" maxlength="60"
                           placeholder="Caneca" required>
                    @error('nome') <span class="field-error">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="checkbox">
                        <input type="checkbox" wire:model.live="usa_variacao">
                        Os itens têm variação com saldo próprio
                    </label>
                    <small class="ajuda">
                        Marque quando o item existir em versões que <strong>esgotam separado</strong> —
                        tamanho de camiseta, cor de caneca. Cada uma vira uma linha de estoque.
                        Para o que só descreve o item (autor, marca), use um campo abaixo.
                    </small>
                </div>

                @if ($usa_variacao)
                    <div>
                        <label for="cat-rotulo">Como chamar a variação</label>
                        <input id="cat-rotulo" type="text" wire:model="rotulo_variacao" maxlength="30"
                               placeholder="Tamanho">
                        @error('rotulo_variacao') <span class="field-error">{{ $message }}</span> @enderror
                    </div>
                @endif

                <div class="form-acoes">
                    @if ($editando)
                        <button type="button" class="btn-sm secondary" wire:click="novo">Nova</button>
                    @endif
                    <button type="submit" wire:loading.attr="disabled" wire:target="salvar">Salvar</button>
                </div>
            </form>

            {{-- ── campos da categoria: só depois de salvar, precisam do id ── --}}
            @if ($this->emEdicao)
                <div class="fotos-bloco">
                    <h3>Campos de {{ $this->emEdicao->nome }}</h3>
                    <p class="ajuda" style="margin-bottom:.8rem">
                        Aparecem no cadastro do item, nesta ordem.
                    </p>

                    @forelse ($this->emEdicao->fields as $campo)
                        <div class="linha-registro" wire:key="campo-{{ $campo->id }}">
                            <div style="flex:1;min-width:0">
                                <strong>{{ $campo->rotulo }}</strong>
                                <small class="bloco">
                                    {{ $this->rotuloDoTipo($campo->tipo) }}
                                    @if ($campo->obrigatorio) · obrigatório @endif
                                    @if ($campo->mostrar_na_lista) · aparece na listagem @endif
                                    @if ($campo->opcoes) · {{ implode(', ', $campo->opcoes) }} @endif
                                </small>
                            </div>
                            <button type="button" class="btn-sm btn-danger" wire:click="removerCampo({{ $campo->id }})"
                                    wire:confirm="Remover “{{ $campo->rotulo }}”? Ele deixa de ser pedido; o que já foi preenchido nos itens continua guardado.">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    @empty
                        <p class="vazio">Nenhum campo ainda.</p>
                    @endforelse

                    <form class="form" wire:submit="adicionarCampo" style="margin-top:1rem">
                        <div class="dupla">
                            <div>
                                <label for="cf-rotulo">Novo campo</label>
                                <input id="cf-rotulo" type="text" wire:model="campo_rotulo" maxlength="60"
                                       placeholder="Capacidade">
                                @error('campo_rotulo') <span class="field-error">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label for="cf-tipo">Tipo</label>
                                <select id="cf-tipo" wire:model.live="campo_tipo">
                                    <option value="texto">Texto</option>
                                    <option value="texto_longo">Texto longo</option>
                                    <option value="inteiro">Número inteiro</option>
                                    <option value="decimal">Número decimal</option>
                                    <option value="data">Data</option>
                                    <option value="selecao">Lista de opções</option>
                                    <option value="booleano">Sim / não</option>
                                </select>
                            </div>
                        </div>

                        @if ($campo_tipo === 'selecao')
                            <div>
                                <label for="cf-opcoes">Opções</label>
                                <input id="cf-opcoes" type="text" wire:model="campo_opcoes"
                                       placeholder="Preta, Branca, Azul">
                                <small class="ajuda">Separadas por vírgula.</small>
                                @error('campo_opcoes') <span class="field-error">{{ $message }}</span> @enderror
                            </div>
                        @endif

                        <label class="checkbox">
                            <input type="checkbox" wire:model="campo_obrigatorio"> Obrigatório
                        </label>
                        <label class="checkbox">
                            <input type="checkbox" wire:model="campo_na_lista">
                            Mostrar na listagem, embaixo do nome
                        </label>

                        <div class="form-acoes">
                            <button type="submit" wire:loading.attr="disabled" wire:target="adicionarCampo">
                                Adicionar campo
                            </button>
                        </div>
                    </form>
                </div>
            @endif
        </section>

        {{-- ── lista ── --}}
        <section class="card">
            <h2 class="card-titulo">Categorias cadastradas</h2>

            @forelse ($this->categorias as $c)
                <div class="linha-registro {{ $c->ativo ? '' : 'inativo' }}" wire:key="cat-{{ $c->id }}">
                    <div style="flex:1;min-width:0">
                        <strong>{{ $c->nome }}</strong>
                        <small class="bloco">
                            {{ $c->fields_count }} {{ $c->fields_count == 1 ? 'campo' : 'campos' }}
                            · {{ $c->products_count }} {{ $c->products_count == 1 ? 'item' : 'itens' }}
                            @if ($c->usa_variacao) · variação por {{ $c->rotuloVariacao() }} @endif
                        </small>
                    </div>
                    <button type="button" class="btn-sm secondary" wire:click="editar({{ $c->id }})">Editar</button>
                    <button type="button" class="btn-sm btn-ghost" wire:click="alternarAtiva({{ $c->id }})">
                        {{ $c->ativo ? 'Desativar' : 'Reativar' }}
                    </button>
                </div>
            @empty
                <p class="vazio">Nenhuma categoria ainda.</p>
            @endforelse
        </section>
    </div>
</div>
