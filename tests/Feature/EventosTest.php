<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Livraria\PaymentMethod;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\SystemRole;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/** Criar, editar e parametrizar o evento — e escolher qual está ativo. */
class EventosTest extends TestCase
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

        $perfil = SystemRole::create(['church_id' => 1, 'name' => 'Coord '.uniqid()]);
        $perfil->permissions()->sync(Permission::pluck('id'));

        $this->coordenador = User::create([
            'name' => 'Coord', 'email' => 'e'.uniqid().'@ipccg.org.br', 'password' => 'segredo123',
        ]);
        Membership::create(['user_id' => $this->coordenador->id, 'church_id' => 1,
            'system_role_id' => $perfil->id, 'status' => true, 'created_at' => now()]);
    }

    private function tela()
    {
        return Livewire::actingAs($this->coordenador)->test('eventos');
    }

    public function test_cria_evento_com_meta_zero_a_zero(): void
    {
        $this->tela()
            ->set('nome', 'Retiro de Carnaval')
            ->set('local', 'Chácara')
            ->set('inicio', today()->toDateString())
            ->set('status', 'planejamento')
            ->set('meta_tipo', 'zero_a_zero')
            ->call('salvar')
            ->assertHasNoErrors();

        $e = Event::where('nome', 'Retiro de Carnaval')->first();
        $this->assertSame('zero_a_zero', $e->livrariaSettings->meta_tipo);
    }

    /** Evento novo já nasce com PIX, Débito e Crédito — não se cadastra do zero toda vez. */
    public function test_evento_novo_nasce_com_as_formas_usuais(): void
    {
        $this->tela()
            ->set('nome', 'Congresso '.uniqid())
            ->set('inicio', today()->toDateString())
            ->call('salvar')
            ->assertHasNoErrors();

        $e = Event::orderByDesc('id')->first();
        $this->assertSame(['PIX', 'Débito', 'Crédito'],
            PaymentMethod::where('event_id', $e->id)->orderBy('ordem')->pluck('nome')->all());
    }

    public function test_meta_por_valor_exige_o_valor(): void
    {
        $this->tela()
            ->set('nome', 'Evento com meta')
            ->set('inicio', today()->toDateString())
            ->set('meta_tipo', 'valor')
            ->call('salvar')
            ->assertHasErrors('meta_valor');
    }

    public function test_termino_nao_pode_ser_antes_do_inicio(): void
    {
        $this->tela()
            ->set('nome', 'Evento invertido')
            ->set('inicio', today()->toDateString())
            ->set('fim', today()->subDay()->toDateString())
            ->call('salvar')
            ->assertHasErrors('fim');
    }

    public function test_ativar_evento_muda_o_contexto_da_sessao(): void
    {
        $e = Event::create(['church_id' => 1, 'nome' => 'Outro evento',
            'inicio' => today(), 'status' => 'planejamento']);

        $this->tela()->call('ativar', $e->id);

        $this->assertSame($e->id, session('event_id'));
    }

    public function test_ajusta_a_taxa_de_uma_forma_de_pagamento(): void
    {
        $componente = $this->tela()
            ->set('nome', 'Evento taxa '.uniqid())
            ->set('inicio', today()->toDateString())
            ->call('salvar');

        $evento = Event::find($componente->get('editando'));
        $credito = PaymentMethod::where('event_id', $evento->id)->where('nome', 'Crédito')->first();

        $componente->call('atualizarTaxa', $credito->id, '4.5');

        $this->assertSame('4.50', $credito->fresh()->taxa_percentual);
    }

    /** Forma já usada numa venda não pode sumir: a conferência do extrato quebraria. */
    public function test_nao_remove_forma_ja_usada_em_venda(): void
    {
        $componente = $this->tela()
            ->set('nome', 'Evento venda '.uniqid())
            ->set('inicio', today()->toDateString())
            ->call('salvar');

        $evento = Event::find($componente->get('editando'));
        $pix    = PaymentMethod::where('event_id', $evento->id)->where('nome', 'PIX')->first();

        $evento->sales()->create([
            'payment_method_id' => $pix->id, 'valor_bruto' => 10,
            'taxa_percentual' => 0, 'taxa_valor' => 0, 'vendida_em' => now(), 'created_at' => now(),
        ]);

        $componente->call('removerForma', $pix->id)
            ->assertDispatched('toast', tipo: 'erro');

        $this->assertNotNull($pix->fresh());   // continua lá
    }

    public function test_quem_so_ve_eventos_nao_consegue_salvar(): void
    {
        $perfil = SystemRole::create(['church_id' => 1, 'name' => 'Leitor '.uniqid()]);
        $perfil->permissions()->sync(Permission::where('slug', 'eventos.ver')->pluck('id'));

        $leitor = User::create(['name' => 'Leitor', 'email' => 'l'.uniqid().'@ipccg.org.br', 'password' => 'segredo123']);
        Membership::create(['user_id' => $leitor->id, 'church_id' => 1,
            'system_role_id' => $perfil->id, 'status' => true, 'created_at' => now()]);

        Livewire::actingAs($leitor)->test('eventos')
            ->set('nome', 'Não deveria salvar')
            ->set('inicio', today()->toDateString())
            ->call('salvar')
            ->assertForbidden();
    }
}
