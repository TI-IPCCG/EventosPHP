<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Auto-cadastro. O que estes testes travam não é o formulário funcionar — é
 * ele NÃO virar porta dos fundos: vínculo sempre pendente, e conta existente
 * jamais alterada.
 */
class CadastroTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('cadastro:127.0.0.1');
    }

    private function email(): string
    {
        return 'novo'.uniqid().'@ipccg.org.br';
    }

    public function test_cadastro_cria_usuario_com_vinculo_pendente(): void
    {
        $email = $this->email();

        Livewire::test('auth.cadastro')
            ->set('church_id', 1)->set('name', 'Voluntário Novo')
            ->set('email', $email)->set('telefone', '(67) 90000-0000')
            ->set('password', 'segredo123')->set('password_confirmation', 'segredo123')
            ->call('cadastrar')
            ->assertHasNoErrors()
            ->assertSet('enviado', true);

        $user = User::where('email', $email)->first();
        $this->assertNotNull($user, 'usuário deveria existir');
        $this->assertSame('(67) 90000-0000', $user->telefone);
        $this->assertTrue(Hash::check('segredo123', $user->password));

        $vinculo = Membership::where('user_id', $user->id)->where('church_id', 1)->first();
        $this->assertNotNull($vinculo, 'vínculo deveria existir');
        $this->assertFalse((bool) $vinculo->status, 'o vínculo NÃO pode nascer ativo');
        $this->assertNull($vinculo->system_role_id, 'não pode nascer com perfil');
    }

    public function test_quem_se_cadastra_nao_consegue_entrar_antes_de_liberado(): void
    {
        $email = $this->email();

        Livewire::test('auth.cadastro')
            ->set('church_id', 1)->set('name', 'Ainda Pendente')
            ->set('email', $email)
            ->set('password', 'segredo123')->set('password_confirmation', 'segredo123')
            ->call('cadastrar')->assertHasNoErrors();

        Livewire::test('auth.login')
            ->set('church_id', 1)->set('email', $email)->set('password', 'segredo123')
            ->call('authenticate')
            ->assertHasErrors('church_id');          // "sem acesso ativo"

        $this->assertGuest();
    }

    public function test_cadastro_nao_altera_a_senha_de_conta_existente(): void
    {
        // O ataque que este teste barra: cadastrar-se com o e-mail de outra
        // pessoa para sobrescrever a senha dela.
        $alvo = User::create([
            'name' => 'Coordenador', 'email' => $this->email(), 'password' => 'senhaOriginal1',
        ]);

        Livewire::test('auth.cadastro')
            ->set('church_id', 1)->set('name', 'Impostor')
            ->set('email', $alvo->email)
            ->set('password', 'senhaDoAtacante')->set('password_confirmation', 'senhaDoAtacante')
            ->call('cadastrar')
            ->assertHasNoErrors()
            ->assertSet('enviado', true);            // resposta idêntica: não revela nada

        $alvo->refresh();
        $this->assertTrue(Hash::check('senhaOriginal1', $alvo->password), 'a senha foi trocada!');
        $this->assertFalse(Hash::check('senhaDoAtacante', $alvo->password));
        $this->assertSame('Coordenador', $alvo->name, 'o nome foi sobrescrito!');
    }

    public function test_cadastro_nao_reativa_vinculo_desativado(): void
    {
        $user = User::create(['name' => 'Desativado', 'email' => $this->email(), 'password' => 'segredo123']);
        $vinculo = Membership::create([
            'user_id' => $user->id, 'church_id' => 1,
            'system_role_id' => null, 'status' => false, 'created_at' => now(),
        ]);

        Livewire::test('auth.cadastro')
            ->set('church_id', 1)->set('name', 'Desativado')
            ->set('email', $user->email)
            ->set('password', 'outraSenha123')->set('password_confirmation', 'outraSenha123')
            ->call('cadastrar')->assertHasNoErrors();

        $this->assertFalse((bool) $vinculo->fresh()->status);
        $this->assertSame(1, Membership::where('user_id', $user->id)->where('church_id', 1)->count());
    }

    public function test_senha_curta_e_confirmacao_divergente_sao_recusadas(): void
    {
        Livewire::test('auth.cadastro')
            ->set('church_id', 1)->set('name', 'Fulano')->set('email', $this->email())
            ->set('password', 'curta')->set('password_confirmation', 'curta')
            ->call('cadastrar')->assertHasErrors('password');

        Livewire::test('auth.cadastro')
            ->set('church_id', 1)->set('name', 'Fulano')->set('email', $this->email())
            ->set('password', 'segredo123')->set('password_confirmation', 'outraCoisa')
            ->call('cadastrar')->assertHasErrors('password');
    }

    public function test_congregacao_inexistente_e_recusada(): void
    {
        Livewire::test('auth.cadastro')
            ->set('church_id', 99999)->set('name', 'Fulano')->set('email', $this->email())
            ->set('password', 'segredo123')->set('password_confirmation', 'segredo123')
            ->call('cadastrar')->assertHasErrors('church_id');
    }

    public function test_rate_limit_trava_spam_do_mesmo_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Livewire::test('auth.cadastro')
                ->set('church_id', 1)->set('name', "Fulano $i")->set('email', $this->email())
                ->set('password', 'segredo123')->set('password_confirmation', 'segredo123')
                ->call('cadastrar')->assertHasNoErrors();
        }

        Livewire::test('auth.cadastro')
            ->set('church_id', 1)->set('name', 'Sexto')->set('email', $this->email())
            ->set('password', 'segredo123')->set('password_confirmation', 'segredo123')
            ->call('cadastrar')->assertHasErrors('email');
    }

    public function test_tela_de_cadastro_renderiza_e_o_login_aponta_para_ela(): void
    {
        $this->get(route('registrar'))->assertOk()->assertSee('Criar conta');
        $this->get(route('login'))->assertOk()->assertSee(route('registrar'), escape: false);
    }
}
