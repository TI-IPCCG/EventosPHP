<?php

use App\Models\Membership;
use App\Models\Permission;
use App\Models\SystemRole;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Perfis de acesso e o que cada um pode fazer.
 *
 * A matriz salva a cada clique — confirmar permissão a permissão seria um
 * formulário de trinta botões.
 *
 * `perfis.gerenciar` é a chave mestra: quem a tem pode dar a si mesmo qualquer
 * acesso. Por isso as duas travas daqui protegem justamente ela — tirar essa
 * permissão do próprio perfil, ou apagar o próprio perfil, deixaria a
 * congregação sem ninguém capaz de configurar acesso, e só o banco resolveria.
 */
new
#[Layout('components.layouts.admin')]
#[Title('Perfis e acessos — Eventos IPCCG')]
class extends Component {
    public string $nome = '';
    public ?int $editandoId = null;
    public string $editandoNome = '';
    public ?int $selecionadoId = null;
    public array $concedidas = [];

    /** Rótulo por prefixo do slug. Módulo novo entra como um prefixo novo. */
    private const GRUPOS = [
        'usuarios' => 'Pessoas',
        'perfis'   => 'Perfis e acessos',
        'eventos'  => 'Eventos',
        'livraria' => 'Livraria',
    ];

    public function mount(): void
    {
        abort_unless(auth()->user()->can('perfis.gerenciar'), 403);

        // Abre já com um perfil escolhido: a coluna da direita vazia não conta
        // o que esta tela faz, e o primeiro clique seria sempre o mesmo.
        if ($primeiro = SystemRole::orderBy('name')->first()) {
            $this->selecionar($primeiro->id);
        }
    }

    /** Defesa em profundidade: a rota já tem gate, cada escrita revalida. */
    private function guard(): void
    {
        abort_unless(auth()->user()?->can('perfis.gerenciar'), 403);
    }

    /** O perfil que o próprio usuário usa nesta congregação. */
    #[Computed]
    public function meuPerfilId(): ?int
    {
        return Membership::where('user_id', auth()->id())
            ->where('church_id', session('church_id'))
            ->value('system_role_id');
    }

    #[Computed]
    public function perfis()
    {
        return SystemRole::withCount('memberships')->orderBy('name')->get();
    }

    #[Computed]
    public function grupos()
    {
        return Permission::orderBy('slug')->get()
            ->groupBy(fn ($p) => explode('.', $p->slug)[0]);
    }

    public function rotulo(string $area): string
    {
        return self::GRUPOS[$area] ?? ucfirst($area);
    }

    public function criar(): void
    {
        $this->guard();

        $dados = $this->validate(
            ['nome' => ['required', 'string', 'max:50']],
            ['nome.required' => 'Informe o nome do perfil.'],
        );

        // church_id vem da sessão pelo BelongsToChurch: perfil nasce sempre na
        // congregação de quem está criando, nunca em outra.
        $perfil = SystemRole::create(['name' => $dados['nome']]);

        $this->nome = '';
        unset($this->perfis);
        $this->selecionar($perfil->id);

        $this->dispatch('toast', tipo: 'ok',
            mensagem: 'Perfil criado. Marque abaixo o que ele pode fazer.');
    }

    public function editar(int $id): void
    {
        $perfil = SystemRole::findOrFail($id);
        $this->editandoId = $perfil->id;
        $this->editandoNome = $perfil->name;
    }

    public function salvarNome(): void
    {
        $this->guard();

        $this->validate(
            ['editandoNome' => ['required', 'string', 'max:50']],
            ['editandoNome.required' => 'Informe o nome do perfil.'],
        );

        SystemRole::findOrFail($this->editandoId)->update(['name' => $this->editandoNome]);

        $this->cancelarEdicao();
        unset($this->perfis);
        $this->dispatch('toast', tipo: 'ok', mensagem: 'Nome atualizado.');
    }

    public function cancelarEdicao(): void
    {
        $this->editandoId = null;
        $this->editandoNome = '';
    }

    public function remover(int $id): void
    {
        $this->guard();

        if ($id === $this->meuPerfilId) {
            $this->dispatch('toast', tipo: 'erro', titulo: 'Não dá',
                mensagem: 'Este é o seu próprio perfil. Apagá-lo tiraria o seu acesso a esta tela.');

            return;
        }

        $perfil = SystemRole::findOrFail($id);
        $nome = $perfil->name;
        $perfil->delete();   // a FK é ON DELETE SET NULL: ninguém é apagado junto

        if ($this->selecionadoId === $id) {
            $this->selecionadoId = null;
            $this->concedidas = [];
        }

        unset($this->perfis);
        $this->dispatch('toast', tipo: 'info',
            mensagem: 'Perfil "'.$nome.'" removido. Quem o usava ficou sem perfil — dê outro em Pessoas.');
    }

    public function selecionar(int $id): void
    {
        $perfil = SystemRole::with('permissions')->findOrFail($id);
        $this->selecionadoId = $perfil->id;
        $this->concedidas = $perfil->permissions->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /** A matriz grava a cada toque. */
    public function updatedConcedidas(): void
    {
        $this->guard();

        if (! $this->selecionadoId) {
            return;
        }

        // Anti-lockout: sem esta trava, desmarcar "perfis.gerenciar" no próprio
        // perfil fecha a porta por dentro — a tela deixa de abrir e não sobra
        // como reverter senão pelo banco.
        if ($this->selecionadoId === $this->meuPerfilId) {
            $chave = Permission::where('slug', 'perfis.gerenciar')->value('id');

            if ($chave && ! in_array((int) $chave, array_map('intval', $this->concedidas), true)) {
                $this->concedidas[] = (int) $chave;

                $this->dispatch('toast', tipo: 'aviso', titulo: 'Mantido',
                    mensagem: 'Você não pode tirar "gerenciar perfis" do seu próprio perfil — perderia esta tela.');

                return;
            }
        }

        SystemRole::findOrFail($this->selecionadoId)->permissions()->sync($this->concedidas);
    }
}; ?>

<div class="cadastro">
    <header class="painel-header">
        <div>
            <h1>Perfis e acessos</h1>
            <p class="subtitulo">O perfil decide o que cada pessoa enxerga e pode fazer.</p>
        </div>
    </header>

    <div class="cadastro-grid">
        {{-- ── perfis ── --}}
        <section class="card">
            <h2 class="card-titulo">
                Perfis <span class="pill">{{ $this->perfis->count() }}</span>
            </h2>

            <form class="form" wire:submit="criar" style="margin-bottom:14px">
                <div>
                    <label for="p-nome">Novo perfil</label>
                    <input id="p-nome" type="text" wire:model="nome" maxlength="50"
                           placeholder="Ex.: Conferente de Remessa">
                    @error('nome') <span class="field-error">{{ $message }}</span> @enderror
                </div>
                <button type="submit" class="btn-sm" wire:loading.attr="disabled" wire:target="criar">
                    Adicionar
                </button>
            </form>

            @forelse ($this->perfis as $perfil)
                <div class="linha-registro" wire:key="perfil-{{ $perfil->id }}">
                    @if ($editandoId === $perfil->id)
                        <input type="text" wire:model="editandoNome" maxlength="50"
                               wire:keydown.enter="salvarNome" style="flex:1">
                        <div class="card-acoes">
                            <button type="button" class="btn-sm" wire:click="salvarNome">Salvar</button>
                            <button type="button" class="btn-sm secondary" wire:click="cancelarEdicao">Cancelar</button>
                        </div>
                    @else
                        <div>
                            <button type="button" class="btn-ghost btn-sm" style="text-align:left"
                                    wire:click="selecionar({{ $perfil->id }})">
                                <strong>{{ $perfil->name }}</strong>
                            </button>
                            @if ($perfil->id === $this->meuPerfilId)
                                <span class="pill">o seu</span>
                            @endif
                            <small class="bloco">
                                {{ $perfil->memberships_count }}
                                {{ $perfil->memberships_count == 1 ? 'pessoa' : 'pessoas' }}
                                @if ($selecionadoId === $perfil->id) · <strong>editando</strong> @endif
                            </small>
                        </div>
                        <div class="card-acoes">
                            <button type="button" class="btn-sm secondary" wire:click="editar({{ $perfil->id }})">
                                Renomear
                            </button>
                            @if ($perfil->id !== $this->meuPerfilId)
                                <button type="button" class="btn-sm btn-danger"
                                        wire:click="remover({{ $perfil->id }})"
                                        wire:confirm="Remover o perfil &quot;{{ $perfil->name }}&quot;?@if ($perfil->memberships_count) As {{ $perfil->memberships_count }} pessoas que o usam ficam SEM perfil e param de enxergar as telas até receberem outro.@endif">
                                    Remover
                                </button>
                            @endif
                        </div>
                    @endif
                </div>
            @empty
                <p class="vazio">Nenhum perfil ainda. Crie o primeiro acima.</p>
            @endforelse
        </section>

        {{-- ── matriz de permissões ── --}}
        <section class="card">
            <h2 class="card-titulo">
                @if ($selecionadoId)
                    O que "{{ $this->perfis->firstWhere('id', $selecionadoId)?->name }}" pode fazer
                @else
                    Permissões
                @endif
            </h2>

            @if (! $selecionadoId)
                <p class="vazio">Escolha um perfil ao lado para editar as permissões.</p>
            @else
                <p class="ajuda" style="margin-top:0">
                    Marque o que este perfil pode fazer. <strong>Salva sozinho</strong> a cada toque.
                </p>

                @foreach ($this->grupos as $area => $permissoes)
                    <div class="box" wire:key="area-{{ $area }}" style="margin-bottom:10px">
                        <div class="stat-label" style="margin-bottom:8px">{{ $this->rotulo($area) }}</div>

                        @foreach ($permissoes as $perm)
                            <label wire:key="perm-{{ $perm->id }}"
                                   style="display:flex;gap:10px;align-items:flex-start;padding:6px 0;cursor:pointer">
                                <input type="checkbox" wire:model.live="concedidas" value="{{ $perm->id }}"
                                       wire:loading.attr="disabled" wire:target="updatedConcedidas">
                                <span>
                                    {{ $perm->description }}
                                    <code style="display:block;font-size:.75rem;color:var(--muted-fg)">{{ $perm->slug }}</code>
                                </span>
                            </label>
                        @endforeach
                    </div>
                @endforeach
            @endif
        </section>
    </div>
</div>
