<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Permission;
use App\Models\SystemRole;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Perfis e acessos. perfis.gerenciar é a chave mestra do sistema, então o que
 * estes testes protegem é ninguém conseguir fechar a porta por dentro.
 */
class PerfisTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    private User $admin;
    private SystemRole $perfilDoAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        session(['church_id' => 1]);

        foreach (array_keys(\Database\Seeders\PermissionSeeder::CATALOG) as $slug) {
            Permission::firstOrCreate(['slug' => $slug], ['description' => $slug]);
        }

        $this->perfilDoAdmin = SystemRole::create(['church_id' => 1, 'name' => 'Admin '.uniqid()]);
        $this->perfilDoAdmin->permissions()->sync(Permission::pluck('id'));

        $this->admin = User::create(['name' => 'Admin', 'email' => 'p'.uniqid().'@ipccg.org.br',
            'password' => 'segredo123']);

        Membership::create(['user_id' => $this->admin->id, 'church_id' => 1,
            'system_role_id' => $this->perfilDoAdmin->id, 'status' => true, 'created_at' => now()]);
    }

    public function test_cria_perfil_na_congregacao_da_sessao(): void
    {
        Livewire::actingAs($this->admin)->test('admin.perfis')
            ->set('nome', 'Conferente')
            ->call('criar')
            ->assertHasNoErrors();

        $novo = SystemRole::withoutGlobalScopes()->where('name', 'Conferente')->first();
        $this->assertNotNull($novo);
        $this->assertSame(1, (int) $novo->church_id, 'o perfil nasceu em outra congregação');
    }

    public function test_marcar_permissao_salva_na_hora(): void
    {
        $perfil = SystemRole::create(['church_id' => 1, 'name' => 'Vazio '.uniqid()]);
        $vender = Permission::where('slug', 'livraria.vender')->value('id');

        Livewire::actingAs($this->admin)->test('admin.perfis')
            ->call('selecionar', $perfil->id)
            ->set('concedidas', [$vender]);

        $this->assertTrue($perfil->fresh()->permissions->contains('slug', 'livraria.vender'));
    }

    public function test_nao_consigo_tirar_perfis_gerenciar_do_meu_proprio_perfil(): void
    {
        // Sem esta trava, o admin fecha a porta por dentro: a tela deixa de
        // abrir e não sobra caminho fora do banco.
        $chave = (int) Permission::where('slug', 'perfis.gerenciar')->value('id');
        $semAChave = Permission::where('slug', '!=', 'perfis.gerenciar')->pluck('id')
            ->map(fn ($i) => (int) $i)->all();

        Livewire::actingAs($this->admin)->test('admin.perfis')
            ->call('selecionar', $this->perfilDoAdmin->id)
            ->set('concedidas', $semAChave);

        $this->assertTrue(
            $this->perfilDoAdmin->fresh()->permissions->contains('id', $chave),
            'o admin conseguiu remover a própria chave mestra',
        );

        $this->assertTrue($this->admin->fresh()->can('perfis.gerenciar'));
    }

    public function test_posso_tirar_perfis_gerenciar_de_OUTRO_perfil(): void
    {
        $outro = SystemRole::create(['church_id' => 1, 'name' => 'Outro '.uniqid()]);
        $outro->permissions()->sync(Permission::pluck('id'));

        $semAChave = Permission::where('slug', '!=', 'perfis.gerenciar')->pluck('id')
            ->map(fn ($i) => (int) $i)->all();

        Livewire::actingAs($this->admin)->test('admin.perfis')
            ->call('selecionar', $outro->id)
            ->set('concedidas', $semAChave);

        $this->assertFalse($outro->fresh()->permissions->contains('slug', 'perfis.gerenciar'));
    }

    public function test_nao_consigo_apagar_o_meu_proprio_perfil(): void
    {
        Livewire::actingAs($this->admin)->test('admin.perfis')
            ->call('remover', $this->perfilDoAdmin->id);

        $this->assertNotNull(
            SystemRole::withoutGlobalScopes()->find($this->perfilDoAdmin->id),
            'o admin apagou o próprio perfil e perdeu o acesso',
        );
    }

    public function test_apagar_perfil_deixa_as_pessoas_sem_perfil_mas_nao_as_apaga(): void
    {
        $perfil = SystemRole::create(['church_id' => 1, 'name' => 'Temporario '.uniqid()]);
        $pessoa = User::create(['name' => 'Alguem', 'email' => 'x'.uniqid().'@ipccg.org.br',
            'password' => 'segredo123']);
        Membership::create(['user_id' => $pessoa->id, 'church_id' => 1,
            'system_role_id' => $perfil->id, 'status' => true, 'created_at' => now()]);

        Livewire::actingAs($this->admin)->test('admin.perfis')
            ->call('remover', $perfil->id);

        $this->assertNull(SystemRole::withoutGlobalScopes()->find($perfil->id));
        $this->assertNotNull(User::find($pessoa->id), 'a pessoa foi apagada junto');
        $this->assertNull(
            Membership::where('user_id', $pessoa->id)->value('system_role_id'),
            'o vínculo deveria ficar sem perfil (FK ON DELETE SET NULL)',
        );
    }

    public function test_renomear_perfil(): void
    {
        $perfil = SystemRole::create(['church_id' => 1, 'name' => 'Antigo '.uniqid()]);

        Livewire::actingAs($this->admin)->test('admin.perfis')
            ->call('editar', $perfil->id)
            ->set('editandoNome', 'Nome Novo')
            ->call('salvarNome')
            ->assertHasNoErrors();

        $this->assertSame('Nome Novo', $perfil->fresh()->name);
    }

    public function test_quem_nao_tem_perfis_gerenciar_leva_403(): void
    {
        $limitado = SystemRole::create(['church_id' => 1, 'name' => 'Limitado '.uniqid()]);
        $limitado->permissions()->sync(
            Permission::whereIn('slug', ['usuarios.ver', 'usuarios.gerenciar'])->pluck('id'),
        );

        $ze = User::create(['name' => 'Ze', 'email' => 'z'.uniqid().'@ipccg.org.br',
            'password' => 'segredo123']);
        Membership::create(['user_id' => $ze->id, 'church_id' => 1,
            'system_role_id' => $limitado->id, 'status' => true, 'created_at' => now()]);

        $this->actingAs($ze)->get(route('perfis'))->assertForbidden();
    }

    public function test_tela_renderiza_com_perfis_e_permissoes(): void
    {
        $this->actingAs($this->admin)->get(route('perfis'))
            ->assertOk()
            ->assertSee($this->perfilDoAdmin->name)
            ->assertSee('Livraria')                       // grupo de permissões
            ->assertSee('livraria.vender');               // slug listado
    }
}
