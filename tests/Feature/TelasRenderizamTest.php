<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\SystemRole;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Renderização de PÁGINA INTEIRA — componente + layout + assets.
 *
 * Os testes de componente (Livewire::test) não exercitam o layout, então um
 * erro no admin.blade.php passaria despercebido até alguém abrir no navegador.
 */
class TelasRenderizamTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    private User $coordenador;

    protected function setUp(): void
    {
        parent::setUp();
        session(['church_id' => 1]);

        foreach (array_keys(\Database\Seeders\PermissionSeeder::CATALOG) as $slug) {
            Permission::firstOrCreate(['slug' => $slug], ['description' => $slug]);
        }

        $perfil = SystemRole::create(['church_id' => 1, 'name' => 'Coordenador '.uniqid()]);
        $perfil->permissions()->sync(Permission::pluck('id'));

        $this->coordenador = User::create([
            'name' => 'Coordenador', 'email' => 'c'.uniqid().'@ipccg.org.br', 'password' => 'segredo123',
        ]);

        Membership::create([
            'user_id' => $this->coordenador->id, 'church_id' => 1,
            'system_role_id' => $perfil->id, 'status' => true, 'created_at' => now(),
        ]);

        Event::create(['church_id' => 1, 'nome' => 'Simpósio de Teste',
            'inicio' => today(), 'status' => 'em_andamento']);
    }

    public static function telas(): array
    {
        return [
            'painel'  => ['painel'],
            'venda'   => ['livraria.venda'],
            'estoque' => ['livraria.estoque'],
            'eventos' => ['eventos'],
            'ajuda'   => ['ajuda'],
            'checkin'      => ['participantes.checkin'],
            'inscritos'    => ['participantes.inscritos'],
            'dias'         => ['participantes.dias'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('telas')]
    public function test_tela_renderiza_pagina_inteira(string $rota): void
    {
        $resposta = $this->actingAs($this->coordenador)->get(route($rota));

        $resposta->assertOk()
            ->assertSee('assets/app.css', escape: false)   // design system carregado
            ->assertSee('Simpósio de Teste');              // contexto do evento na sidebar
    }

    public function test_login_renderiza_para_visitante(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Congregação')
            ->assertSee('assets/app.css', escape: false);
    }
}
