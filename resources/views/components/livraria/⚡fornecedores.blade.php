<?php

use App\Models\Livraria\Supplier;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Fornecedores. O `prefixo` é o que aparece na etiqueta de cada exemplar
 * (ECC001), então é curto, único na congregação e não muda depois que uma
 * remessa já usou — trocar renomearia códigos de livros que estão na caixa.
 */
new
#[Layout('components.layouts.admin')]
#[Title('Fornecedores — Eventos IPCCG')]
class extends Component {
    public ?int $editando = null;

    public string $nome = '';
    public string $prefixo = '';
    public string $condicao_padrao = 'consignado';
    public ?string $desconto_padrao = null;
    public string $contato = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('livraria.catalogo'), 403);
    }

    #[Computed]
    public function fornecedores()
    {
        return Supplier::withCount('products')->orderBy('nome')->get();
    }

    protected function regras(): array
    {
        return [
            'nome'            => ['required', 'string', 'max:150'],
            'prefixo'         => ['required', 'string', 'min:2', 'max:4', 'regex:/^[A-Z0-9]+$/'],
            'condicao_padrao' => ['required', 'in:consignado,firme'],
            'desconto_padrao' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'contato'         => ['nullable', 'string', 'max:150'],
        ];
    }

    public function novo(): void
    {
        $this->reset(['editando', 'nome', 'prefixo', 'contato', 'desconto_padrao']);
        $this->condicao_padrao = 'consignado';
        $this->resetErrorBag();
    }

    public function editar(int $id): void
    {
        $f = Supplier::findOrFail($id);

        $this->editando        = $f->id;
        $this->nome            = $f->nome;
        $this->prefixo         = $f->prefixo;
        $this->condicao_padrao = $f->condicao_padrao;
        $this->desconto_padrao = $f->desconto_padrao;
        $this->contato         = (string) $f->contato;
        $this->resetErrorBag();
    }

    public function salvar(): void
    {
        abort_unless(auth()->user()->can('livraria.catalogo'), 403);

        $this->prefixo = mb_strtoupper(trim($this->prefixo));
        $dados = $this->validate($this->regras());

        // O prefixo é único por congregação; sem esta checagem o erro sairia
        // como falha de banco, sem dizer o que fazer.
        $repetido = Supplier::where('prefixo', $this->prefixo)
            ->when($this->editando, fn ($q) => $q->whereKeyNot($this->editando))
            ->exists();

        if ($repetido) {
            $this->addError('prefixo', 'Já existe um fornecedor com este prefixo.');

            return;
        }

        $dados['desconto_padrao'] = $this->desconto_padrao === '' ? null : $this->desconto_padrao;

        if ($this->editando) {
            Supplier::findOrFail($this->editando)->update($dados);
            $this->dispatch('toast', tipo: 'ok', mensagem: "{$dados['nome']} atualizado.");
        } else {
            Supplier::create($dados + ['ativo' => true]);
            $this->dispatch('toast', tipo: 'ok',
                mensagem: "{$dados['nome']} cadastrado. Prefixo {$this->prefixo}.");
        }

        $this->novo();
        unset($this->fornecedores);
    }

    public function alternarAtivo(int $id): void
    {
        abort_unless(auth()->user()->can('livraria.catalogo'), 403);

        $f = Supplier::findOrFail($id);
        $f->update(['ativo' => ! $f->ativo]);
        unset($this->fornecedores);

        $this->dispatch('toast', tipo: $f->ativo ? 'ok' : 'info',
            mensagem: $f->nome.($f->ativo
                ? ' reativado.'
                : ' desativado — some dos seletores, mas o histórico fica.'));
    }
}; ?>

<div class="cadastro">
    <header class="painel-header">
        <div>
            <h1>Fornecedores</h1>
            <p class="subtitulo">Editoras e confecções que mandam itens para os eventos.</p>
        </div>
    </header>

    @if (session('ok'))
        <div class="alert ok" role="alert"><i class="bi bi-check-circle"></i> {{ session('ok') }}</div>
    @endif

    <div class="cadastro-grid">
        {{-- ── formulário ── --}}
        <section class="card">
            <h2 class="card-titulo">
                {{ $editando ? 'Editar fornecedor' : 'Novo fornecedor' }}
            </h2>

            <form class="form" wire:submit="salvar">
                <div>
                    <label for="f-nome">Nome</label>
                    <input id="f-nome" type="text" wire:model="nome" maxlength="150" required>
                    @error('nome') <span class="field-error">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="f-prefixo">Prefixo do código</label>
                    <input id="f-prefixo" type="text" wire:model="prefixo" maxlength="4"
                           style="text-transform:uppercase" placeholder="ECC">
                    <small class="ajuda">
                        Aparece na etiqueta de cada exemplar: <strong>{{ $prefixo ?: 'ECC' }}001</strong>.
                        Evite trocar depois que já houver remessa.
                    </small>
                    @error('prefixo') <span class="field-error">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="f-condicao">Condição comercial</label>
                    <select id="f-condicao" wire:model="condicao_padrao">
                        <option value="consignado">Consignado — o que não vende volta</option>
                        <option value="firme">Compra firme — não devolve</option>
                    </select>
                    <small class="ajuda">
                        No consignado, só se paga o que vendeu ou saiu por baixa.
                    </small>
                    @error('condicao_padrao') <span class="field-error">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="f-desconto">Desconto padrão (%)</label>
                    <input id="f-desconto" type="number" step="0.01" min="0" max="100"
                           wire:model="desconto_padrao" placeholder="40">
                    <small class="ajuda">Opcional — deixe vazio se varia por item.</small>
                    @error('desconto_padrao') <span class="field-error">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="f-contato">Contato</label>
                    <input id="f-contato" type="text" wire:model="contato" maxlength="150"
                           placeholder="Telefone ou e-mail">
                    @error('contato') <span class="field-error">{{ $message }}</span> @enderror
                </div>

                <div class="form-acoes">
                    @if ($editando)
                        <button type="button" class="btn-sm secondary" wire:click="novo">Cancelar</button>
                    @endif
                    <button type="submit" wire:loading.attr="disabled" wire:target="salvar">
                        {{ $editando ? 'Salvar' : 'Cadastrar' }}
                    </button>
                </div>
            </form>
        </section>

        {{-- ── lista ── --}}
        <section class="card">
            <h2 class="card-titulo">Cadastrados</h2>

            @forelse ($this->fornecedores as $f)
                <div class="linha-registro {{ $f->ativo ? '' : 'inativo' }}">
                    <div>
                        <strong>{{ $f->nome }}</strong>
                        <small class="bloco">
                            <span class="pill">{{ $f->prefixo }}</span>
                            {{ $f->condicao_padrao === 'consignado' ? 'Consignado' : 'Compra firme' }}
                            @if ($f->desconto_padrao) · {{ rtrim(rtrim(number_format($f->desconto_padrao, 2, ',', '.'), '0'), ',') }}% @endif
                            · {{ $f->products_count }} {{ $f->products_count == 1 ? 'item' : 'itens' }}
                        </small>
                    </div>
                    <div class="card-acoes">
                        <button type="button" class="btn-sm secondary" wire:click="editar({{ $f->id }})">
                            Editar
                        </button>
                        <button type="button" class="btn-sm btn-ghost" wire:click="alternarAtivo({{ $f->id }})"
                                @if ($f->ativo)
                                    wire:confirm="Desativar {{ $f->nome }}? Ele sai dos seletores de item e de remessa. O histórico não muda."
                                @endif>
                            {{ $f->ativo ? 'Desativar' : 'Reativar' }}
                        </button>
                    </div>
                </div>
            @empty
                <p class="vazio">Nenhum fornecedor ainda. Cadastre o primeiro ao lado.</p>
            @endforelse
        </section>
    </div>
</div>
