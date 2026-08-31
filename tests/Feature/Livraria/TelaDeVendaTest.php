<?php

namespace Tests\Feature\Livraria;

use App\Models\Event;
use App\Models\Livraria\Category;
use App\Models\Livraria\Copy;
use App\Models\Livraria\PaymentMethod;
use App\Models\Livraria\Product;
use App\Models\Livraria\Shipment;
use App\Models\Livraria\ShipmentItem;
use App\Models\Livraria\Supplier;
use App\Models\Livraria\Variant;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\SystemRole;
use App\Models\User;
use App\Services\Livraria\EventResult;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A tela da mesa, ponta a ponta: login com vínculo, busca, carrinho,
 * pagamento e conclusão — do jeito que o voluntário usa.
 */
class TelaDeVendaTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    private User $voluntario;
    private Event $evento;
    private PaymentMethod $pix;
    private array $copies = [];

    protected function setUp(): void
    {
        parent::setUp();
        session(['church_id' => 1]);
        $this->criarVoluntario();
        $this->montarEvento();
        session(['event_id' => $this->evento->id]);
    }

    private function criarVoluntario(): void
    {
        foreach (['livraria.ver', 'livraria.vender'] as $slug) {
            Permission::firstOrCreate(['slug' => $slug], ['description' => $slug]);
        }

        $perfil = SystemRole::create(['church_id' => 1, 'name' => 'Operador '.uniqid()]);
        $perfil->permissions()->sync(Permission::whereIn('slug', ['livraria.ver', 'livraria.vender'])->pluck('id'));

        $this->voluntario = User::create([
            'name' => 'Voluntário', 'email' => 'vol'.uniqid().'@ipccg.org.br',
            'password' => 'segredo123',
        ]);

        Membership::create([
            'user_id' => $this->voluntario->id, 'church_id' => 1,
            'system_role_id' => $perfil->id, 'status' => true, 'created_at' => now(),
        ]);
    }

    private function montarEvento(): void
    {
        $sufixo = uniqid();

        $catCam = Category::create(['church_id' => 1, 'nome' => 'Camiseta', 'slug' => "cam-$sufixo",
            'usa_variacao' => true, 'rotulo_variacao' => 'Tamanho']);
        $catCam->fields()->create(['chave' => 'modelo', 'rotulo' => 'Modelo', 'tipo' => 'texto', 'mostrar_na_lista' => true]);

        $forn = Supplier::create(['church_id' => 1, 'nome' => 'Fornecedor', 'prefixo' => substr($sufixo, 0, 4)]);

        $camisa = Product::create(['church_id' => 1, 'category_id' => $catCam->id, 'supplier_id' => $forn->id,
            'nome' => 'Camiseta Simpósio', 'atributos' => ['modelo' => 'Básica']]);

        $this->evento = Event::create(['church_id' => 1, 'nome' => 'Simpósio',
            'inicio' => today(), 'status' => 'em_andamento']);

        $this->pix = PaymentMethod::create(['event_id' => $this->evento->id, 'nome' => 'PIX',
            'taxa_percentual' => 0, 'ordem' => 1]);
        PaymentMethod::create(['event_id' => $this->evento->id, 'nome' => 'Crédito',
            'taxa_percentual' => 4.5, 'ordem' => 2]);

        $remessa = Shipment::create(['event_id' => $this->evento->id, 'supplier_id' => $forn->id, 'condicao' => 'firme']);

        foreach (['M' => 2, 'G' => 1] as $tam => $qtd) {
            $variacao = Variant::create([
                'product_id' => $camisa->id, 'nome' => $tam,
                'ordem' => $tam === 'M' ? 1 : 2,
            ]);
            $item = ShipmentItem::create(['shipment_id' => $remessa->id, 'product_id' => $camisa->id,
                'variant_id' => $variacao->id, 'quantidade' => $qtd, 'custo_unitario' => 20, 'preco_venda' => 40]);

            for ($i = 1; $i <= $qtd; $i++) {
                $codigo = "C{$tam}00{$i}";
                $this->copies[$codigo] = Copy::create([
                    'event_id' => $this->evento->id, 'shipment_item_id' => $item->id, 'codigo' => $codigo,
                ]);
            }
        }
    }

    private function tela()
    {
        return Livewire::actingAs($this->voluntario)->test('livraria.venda');
    }

    public function test_tela_carrega_com_o_evento_ativo(): void
    {
        $this->tela()
            ->assertOk()
            ->assertSee('Simpósio')
            ->assertSee('Venda');
    }

    /**
     * Sem busca, a tela JÁ mostra o estoque disponível.
     *
     * Antes exigia 3 caracteres e abria em branco — uma caixa de busca solta
     * numa página vazia, sem dizer o que fazer. Numa livraria de evento, com
     * uma dezena de títulos, tocar na lista é mais rápido que digitar.
     */
    public function test_sem_busca_a_tela_ja_lista_o_estoque(): void
    {
        $resultados = $this->tela()->instance()->resultados;

        $this->assertGreaterThan(0, $resultados->count());
        $this->assertSame(3, (int) collect($resultados)->sum('disponiveis'));  // 2 M + 1 G
    }

    /** Digitar filtra a mesma lista. */
    public function test_busca_filtra_a_lista(): void
    {
        $c = $this->tela()->set('busca', 'Camiseta');
        $this->assertGreaterThan(0, $c->instance()->resultados->count());

        $c->set('busca', 'Isso não existe');
        $this->assertCount(0, $c->instance()->resultados);
    }

    /** Cada tamanho é uma linha própria — é o que responde "tem no M?". */
    public function test_busca_devolve_uma_linha_por_variacao_com_o_saldo(): void
    {
        $resultados = $this->tela()->set('busca', 'Camiseta')->instance()->resultados;

        $this->assertCount(2, $resultados);                      // M e G
        $this->assertSame('M', $resultados[0]->variacao);
        $this->assertSame(2, (int) $resultados[0]->disponiveis);
        $this->assertSame('G', $resultados[1]->variacao);
        $this->assertSame(1, (int) $resultados[1]->disponiveis);
    }

    public function test_busca_por_codigo_encontra_o_exemplar(): void
    {
        $resultados = $this->tela()->set('busca', 'CM001')->instance()->resultados;

        $this->assertCount(1, $resultados);
        $this->assertSame('M', $resultados[0]->variacao);
    }

    /** Quatro toques: buscar, tocar no item, tocar na forma, concluir. */
    public function test_venda_de_item_unico_em_quatro_toques(): void
    {
        $componente = $this->tela()
            ->set('busca', 'Camiseta')                                   // 1 digitar
            ->call('adicionar', $this->copies['CM001']->id)              // 2 tocar no item
            ->call('$set', 'payment_method_id', $this->pix->id)          // 3 tocar na forma
            ->call('revisar')                                            // 4 revisar
            ->call('concluir');                                          //   confirmar

        $componente->assertHasNoErrors();

        $this->assertSame(40.00, EventResult::para($this->evento)->receita());
        $this->assertSame(Copy::VENDIDO, Copy::find($this->copies['CM001']->id)->status);

        // carrinho limpo e busca zerada, pronto para o próximo comprador
        $this->assertSame([], $componente->get('carrinho'));
        $this->assertSame('', $componente->get('busca'));
    }

    /** Uma venda com dois itens é UMA transação, com UMA taxa. */
    public function test_carrinho_com_dois_itens_gera_uma_transacao(): void
    {
        $credito = PaymentMethod::where('event_id', $this->evento->id)->where('nome', 'Crédito')->first();

        $this->tela()
            ->call('adicionar', $this->copies['CM001']->id)
            ->call('adicionar', $this->copies['CM002']->id)
            ->call('$set', 'payment_method_id', $credito->id)
            ->call('revisar')
            ->call('concluir')
            ->assertHasNoErrors();

        $this->assertSame(1, $this->evento->sales()->count());
        $this->assertSame(80.00, EventResult::para($this->evento)->receita());
        $this->assertSame(3.60, EventResult::para($this->evento)->taxas());   // 4,5% de 80, uma vez
    }

    public function test_exige_forma_de_pagamento(): void
    {
        $this->tela()
            ->call('adicionar', $this->copies['CM001']->id)
            ->call('concluir')
            ->assertHasErrors('payment_method_id');

        $this->assertSame(0.00, EventResult::para($this->evento)->receita());
    }

    public function test_carrinho_vazio_nao_conclui(): void
    {
        $this->tela()
            ->call('$set', 'payment_method_id', $this->pix->id)
            ->call('concluir')
            ->assertHasErrors('carrinho');
    }

    /** O item já no carrinho some da busca — não dá para adicionar duas vezes. */
    public function test_item_no_carrinho_sai_dos_resultados(): void
    {
        $componente = $this->tela()
            ->call('adicionar', $this->copies['CM001']->id)
            ->set('busca', 'Camiseta');

        $m = collect($componente->instance()->resultados)->firstWhere('variacao', 'M');

        $this->assertSame(1, (int) $m->disponiveis);   // 2 − 1 no carrinho
    }

    /** Outro voluntário vendeu entre a busca e o toque: avisa, não falha calado. */
    public function test_exemplar_vendido_por_outro_avisa_em_vez_de_estourar(): void
    {
        $copy = $this->copies['CM001'];
        Copy::where('id', $copy->id)->update(['status' => Copy::VENDIDO]);

        $componente = $this->tela()->call('adicionar', $copy->id);

        $componente->assertDispatched('toast', tipo: 'aviso');
        $this->assertSame([], $componente->get('carrinho'));   // não entrou no carrinho
    }

    // ─────────────── confirmação e avisos ───────────────

    /** Adicionar e remover do carrinho avisam — antes era silencioso. */
    public function test_carrinho_avisa_ao_adicionar_e_ao_remover(): void
    {
        $id = $this->copies['CM001']->id;

        $this->tela()
            ->call('adicionar', $id)
            ->assertDispatched('toast', tipo: 'ok')
            ->call('remover', $id)
            ->assertDispatched('toast', tipo: 'info');
    }

    /** "Concluir" abre a revisão; NADA é gravado antes de confirmar. */
    public function test_concluir_abre_a_revisao_sem_gravar(): void
    {
        $componente = $this->tela()
            ->call('adicionar', $this->copies['CM001']->id)
            ->call('$set', 'payment_method_id', $this->pix->id)
            ->call('revisar');

        $this->assertTrue($componente->get('confirmando'));
        $this->assertSame(0, $this->evento->sales()->count());
        $this->assertSame(Copy::DISPONIVEL, Copy::find($this->copies['CM001']->id)->status);
    }

    /** O resumo mostra itens, taxa em separado e o total com a taxa. */
    public function test_resumo_da_confirmacao_detalha_itens_e_taxa(): void
    {
        $credito = PaymentMethod::where('event_id', $this->evento->id)->where('nome', 'Crédito')->first();

        $resumo = $this->tela()
            ->call('adicionar', $this->copies['CM001']->id)
            ->call('adicionar', $this->copies['CM002']->id)
            ->call('$set', 'payment_method_id', $credito->id)
            ->call('revisar')
            ->instance()->resumo;

        $this->assertCount(2, $resumo['itens']);
        $this->assertSame(80.00, $resumo['subtotal']);
        $this->assertSame(3.60, $resumo['taxa']);          // 4,5% de 80
        $this->assertSame(83.60, $resumo['total']);
        $this->assertSame('Crédito', $resumo['forma']);
    }

    public function test_cancelar_a_revisao_mantem_o_carrinho(): void
    {
        $componente = $this->tela()
            ->call('adicionar', $this->copies['CM001']->id)
            ->call('$set', 'payment_method_id', $this->pix->id)
            ->call('revisar')
            ->call('cancelarRevisao');

        $this->assertFalse($componente->get('confirmando'));
        $this->assertCount(1, $componente->get('carrinho'));
        $this->assertSame(0, $this->evento->sales()->count());
    }

    /** Documento entra junto do nome na identificação do comprador. */
    public function test_documento_do_comprador_e_gravado(): void
    {
        $this->tela()
            ->call('adicionar', $this->copies['CM001']->id)
            ->set('comprador', 'Maria')
            ->set('documento', '123.456.789-00')
            ->call('$set', 'payment_method_id', $this->pix->id)
            ->call('revisar')
            ->call('concluir')
            ->assertDispatched('toast', tipo: 'ok');

        $this->assertSame('Maria · 123.456.789-00', $this->evento->sales()->value('comprador'));
    }

    public function test_sem_permissao_de_vender_recebe_403(): void
    {
        $intruso = User::create([
            'name' => 'Sem acesso', 'email' => 'x'.uniqid().'@ipccg.org.br', 'password' => 'segredo123',
        ]);
        Membership::create(['user_id' => $intruso->id, 'church_id' => 1, 'status' => true, 'created_at' => now()]);

        $this->actingAs($intruso)->get(route('livraria.venda'))->assertForbidden();
    }

    public function test_visitante_e_mandado_para_o_login(): void
    {
        $this->get(route('livraria.venda'))->assertRedirect(route('login'));
    }
}
