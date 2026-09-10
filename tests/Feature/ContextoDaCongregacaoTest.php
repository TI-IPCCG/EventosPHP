<?php

namespace Tests\Feature;

use App\Models\Church;
use App\Models\Event;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\SystemRole;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Reidratação do contexto da congregação.
 *
 * O que estes testes travam: a sessão perde o church_id (ela expira em 2h e o
 * cookie remember re-autentica numa sessão vazia), e a partir daí o ChurchScope
 * filtra por NULL — toda tela diz "nenhum evento", sem erro nenhum. Na portaria
 * de um evento, com fila, isso é uma parada.
 */
class ContextoDaCongregacaoTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    private SystemRole $perfil;

    protected function setUp(): void
    {
        parent::setUp();
        session(['church_id' => 1]);

        foreach (array_keys(\Database\Seeders\PermissionSeeder::CATALOG) as $slug) {
            Permission::firstOrCreate(['slug' => $slug], ['description' => $slug]);
        }

        $this->perfil = SystemRole::create(['church_id' => 1, 'name' => 'Ctx '.uniqid()]);
        $this->perfil->permissions()->sync(Permission::pluck('id'));

        Event::firstOrCreate(
            ['church_id' => 1, 'nome' => 'Evento do contexto'],
            ['inicio' => today(), 'status' => 'em_andamento'],
        );
    }

    private function pessoa(array $igrejas, bool $super = false): User
    {
        $u = User::create(['name' => 'Voluntário', 'email' => 'c'.uniqid().'@ipccg.org.br',
            'password' => 'segredo123', 'is_super' => $super]);

        foreach ($igrejas as $churchId) {
            Membership::create(['user_id' => $u->id, 'church_id' => $churchId,
                'system_role_id' => $this->perfil->id, 'status' => true, 'created_at' => now()]);
        }

        return $u;
    }

    public function test_um_vinculo_so_reidrata_a_sessao_em_vez_de_mostrar_tela_vazia(): void
    {
        $voluntario = $this->pessoa([1]);

        // Simula o que o cookie remember faz: autenticado, sessão sem church_id.
        $this->actingAs($voluntario);
        session()->forget(['church_id', 'church_tz']);

        $this->get(route('painel'))->assertOk();

        $this->assertSame(1, session('church_id'), 'a sessão não foi reidratada');
        $this->assertNotNull(session('church_tz'), 'o fuso da congregação não foi resolvido');
    }

    public function test_reidratacao_traz_o_fuso_da_congregacao(): void
    {
        $voluntario = $this->pessoa([1]);
        $fusoEsperado = Church::whereKey(1)->value('timezone') ?: 'America/Sao_Paulo';

        $this->actingAs($voluntario);
        session()->forget(['church_id', 'church_tz']);

        $this->get(route('painel'))->assertOk();

        $this->assertSame($fusoEsperado, session('church_tz'));
    }

    public function test_dois_vinculos_manda_escolher_no_login_preservando_o_destino(): void
    {
        // Com mais de uma congregação, só a pessoa sabe onde quer entrar —
        // adivinhar gravaria dado no evento da igreja errada.
        $coord = $this->pessoa([1, 2]);

        $this->actingAs($coord);
        session()->forget(['church_id', 'church_tz']);

        $this->get(route('eventos'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('aviso_login');

        $this->assertGuest();
        $this->assertSame(route('eventos'), session('url.intended'),
            'a intenção deveria sobreviver ao invalidate da sessão');
    }

    public function test_super_admin_sem_vinculo_tambem_escolhe(): void
    {
        $super = $this->pessoa([], true);

        $this->actingAs($super);
        session()->forget(['church_id', 'church_tz']);

        $this->get(route('painel'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_cadeia_de_redirect_termina_no_login_sem_loop(): void
    {
        // O middleware roda em toda request web, inclusive nas de auth. Se ele
        // mexesse na sessão do login, o app entraria em loop e não abriria
        // mais. Seguindo os redirects até o fim, a cadeia tem de PARAR numa
        // tela utilizável — é isso que prova a ausência do loop.
        $coord = $this->pessoa([1, 2]);

        $this->actingAs($coord);
        session()->forget(['church_id', 'church_tz']);

        $this->followingRedirects()
            ->get(route('painel'))
            ->assertOk()
            ->assertSee('Congregação');       // parou no login, com o seletor

        $this->assertGuest();
    }

    public function test_telas_de_auth_abrem_para_visitante_sem_interferencia(): void
    {
        $this->get(route('login'))->assertOk();
        $this->get(route('registrar'))->assertOk();
        $this->get(route('password.request'))->assertOk();
    }

    public function test_visitante_nao_e_afetado(): void
    {
        $this->get(route('login'))->assertOk();
        $this->assertGuest();
    }

    public function test_sessao_com_church_id_nao_e_tocada(): void
    {
        $voluntario = $this->pessoa([1, 2]);   // dois vínculos, mas já escolheu

        $this->actingAs($voluntario);
        session(['church_id' => 2, 'church_tz' => 'America/Sao_Paulo']);

        $this->get(route('painel'))->assertOk();

        $this->assertSame(2, session('church_id'), 'a escolha da pessoa foi sobrescrita');
        $this->assertAuthenticated();
    }

    public function test_vinculo_inativo_nao_reidrata(): void
    {
        $u = User::create(['name' => 'Desativado', 'email' => 'd'.uniqid().'@ipccg.org.br',
            'password' => 'segredo123']);
        Membership::create(['user_id' => $u->id, 'church_id' => 1,
            'system_role_id' => $this->perfil->id, 'status' => false, 'created_at' => now()]);

        $this->actingAs($u);
        session()->forget(['church_id', 'church_tz']);

        $this->get(route('painel'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
