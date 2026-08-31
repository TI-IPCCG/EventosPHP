<?php

use App\Models\Livraria\Category;
use App\Models\Livraria\Product;
use App\Models\Livraria\ProductPhoto;
use App\Models\Livraria\Supplier;
use App\Models\Livraria\Variant;
use App\Services\Livraria\PhotoService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Catálogo de itens — permanente, reaproveitado entre eventos.
 *
 * O formulário se MONTA a partir da categoria: livro pede autor e editora,
 * camiseta pede modelo e marca. Nenhum desses campos existe no código desta
 * tela; todos vêm de liv_category_fields, então acrescentar "ISBN" é cadastro,
 * não desenvolvimento.
 *
 * Tamanho não é campo: é variação, com saldo próprio (aba ao lado).
 */
new
#[Layout('components.layouts.admin')]
#[Title('Catálogo — Eventos IPCCG')]
class extends Component {
    use WithFileUploads;

    public string $busca = '';

    /** Na URL: dá link direto para um item, e recarregar não perde a edição. */
    #[Url(except: null)]
    public ?int $editando = null;

    // campos fixos
    public ?int $category_id = null;
    public ?int $supplier_id = null;
    public string $nome = '';
    public ?string $preco_referencia = null;

    /** Vazio = usa o desconto do fornecedor. */
    public ?string $desconto = null;

    /** Campo universal: existe para qualquer categoria, fora do JSON. */
    public string $observacoes = '';

    /** valores dos campos que a CATEGORIA define */
    public array $atributos = [];

    /** variações em edição: [['id' => ?int, 'nome' => string]] */
    public array $variacoes = [];

    public $foto;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('livraria.catalogo'), 403);

        // Item veio pela URL (atalho do estoque, ou link salvo): carrega o
        // formulário já preenchido. Se o item não existe mais, abre em branco
        // em vez de estourar — link antigo não deve derrubar a tela.
        if ($this->editando) {
            Product::find($this->editando)
                ? $this->editar($this->editando)
                : $this->novo();
        }
    }

    #[Computed]
    public function categorias()
    {
        return Category::ativas()->with('fields')->get();
    }

    #[Computed]
    public function fornecedores()
    {
        return Supplier::ativos()->get();
    }

    /** A categoria escolhida agora — é ela que define o formulário. */
    #[Computed]
    public function categoria(): ?Category
    {
        return $this->category_id ? $this->categorias->firstWhere('id', $this->category_id) : null;
    }

    #[Computed]
    public function itens()
    {
        return Product::with(['category', 'supplier', 'coverPhoto', 'variants'])
            ->when(trim($this->busca) !== '', fn ($q) => $q->where('nome', 'like', '%'.trim($this->busca).'%'))
            ->orderBy('nome')
            ->get();
    }

    /** O fornecedor escolhido agora, para exibir o desconto padrão dele. */
    #[Computed]
    public function fornecedor()
    {
        return $this->supplier_id ? $this->fornecedores->firstWhere('id', $this->supplier_id) : null;
    }

    #[Computed]
    public function fotos()
    {
        return $this->editando
            ? ProductPhoto::where('product_id', $this->editando)->orderBy('ordem')->get()
            : collect();
    }

    /** Trocar de categoria descarta atributos que não existem na nova. */
    public function updatedCategoryId(): void
    {
        $chaves = $this->categoria?->fields->pluck('chave')->all() ?? [];
        $this->atributos = array_intersect_key($this->atributos, array_flip($chaves));

        if (! $this->categoria?->usa_variacao) {
            $this->variacoes = [];
        }
    }

    protected function regras(): array
    {
        $regras = [
            'category_id'      => ['required', 'integer'],
            'supplier_id'      => ['required', 'integer'],
            'nome'             => ['required', 'string', 'max:200'],
            'preco_referencia' => ['nullable', 'numeric', 'min:0'],
            'desconto'         => ['nullable', 'numeric', 'min:0', 'max:100'],
            'observacoes'      => ['nullable', 'string', 'max:2000'],
        ];

        // As regras dos campos dinâmicos vêm da própria definição da categoria.
        foreach ($this->categoria?->fields ?? [] as $campo) {
            $regras["atributos.{$campo->chave}"] = $campo->regras();
        }

        if ($this->categoria?->usa_variacao) {
            $regras['variacoes']        = ['required', 'array', 'min:1'];
            $regras['variacoes.*.nome'] = ['required', 'string', 'max:30'];
        }

        return $regras;
    }

    protected function rotulos(): array
    {
        $rotulos = ['category_id' => 'categoria', 'supplier_id' => 'fornecedor'];

        foreach ($this->categoria?->fields ?? [] as $campo) {
            $rotulos["atributos.{$campo->chave}"] = mb_strtolower($campo->rotulo);
        }

        return $rotulos;
    }

    public function novo(): void
    {
        $this->reset(['editando', 'nome', 'preco_referencia', 'desconto', 'observacoes',
                      'atributos', 'variacoes', 'foto', 'supplier_id']);
        $this->resetErrorBag();
    }

    public function editar(int $id): void
    {
        $p = Product::with('variants')->findOrFail($id);

        $this->editando         = $p->id;
        $this->category_id      = $p->category_id;
        $this->supplier_id      = $p->supplier_id;
        $this->nome             = $p->nome;
        $this->preco_referencia = $p->preco_referencia;
        $this->observacoes      = (string) $p->observacoes;
        $this->desconto         = $p->desconto;
        $this->atributos        = $p->atributos ?? [];
        $this->variacoes        = $p->variants->map(fn ($v) => ['id' => $v->id, 'nome' => $v->nome])->all();
        $this->resetErrorBag();
    }

    public function adicionarVariacao(): void
    {
        $this->variacoes[] = ['id' => null, 'nome' => ''];
    }

    public function removerVariacao(int $i): void
    {
        // Variação com exemplar na remessa não sai daqui: sumiria com o saldo
        // de um tamanho inteiro.
        $variacao = isset($this->variacoes[$i]['id']) ? Variant::find($this->variacoes[$i]['id']) : null;

        if ($variacao && $variacao->shipmentItems()->exists()) {
            $this->dispatch('toast', tipo: 'erro', titulo: 'Não dá para remover',
                mensagem: "“{$variacao->nome}” já está em uma remessa. Remover levaria junto o saldo desse tamanho.");

            return;
        }

        unset($this->variacoes[$i]);
        $this->variacoes = array_values($this->variacoes);
    }

    public function salvar(): void
    {
        abort_unless(auth()->user()->can('livraria.catalogo'), 403);

        $this->validate($this->regras(), attributes: $this->rotulos());

        $dados = [
            'category_id'      => $this->category_id,
            'supplier_id'      => $this->supplier_id,
            'nome'             => $this->nome,
            'preco_referencia' => $this->preco_referencia === '' ? null : $this->preco_referencia,
            'desconto'         => $this->desconto === '' ? null : $this->desconto,
            'atributos'        => array_filter($this->atributos, fn ($v) => $v !== '' && $v !== null),
            'observacoes'      => trim($this->observacoes) ?: null,
        ];

        $produto = $this->editando
            ? tap(Product::findOrFail($this->editando))->update($dados)
            : Product::create($dados + ['ativo' => true]);

        $this->sincronizarVariacoes($produto);

        $this->editando = $produto->id;
        unset($this->itens);

        $this->dispatch('toast', tipo: 'ok', mensagem: "{$produto->nome} salvo.");
    }

    private function sincronizarVariacoes(Product $produto): void
    {
        if (! $produto->usaVariacao()) {
            return;
        }

        $mantidos = [];

        foreach (array_values($this->variacoes) as $i => $v) {
            $variacao = Variant::updateOrCreate(
                ['product_id' => $produto->id, 'nome' => trim($v['nome'])],
                ['ordem' => $i + 1, 'ativo' => true],
            );
            $mantidos[] = $variacao->id;
        }

        // As que sumiram do formulário só são apagadas se nunca entraram numa
        // remessa — o mesmo cuidado do removerVariacao(), agora no salvar.
        Variant::where('product_id', $produto->id)
            ->whereNotIn('id', $mantidos)
            ->whereDoesntHave('shipmentItems')
            ->delete();

        $this->variacoes = $produto->variants()->get()
            ->map(fn ($v) => ['id' => $v->id, 'nome' => $v->nome])->all();
    }

    public function enviarFoto(PhotoService $fotos): void
    {
        abort_unless(auth()->user()->can('livraria.catalogo'), 403);

        $this->validate(['foto' => ['required', 'image', 'max:5120']], [
            'foto.image' => 'O arquivo precisa ser uma imagem.',
            'foto.max'   => 'A imagem passa de 5 MB.',
        ]);

        $fotos->adicionar(Product::findOrFail($this->editando), $this->foto);

        $this->reset('foto');
        unset($this->fotos, $this->itens);

        $this->dispatch('toast', tipo: 'ok', mensagem: 'Foto adicionada.');
    }

    public function definirCapa(int $fotoId, PhotoService $fotos): void
    {
        $fotos->definirCapa(ProductPhoto::findOrFail($fotoId));
        unset($this->fotos, $this->itens);

        $this->dispatch('toast', tipo: 'ok', mensagem: 'Capa alterada.');
    }

    public function removerFoto(int $fotoId, PhotoService $fotos): void
    {
        $fotos->remover(ProductPhoto::findOrFail($fotoId));
        unset($this->fotos, $this->itens);

        $this->dispatch('toast', tipo: 'info', mensagem: 'Foto removida.');
    }
}; ?>

<div class="cadastro">
    <header class="painel-header">
        <div>
            <h1>Catálogo</h1>
            <p class="subtitulo">Itens permanentes, reaproveitados em todos os eventos.</p>
        </div>
        <button type="button" class="btn-sm secondary" wire:click="novo">Novo item</button>
    </header>

    @if (session('ok'))
        <div class="alert ok" role="alert"><i class="bi bi-check-circle"></i> {{ session('ok') }}</div>
    @endif

    <div class="cadastro-grid">
        {{-- ── formulário ── --}}
        <section class="card">
            <h2 class="card-titulo">{{ $editando ? 'Editar item' : 'Novo item' }}</h2>

            <form class="form" wire:submit="salvar">
                <div>
                    <label for="c-categoria">Categoria</label>
                    <select id="c-categoria" wire:model.live="category_id" required>
                        <option value="">Selecione…</option>
                        @foreach ($this->categorias as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->nome }}</option>
                        @endforeach
                    </select>
                    <small class="ajuda">A categoria define quais campos o item pede.</small>
                    @error('category_id') <span class="field-error">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="c-fornecedor">Fornecedor</label>
                    <select id="c-fornecedor" wire:model="supplier_id" required>
                        <option value="">Selecione…</option>
                        @foreach ($this->fornecedores as $f)
                            <option value="{{ $f->id }}">{{ $f->nome }}</option>
                        @endforeach
                    </select>
                    @error('supplier_id') <span class="field-error">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="c-nome">Nome</label>
                    <input id="c-nome" type="text" wire:model="nome" maxlength="200" required>
                    @error('nome') <span class="field-error">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="c-preco">Preço de referência</label>
                    <input id="c-preco" type="number" step="0.01" min="0" wire:model="preco_referencia">
                    <small class="ajuda">Preço de capa ou de tabela. O preço de venda é definido na remessa.</small>
                    @error('preco_referencia') <span class="field-error">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="c-desconto">Desconto deste item (%)</label>
                    <input id="c-desconto" type="number" step="0.01" min="0" max="100"
                           wire:model.live.debounce.400ms="desconto"
                           placeholder="{{ $this->fornecedor?->desconto_padrao !== null
                               ? rtrim(rtrim(number_format($this->fornecedor->desconto_padrao, 2, '.', ''), '0'), '.')
                               : '' }}">
                    <small class="ajuda">
                        @if ($desconto !== null && $desconto !== '')
                            Só deste item — ignora o padrão do fornecedor.
                        @elseif ($this->fornecedor?->desconto_padrao !== null)
                            Vazio usa o padrão de {{ $this->fornecedor->nome }}:
                            <strong>{{ number_format($this->fornecedor->desconto_padrao, 2, ',', '.') }}%</strong>.
                        @else
                            Vazio e sem padrão no fornecedor: o custo é digitado na remessa.
                        @endif
                        Serve para <strong>sugerir</strong> o custo ao montar a remessa —
                        o valor do acerto é o que ficar gravado lá.
                    </small>
                    @error('desconto') <span class="field-error">{{ $message }}</span> @enderror
                </div>

                {{-- ── campos que a CATEGORIA define ── --}}
                @if ($this->categoria)
                    @foreach ($this->categoria->fields as $campo)
                        <div wire:key="campo-{{ $campo->id }}">
                            <label for="attr-{{ $campo->chave }}">
                                {{ $campo->rotulo }}
                                @unless ($campo->obrigatorio) <small class="opcional">opcional</small> @endunless
                            </label>

                            @if ($campo->tipo === 'selecao')
                                <select id="attr-{{ $campo->chave }}" wire:model="atributos.{{ $campo->chave }}">
                                    <option value="">Selecione…</option>
                                    @foreach ($campo->opcoes ?? [] as $opcao)
                                        <option value="{{ $opcao }}">{{ $opcao }}</option>
                                    @endforeach
                                </select>
                            @elseif ($campo->tipo === 'texto_longo')
                                <textarea id="attr-{{ $campo->chave }}" rows="3"
                                          wire:model="atributos.{{ $campo->chave }}"></textarea>
                            @elseif ($campo->tipo === 'booleano')
                                <label class="checkbox">
                                    <input type="checkbox" wire:model="atributos.{{ $campo->chave }}"> Sim
                                </label>
                            @else
                                <input id="attr-{{ $campo->chave }}"
                                       type="{{ in_array($campo->tipo, ['inteiro','decimal']) ? 'number' : ($campo->tipo === 'data' ? 'date' : 'text') }}"
                                       @if ($campo->tipo === 'decimal') step="0.01" @endif
                                       wire:model="atributos.{{ $campo->chave }}">
                            @endif

                            @error("atributos.{$campo->chave}") <span class="field-error">{{ $message }}</span> @enderror
                        </div>
                    @endforeach
                @endif

                {{-- ── variações: saldo próprio por tamanho/cor ── --}}
                @if ($this->categoria?->usa_variacao)
                    <div class="variacoes">
                        <label>{{ $this->categoria->rotuloVariacao() }}</label>
                        <small class="ajuda">
                            Cada uma tem saldo próprio: é o que responde “tem no M?” na mesa.
                        </small>

                        @foreach ($variacoes as $i => $v)
                            <div class="variacao-linha" wire:key="var-{{ $i }}">
                                <input type="text" wire:model="variacoes.{{ $i }}.nome"
                                       maxlength="30" placeholder="P, M, G…">
                                <button type="button" class="btn-sm btn-danger" wire:click="removerVariacao({{ $i }})"
                                        @if (! empty($v['id']))
                                            wire:confirm="Remover “{{ $v['nome'] }}”? Só é possível se ainda não entrou em nenhuma remessa."
                                        @endif
                                        aria-label="Remover">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            @error("variacoes.{$i}.nome") <span class="field-error">{{ $message }}</span> @enderror
                        @endforeach

                        <button type="button" class="btn-sm secondary" wire:click="adicionarVariacao">
                            <i class="bi bi-plus"></i> Adicionar
                        </button>
                        @error('variacoes') <span class="field-error">{{ $message }}</span> @enderror
                    </div>
                @endif

                {{-- universal: não depende da categoria --}}
                <div>
                    <label for="c-obs">Observações</label>
                    <textarea id="c-obs" rows="3" wire:model="observacoes" maxlength="2000"
                              placeholder="Edição de bolso · capa dura · exemplar de mostruário…"></textarea>
                    <small class="ajuda">Vale para qualquer categoria. Aparece na conferência do estoque.</small>
                    @error('observacoes') <span class="field-error">{{ $message }}</span> @enderror
                </div>

                <div class="form-acoes">
                    @if ($editando)
                        <button type="button" class="btn-sm secondary" wire:click="novo">Novo</button>
                    @endif
                    <button type="submit" wire:loading.attr="disabled" wire:target="salvar">Salvar</button>
                </div>
            </form>

            {{-- ── fotos: só depois de salvar, porque precisam do id ── --}}
            @if ($editando)
                <div class="fotos-bloco">
                    <h3>Fotos</h3>

                    <div class="fotos-galeria">
                        @foreach ($this->fotos as $f)
                            <figure class="foto {{ $f->capa ? 'capa' : '' }}" wire:key="foto-{{ $f->id }}">
                                <img src="{{ $f->urlThumb() }}" alt="">
                                @if ($f->capa) <figcaption>capa</figcaption> @endif
                                <div class="foto-acoes">
                                    @unless ($f->capa)
                                        <button type="button" wire:click="definirCapa({{ $f->id }})"
                                                title="Usar como capa"><i class="bi bi-star"></i></button>
                                    @endunless
                                    <button type="button" wire:click="removerFoto({{ $f->id }})"
                                            wire:confirm="Remover esta foto?" title="Remover">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </figure>
                        @endforeach
                    </div>

                    <input type="file" wire:model="foto" accept="image/*">
                    @error('foto') <span class="field-error">{{ $message }}</span> @enderror

                    <div wire:loading wire:target="foto" class="ajuda">Enviando…</div>

                    @if ($foto)
                        <button type="button" class="btn-sm" wire:click="enviarFoto"
                                wire:loading.attr="disabled" wire:target="enviarFoto">
                            Adicionar foto
                        </button>
                    @endif
                </div>
            @endif
        </section>

        {{-- ── lista ── --}}
        <section class="card">
            <h2 class="card-titulo">Itens cadastrados</h2>

            <div class="venda-busca">
                <i class="bi bi-search"></i>
                <input type="search" wire:model.live.debounce.250ms="busca"
                       placeholder="Filtrar por nome" aria-label="Filtrar itens">
            </div>

            @forelse ($this->itens as $item)
                <div class="linha-registro" wire:key="item-{{ $item->id }}">
                    @if ($item->coverPhoto)
                        <img class="mini-thumb" src="{{ $item->coverPhoto->urlThumb() }}" alt="" loading="lazy">
                    @else
                        <span class="mini-thumb vazia"><i class="bi bi-image"></i></span>
                    @endif

                    <div style="flex:1;min-width:0">
                        <strong>{{ $item->nome }}</strong>
                        <small class="bloco">
                            {{ $item->category->nome }} · {{ $item->supplier->nome }}
                            @foreach ($item->destaques() as $d) · {{ $d['valor'] }} @endforeach
                        </small>
                        @if ($item->desconto !== null)
                            <small class="bloco">
                                <span class="pill warn">desconto próprio
                                    {{ number_format($item->desconto, 2, ',', '.') }}%</span>
                            </small>
                        @endif
                        @if ($item->observacoes)
                            <small class="bloco obs">
                                <i class="bi bi-sticky"></i> {{ Str::limit($item->observacoes, 90) }}
                            </small>
                        @endif
                        @if ($item->variants->isNotEmpty())
                            <small class="bloco">
                                @foreach ($item->variants as $v)
                                    <span class="pill">{{ $v->nome }}</span>
                                @endforeach
                            </small>
                        @endif
                    </div>

                    <button type="button" class="btn-sm secondary" wire:click="editar({{ $item->id }})">
                        Editar
                    </button>
                </div>
            @empty
                <p class="vazio">Nenhum item ainda.</p>
            @endforelse
        </section>
    </div>
</div>
