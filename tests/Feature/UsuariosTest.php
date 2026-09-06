<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Permission;
use App\Models\SystemRole;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tela de Pessoas. O foco é o que protege o sistema: anti-lockout, quem pode
 * o quê, e a identidade global nunca ser duplicada nem sobrescrita.
 */
class UsuariosTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    private User $admin;
    private SystemRole $perfil;

    protected function setUp(): void
    {
        parent::setUp();
        session(['church_id' => 1]);

        foreach (array_keys(\Database\Seeders\PermissionSeeder::CATALOG) as $slug) {
            Permission::firstOrCreate(['slug' => $slug], ['description' => $slug]);
        }

        $this->perfil = SystemRole::create(['church_id' => 1, 'name' => 'Perfil '.uniqid()]);
        $this->perfil->permissions()->sync(Permission::pluck('id'));

        $this->admin = $this->pessoa('admin'.uniqid().'@ipccg.org.br', true, $this->perfil->id);
    }

    private function pessoa(string $email, bool $ativo, ?int $perfil = null, bool $super = false): User
    {
        $u = User::create(['name' => 'Pessoa '.uniqid(), 'email' => $email,
            'password' => 'segredo123', 'is_super' => $super]);

        Membership::create(['user_id' => $u->id, 'church_id' => 1,
            'system_role_id' => $perfil, 'status' => $ativo, 'created_at' => now()]);

        return $u;
    }

    public function test_pendente_aparece_na_fila_e_some_ao_ser_liberado(): void
    {
        $novato = $this->pessoa('novato'.uniqid().'@ipccg.org.br', false);

        $t = Livewire::actingAs($this->admin)->test('admin.usuarios')
            ->assertSee($novato->name);

        $t->set("perfilPendente.{$novato->id}", $this->perfil->id)
          ->call('liberar', $novato->id);

        $v = Membership::where('user_id', $novato->id)->where('church_id', 1)->first();
        $this->assertTrue((bool) $v->status);
        $this->assertSame($this->perfil->id, $v->system_role_id);
    }

    public function test_liberar_sem_perfil_nao_ativa(): void
    {
        $novato = $this->pessoa('semperfil'.uniqid().'@ipccg.org.br', false);

        Livewire::actingAs($this->admin)->test('admin.usuarios')
            ->call('liberar', $novato->id);

        $v = Membership::where('user_id', $novato->id)->where('church_id', 1)->first();
        $this->assertFalse((bool) $v->status, 'não pode ativar sem perfil escolhido');
    }

    public function test_recusar_remove_o_vinculo_mas_preserva_a_conta(): void
    {
        $novato = $this->pessoa('recusado'.uniqid().'@ipccg.org.br', false);

        Livewire::actingAs($this->admin)->test('admin.usuarios')
            ->call('recusar', $novato->id);

        $this->assertDatabaseMissing('memberships', ['user_id' => $novato->id, 'church_id' => 1]);
        $this->assertNotNull(User::find($novato->id), 'a identidade não pode ser apagada');
    }

    public function test_admin_nao_consegue_desativar_o_proprio_acesso(): void
    {
        Livewire::actingAs($this->admin)->test('admin.usuarios')
            ->call('editar', $this->admin->id)
            ->set('ativo', false)
            ->call('salvar')
            ->assertHasErrors('ativo');

        $v = Membership::where('user_id', $this->admin->id)->where('church_id', 1)->first();
        $this->assertTrue((bool) $v->status, 'o admin se trancou para fora');
    }

    public function test_super_nao_consegue_remover_o_proprio_super(): void
    {
        $super = $this->pessoa('super'.uniqid().'@ipccg.org.br', true, $this->perfil->id, true);

        Livewire::actingAs($super)->test('admin.usuarios')
            ->call('editar', $super->id)
            ->set('is_super', false)
            ->call('salvar')
            ->assertHasErrors('is_super');

        $this->assertTrue((bool) $super->fresh()->is_super);
    }

    public function test_cadastrar_email_existente_vincula_sem_tocar_na_senha(): void
    {
        // A pessoa já serve em outra congregação: identidade é global.
        $outra = User::create(['name' => 'De Outra', 'email' => 'outra'.uniqid().'@ipccg.org.br',
            'password' => 'senhaDela123']);

        Livewire::actingAs($this->admin)->test('admin.usuarios')
            ->call('novo')
            ->set('nome', 'Nome Diferente')->set('email', $outra->email)
            ->set('senha', 'senhaNova999')->set('system_role_id', $this->perfil->id)
            ->call('salvar')->assertHasNoErrors();

        $outra->refresh();
        $this->assertTrue(Hash::check('senhaDela123', $outra->password), 'a senha dela foi trocada!');
        $this->assertSame('De Outra', $outra->name, 'o nome dela foi sobrescrito!');
        $this->assertSame(1, User::where('email', $outra->email)->count(), 'duplicou a identidade');
        $this->assertDatabaseHas('memberships', ['user_id' => $outra->id, 'church_id' => 1, 'status' => 1]);
    }

    public function test_cadastrar_alguem_que_ja_esta_na_congregacao_e_recusado(): void
    {
        $ja = $this->pessoa('ja'.uniqid().'@ipccg.org.br', true, $this->perfil->id);

        Livewire::actingAs($this->admin)->test('admin.usuarios')
            ->call('novo')
            ->set('nome', 'Repetido')->set('email', $ja->email)->set('senha', 'segredo123')
            ->call('salvar')->assertHasErrors('email');
    }

    public function test_cadastro_novo_exige_senha(): void
    {
        Livewire::actingAs($this->admin)->test('admin.usuarios')
            ->call('novo')
            ->set('nome', 'Sem Senha')->set('email', 'sem'.uniqid().'@ipccg.org.br')
            ->call('salvar')->assertHasErrors('senha');
    }

    public function test_edicao_com_senha_vazia_nao_altera_a_senha(): void
    {
        $alvo = $this->pessoa('alvo'.uniqid().'@ipccg.org.br', true, $this->perfil->id);

        Livewire::actingAs($this->admin)->test('admin.usuarios')
            ->call('editar', $alvo->id)
            ->set('nome', 'Nome Novo')
            ->set('senha', '')
            ->call('salvar')->assertHasNoErrors();

        $alvo->refresh();
        $this->assertSame('Nome Novo', $alvo->name);
        $this->assertTrue(Hash::check('segredo123', $alvo->password));
    }

    public function test_quem_nao_pode_gerenciar_nao_libera_ninguem(): void
    {
        $soVer = SystemRole::create(['church_id' => 1, 'name' => 'So Ver '.uniqid()]);
        $soVer->permissions()->sync(Permission::whereIn('slug', ['usuarios.ver'])->pluck('id'));
        $curioso = $this->pessoa('curioso'.uniqid().'@ipccg.org.br', true, $soVer->id);

        $novato = $this->pessoa('novato2'.uniqid().'@ipccg.org.br', false);

        Livewire::actingAs($curioso)->test('admin.usuarios')
            ->set("perfilPendente.{$novato->id}", $this->perfil->id)
            ->call('liberar', $novato->id)
            ->assertForbidden();

        $this->assertFalse((bool) Membership::where('user_id', $novato->id)->first()->status);
    }

    public function test_sem_permissao_nenhuma_a_tela_e_403(): void
    {
        $semNada = SystemRole::create(['church_id' => 1, 'name' => 'Nada '.uniqid()]);
        $zé = $this->pessoa('ze'.uniqid().'@ipccg.org.br', true, $semNada->id);

        $this->actingAs($zé)->get(route('usuarios'))->assertForbidden();
    }

    /**
     * A versão anterior deste teste só checava o título "Pessoas" — passava com
     * a lista vazia, que foi exatamente o defeito que escapou para produção.
     */
    public function test_a_lista_mostra_quem_tem_vinculo_ativo(): void
    {
        $colega = $this->pessoa('colega'.uniqid().'@ipccg.org.br', true, $this->perfil->id);
        $inativo = $this->pessoa('inativo'.uniqid().'@ipccg.org.br', false);

        $this->actingAs($this->admin)->get(route('usuarios'))
            ->assertOk()
            ->assertSee($this->admin->name)      // o próprio admin aparece
            ->assertSee($colega->name)
            ->assertSee($inativo->name);         // na fila, não na lista

        $this->assertTrue(true);
    }

    public function test_existe_o_perfil_administrador_com_todas_as_permissoes(): void
    {
        $this->seed(\Database\Seeders\PerfilSeeder::class);

        $admin = SystemRole::withoutGlobalScopes()
            ->where('church_id', 1)->where('name', 'Administrador')->first();

        $this->assertNotNull($admin, 'o perfil Administrador não existe');
        $this->assertSame(
            Permission::count(),
            $admin->permissions()->count(),
            'o Administrador precisa ter TODAS as permissões',
        );
    }

    public function test_coordenador_consegue_liberar_pessoas(): void
    {
        // Sem usuarios.gerenciar ele veria a fila sem conseguir agir.
        $this->seed(\Database\Seeders\PerfilSeeder::class);

        $coord = SystemRole::withoutGlobalScopes()
            ->where('church_id', 1)->where('name', 'Coordenador da Livraria')->first();

        $this->assertTrue(
            $coord->permissions->contains('slug', 'usuarios.gerenciar'),
            'o coordenador precisa poder liberar quem se cadastrou',
        );
    }

    public function test_super_em_congregacao_sem_vinculo_e_avisado(): void
    {
        $super = $this->pessoa('forasteiro'.uniqid().'@ipccg.org.br', true, $this->perfil->id, true);

        // Entra numa congregação onde ele NÃO tem vínculo.
        session(['church_id' => 2]);

        Livewire::actingAs($super)->test('admin.usuarios')
            ->assertSet('busca', '')
            ->assertSee('Você está vendo outra congregação');
    }

    public function test_desativado_continua_na_lista_e_pode_ser_reativado(): void
    {
        // O defeito que este teste barra: filtrar a lista por status=true faz
        // quem é desativado sumir da tela, sem nenhum caminho para reativar.
        $alvo = $this->pessoa('sumico'.uniqid().'@ipccg.org.br', true, $this->perfil->id);

        Livewire::actingAs($this->admin)->test('admin.usuarios')
            ->call('alternarAtivo', $alvo->id);

        $this->assertFalse((bool) Membership::where('user_id', $alvo->id)->first()->status);

        // Continua visível, agora marcado como inativo...
        $this->actingAs($this->admin)->get(route('usuarios'))
            ->assertOk()->assertSee($alvo->name);

        // ...e volta com o mesmo botão.
        Livewire::actingAs($this->admin)->test('admin.usuarios')
            ->call('alternarAtivo', $alvo->id);

        $this->assertTrue((bool) Membership::where('user_id', $alvo->id)->first()->status);
    }

    public function test_desativado_nao_volta_para_a_fila_de_pendentes(): void
    {
        $alvo = $this->pessoa('desat'.uniqid().'@ipccg.org.br', true, $this->perfil->id);

        Livewire::actingAs($this->admin)->test('admin.usuarios')
            ->call('alternarAtivo', $alvo->id);

        $pendentes = Livewire::actingAs($this->admin)->test('admin.usuarios')
            ->instance()->pendentes;

        $this->assertFalse(
            $pendentes->contains('id', $alvo->id),
            'quem foi desativado não é um pedido novo — não pertence à fila',
        );
    }

    public function test_tela_renderiza_para_quem_pode(): void
    {
        $this->actingAs($this->admin)->get(route('usuarios'))
            ->assertOk()->assertSee('Pessoas');
    }
}
