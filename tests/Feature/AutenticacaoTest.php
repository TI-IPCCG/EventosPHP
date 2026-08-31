<?php

namespace Tests\Feature;

use App\Models\Church;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Login = identidade + vínculo. O e-mail e a senha dizem QUEM é a pessoa; o
 * vínculo ativo diz se ela pode entrar NAQUELA congregação.
 */
class AutenticacaoTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'mysql_modern_apps'];

    private function pessoa(bool $comVinculo = true, bool $ativo = true, bool $super = false): User
    {
        $user = User::create([
            'name' => 'Fulano', 'email' => 'f'.uniqid().'@ipccg.org.br',
            'password' => 'segredo123', 'is_super' => $super,
        ]);

        if ($comVinculo) {
            Membership::create([
                'user_id' => $user->id, 'church_id' => 1,
                'status' => $ativo, 'created_at' => now(),
            ]);
        }

        return $user;
    }

    public function test_raiz_manda_para_o_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_tela_de_login_lista_as_congregacoes_ativas(): void
    {
        Livewire::test('auth.login')
            ->assertOk()
            ->assertSee(Church::ativas()->first()->name);
    }

    public function test_entra_e_grava_a_congregacao_na_sessao(): void
    {
        $user = $this->pessoa();

        Livewire::test('auth.login')
            ->set('church_id', 1)
            ->set('email', $user->email)
            ->set('password', 'segredo123')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, session('church_id'));
        $this->assertNotNull(session('church_tz'));
    }

    public function test_senha_errada_nao_revela_se_o_email_existe(): void
    {
        $user = $this->pessoa();

        Livewire::test('auth.login')
            ->set('church_id', 1)->set('email', $user->email)->set('password', 'errada')
            ->call('authenticate')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    /** Sem vínculo ativo, não entra — mesmo com a senha certa. */
    public function test_sem_vinculo_ativo_nao_entra_na_congregacao(): void
    {
        $user = $this->pessoa(comVinculo: true, ativo: false);

        Livewire::test('auth.login')
            ->set('church_id', 1)->set('email', $user->email)->set('password', 'segredo123')
            ->call('authenticate')
            ->assertHasErrors('church_id');

        $this->assertGuest();
    }

    /** Super-admin entra em qualquer congregação ativa, mesmo sem vínculo. */
    public function test_super_admin_entra_sem_vinculo(): void
    {
        $super = $this->pessoa(comVinculo: false, super: true);

        Livewire::test('auth.login')
            ->set('church_id', 1)->set('email', $super->email)->set('password', 'segredo123')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($super);
    }
}
