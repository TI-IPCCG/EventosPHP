<?php

namespace Tests\Feature\Livraria;

use App\Models\Event;
use App\Models\Livraria\Category;
use App\Models\Livraria\Copy;
use App\Models\Livraria\PaymentMethod;
use App\Models\Livraria\Product;
use App\Models\Livraria\Sale;
use App\Models\Livraria\Shipment;
use App\Models\Livraria\ShipmentItem;
use App\Models\Livraria\Supplier;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\SystemRole;
use App\Models\User;
use App\Services\Livraria\SaleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A tela de vendas registradas: listar, ordenar, paginar — e as três ações
 * que mexem em dinheiro já lançado.
 *
 * O que mais importa aqui é a separação de permissão: VER a venda é parte de
 * ver a livraria, mas MEXER nela exige livraria.corrigir. Quem opera a mesa
 * não corrige o próprio lançamento sem esse perfil.
 */
class TelaDeVendasTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    private Event $evento;
    private User $coordenador;
    private PaymentMethod $pix;
    private array $copies = [];

    protected function setUp(): void
    {
        parent::setUp();
        session(['church_id' => 1]);
        $this->montarCenario();
        session(['event_id' => $this->evento->id]);

        $this->coordenador = $this->pessoaCom(['livraria.ver', 'livraria.vender', 'livraria.corrigir']);
    }

    private function pessoaCom(array $slugs): User
    {
        foreach ($slugs as $slug) {
            Permission::firstOrCreate(['slug' => $slug], ['description' => $slug]);
        }

        $perfil = SystemRole::create(['church_id' => 1, 'name' => 'P'.uniqid()]);
        $perfil->permissions()->sync(Permission::whereIn('slug', $slugs)->pluck('id'));

        $u = User::create(['name' => 'Operador', 'email' => 'v'.uniqid().'@ipccg.org.br',
            'password' => 'segredo123']);

        Membership::create(['user_id' => $u->id, 'church_id' => 1,
            'system_role_id' => $perfil->id, 'status' => true, 'created_at' => now()]);

        return $u;
    }

    private function montarCenario(): void
    {
        $sufixo = 'v'.uniqid();

        $cat  = Category::create(['church_id' => 1, 'nome' => 'Livro V',
            'slug' => "livro-$sufixo", 'usa_variacao' => false]);
        $forn = Supplier::create(['church_id' => 1, 'nome' => 'Editora V',
            'prefixo' => bin2hex(random_bytes(2)), 'condicao_padrao' => 'firme']);

        $this->evento = Event::create(['church_id' => 1, 'nome' => 'Simpósio',
            'inicio' => today(), 'status' => 'em_andamento']);

        $this->pix = PaymentMethod::create(['event_id' => $this->evento->id,
            'nome' => 'PIX', 'taxa_percentual' => 0, 'ordem' => 1]);

        $remessa = Shipment::create(['event_id' => $this->evento->id,
            'supplier_id' => $forn->id, 'condicao' => 'firme']);

        foreach ([['A', 45.0], ['B', 36.0]] as [$letra, $preco]) {
            $produto = Product::create(['church_id' => 1, 'category_id' => $cat->id,
                'supplier_id' => $forn->id, 'nome' => "Título {$letra}"]);

            $item = ShipmentItem::create(['shipment_id' => $remessa->id,
                'product_id' => $produto->id, 'quantidade' => 4,
                'custo_unitario' => $preco / 2, 'preco_venda' => $preco]);

            for ($i = 1; $i <= 4; $i++) {
                $codigo = sprintf('V%s%03d', $letra, $i);
                $this->copies[$codigo] = Copy::create(['event_id' => $this->evento->id,
                    'shipment_item_id' => $item->id, 'codigo' => $codigo]);
            }
        }
    }

    private function vender(array $codigos, ?User $quem = null): Sale
    {
        return app(SaleService::class)->registrar(
            $this->evento->id,
            array_map(fn ($c) => $this->copies[$c]->id, $codigos),
            $this->pix->id,
            registradoPor: $quem?->id,
        );
    }

    private function tela(?User $quem = null)
    {
        return Livewire::actingAs($quem ?? $this->coordenador)->test('livraria.vendas');
    }

    // ─────────────────────── lista, ordem, página ───────────────────────

    public function test_a_lista_traz_as_vendas_do_evento(): void
    {
        $venda = $this->vender(['VA001']);

        $this->tela()->assertOk()->assertSee('#'.$venda->id);
    }

    public function test_busca_por_numero_da_venda(): void
    {
        $alvo  = $this->vender(['VA001']);
        $outra = $this->vender(['VA002']);

        $ids = $this->tela()->set('busca', '#'.$alvo->id)
            ->instance()->vendas->getCollection()->pluck('id')->all();

        $this->assertContains($alvo->id, $ids);
        $this->assertNotContains($outra->id, $ids);
    }

    public function test_busca_pelo_codigo_do_exemplar(): void
    {
        $alvo  = $this->vender(['VA001']);
        $outra = $this->vender(['VB001']);

        $ids = $this->tela()->set('busca', 'VA001')
            ->instance()->vendas->getCollection()->pluck('id')->all();

        $this->assertSame([$alvo->id], $ids);
        $this->assertNotContains($outra->id, $ids);
    }

    public function test_filtro_separa_estornadas_de_validas(): void
    {
        $viva      = $this->vender(['VA001']);
        $estornada = $this->vender(['VA002']);
        app(SaleService::class)->estornar($estornada);

        $so = fn (string $f) => $this->tela()->set('filtro', $f)
            ->instance()->vendas->getCollection()->pluck('id')->all();

        $this->assertSame([$viva->id], $so('validas'));
        $this->assertSame([$estornada->id], $so('estornadas'));
    }

    public function test_ordenar_por_valor(): void
    {
        $this->vender(['VA001']);            // 45
        $this->vender(['VB001']);            // 36

        $valores = fn (string $o) => $this->tela()->set('ordem', $o)
            ->instance()->vendas->getCollection()->pluck('valor_bruto')
            ->map(fn ($v) => (float) $v)->all();

        $this->assertSame([45.0, 36.0], $valores('maior'));
        $this->assertSame([36.0, 45.0], $valores('menor'));
    }

    public function test_paginacao_respeita_o_tamanho_e_o_teto(): void
    {
        foreach (['VA001', 'VA002', 'VA003', 'VB001', 'VB002', 'VB003'] as $c) {
            $this->vender([$c]);
        }

        $r = $this->tela()->set('porPagina', 5)->instance()->vendas;

        $this->assertCount(5, $r->items());
        $this->assertSame(6, $r->total());
        $this->assertSame(50, $this->tela()->set('porPagina', 99999)->instance()->vendas->perPage());
    }

    public function test_ordem_desconhecida_cai_no_padrao(): void
    {
        $this->vender(['VA001']);

        $r = $this->tela()->set('ordem', "id'; DROP TABLE liv_sales; --")->instance()->vendas;

        $this->assertSame(1, $r->total());
    }

    // ────────────────── quem fez o quê ──────────────────
    //
    // As colunas existiam desde o início e nunca apareciam na tela: o
    // relacionamento era até carregado no eager load, e descartado. Descobrir
    // quem operou a mesa numa venda estranha exigia SQL na mão.

    public function test_a_lista_mostra_quem_registrou_a_venda(): void
    {
        $vendedor = $this->pessoaCom(['livraria.ver', 'livraria.vender']);
        $vendedor->update(['name' => 'Marta da Mesa']);

        $this->vender(['VA001'], $vendedor);

        $this->tela()->assertSee('Marta da Mesa');
    }

    public function test_o_detalhe_mostra_quem_estornou(): void
    {
        $venda = $this->vender(['VA001']);

        $this->coordenador->update(['name' => 'Coord Responsável']);

        $this->tela()
            ->call('abrir', $venda->id)
            ->call('estornar')
            ->assertSee('Estornada em')
            ->assertSee('Coord Responsável');
    }

    public function test_o_detalhe_mostra_quem_corrigiu(): void
    {
        $venda = $this->vender(['VA001']);

        $this->coordenador->update(['name' => 'Quem Corrigiu']);

        $this->tela()
            ->call('abrir', $venda->id)
            ->call('abrirCorrecao')
            ->call('salvarCorrecao')
            ->call('abrir', $venda->id)
            ->assertSee('Corrigida em')
            ->assertSee('Quem Corrigiu');
    }

    /** O usuário pode ter sido removido — FK é SET NULL, e a tela não pode sumir. */
    public function test_venda_sem_usuario_conhecido_nao_quebra_a_tela(): void
    {
        $venda = $this->vender(['VA001']);       // registrado_por fica NULL

        $this->tela()->call('abrir', $venda->id)
            ->assertOk()
            ->assertSee('não está mais no sistema');
    }

    // ─────────────────────────── permissões ───────────────────────────

    /**
     * O footgun do módulo: ver a venda é parte de ver a livraria, mas mexer
     * nela não. Quem opera a mesa abre a tela e NÃO recebe os botões.
     */
    public function test_quem_so_vende_abre_a_tela_mas_nao_corrige(): void
    {
        $vendedor = $this->pessoaCom(['livraria.ver', 'livraria.vender']);
        $venda = $this->vender(['VA001']);

        $this->actingAs($vendedor)->get(route('livraria.vendas'))->assertOk();

        $this->tela($vendedor)
            ->call('abrir', $venda->id)
            ->assertDontSee('Corrigir lançamento')
            ->call('abrirCorrecao')
            ->assertForbidden();
    }

    public function test_quem_nao_ve_a_livraria_leva_403(): void
    {
        $estranho = $this->pessoaCom(['eventos.ver']);

        $this->actingAs($estranho)->get(route('livraria.vendas'))->assertForbidden();
    }

    public function test_estornar_exige_permissao(): void
    {
        $vendedor = $this->pessoaCom(['livraria.ver', 'livraria.vender']);
        $venda = $this->vender(['VA001']);

        $this->tela($vendedor)->call('abrir', $venda->id)
            ->call('estornar')->assertForbidden();

        $this->assertNull($venda->fresh()->cancelada_em);
    }

    // ─────────────────────────── as ações ───────────────────────────

    public function test_corrigir_pela_tela_reescreve_a_venda(): void
    {
        $venda = $this->vender(['VA001']);          // 45,00

        $this->tela()
            ->call('abrir', $venda->id)
            ->call('abrirCorrecao')
            ->call('alternarItemFinal', $this->copies['VA001']->id)   // tira
            ->call('adicionarAoFinal', $this->copies['VB001']->id)    // põe
            ->call('salvarCorrecao')
            ->assertHasNoErrors();

        $this->assertSame('36.00', $venda->fresh()->valor_bruto);
    }

    /** A correção abre com o que a venda tem hoje — é editar, não remontar. */
    public function test_a_correcao_abre_preenchida(): void
    {
        $venda = $this->vender(['VA001', 'VB001']);

        $componente = $this->tela()->call('abrir', $venda->id)->call('abrirCorrecao');

        $this->assertEqualsCanonicalizing(
            [$this->copies['VA001']->id, $this->copies['VB001']->id],
            $componente->instance()->itensFinais
        );
    }

    public function test_trocar_pela_tela_registra_a_diferenca(): void
    {
        $venda = $this->vender(['VA001']);          // 45,00
        $item  = $venda->items()->first()->id;

        $this->tela()
            ->call('abrir', $venda->id)
            ->call('abrirTroca')
            ->call('alternarSaida', $item)
            ->call('adicionarEntrada', $this->copies['VB001']->id)    // 36,00
            ->call('$set', 'trocaFormaId', $this->pix->id)
            ->call('salvarTroca')
            ->assertHasNoErrors();

        $troca = $venda->fresh()->exchanges()->first();

        $this->assertNotNull($troca);
        $this->assertSame('-9.00', $troca->diferenca);
        $this->assertSame('45.00', $venda->fresh()->valor_bruto, 'a venda original fica intacta');
    }

    /** A conta ao vivo é o ponto da tela: ela tem de dizer quanto devolver. */
    public function test_a_previa_calcula_antes_de_gravar(): void
    {
        $venda = $this->vender(['VA001']);
        $item  = $venda->items()->first()->id;

        $previa = $this->tela()
            ->call('abrir', $venda->id)
            ->call('abrirTroca')
            ->call('alternarSaida', $item)
            ->call('adicionarEntrada', $this->copies['VB001']->id)
            ->instance()->previaDaTroca;

        $this->assertSame(45.0, $previa['sai']);
        $this->assertSame(36.0, $previa['entra']);
        $this->assertSame(-9.0, $previa['diferenca']);

        // e nada foi gravado só por calcular
        $this->assertSame(0, $venda->fresh()->exchanges()->count());
    }

    /**
     * Cobrança adicional é transação nova: sem dizer por onde entrou, a
     * conferência do caixa fica com um valor sem origem.
     */
    public function test_cobranca_adicional_exige_forma_de_pagamento(): void
    {
        $venda = $this->vender(['VB001']);           // 36,00
        $item  = $venda->items()->first()->id;

        $this->tela()
            ->call('abrir', $venda->id)
            ->call('abrirTroca')
            ->call('alternarSaida', $item)
            ->call('adicionarEntrada', $this->copies['VA001']->id)   // 45,00 → cobra 9
            ->call('salvarTroca')
            ->assertHasErrors('trocaFormaId');

        $this->assertSame(0, $venda->fresh()->exchanges()->count());
    }

    // ────────────── escolher o que entra (padrão compartilhado) ──────────────
    //
    // Mesma escolha das Baixas, mesmo trait: a lista agrupa por ITEM e o código
    // sai no pop-up. Aqui o que se trava é que a troca não pode oferecer
    // exemplar vendido nem o que já está no rascunho — cada um desses viraria
    // uma recusa lá no serviço, depois de o operador já ter escolhido.

    public function test_a_lista_agrupa_por_item_com_a_contagem(): void
    {
        $venda = $this->vender(['VA001']);        // VA001 sai do estoque

        $linhas = $this->tela()
            ->call('abrir', $venda->id)
            ->call('abrirTroca')
            ->instance()->estoque;

        $this->assertCount(2, $linhas, 'dois títulos, uma linha cada');

        $a = $linhas->firstWhere('item', 'Título A');
        $this->assertSame(3, (int) $a->disponiveis, 'quatro menos o vendido');
    }

    public function test_a_contagem_desconta_o_que_ja_esta_no_rascunho(): void
    {
        $venda = $this->vender(['VA001']);

        $linhas = $this->tela()
            ->call('abrir', $venda->id)
            ->call('abrirTroca')
            ->call('adicionarEntrada', $this->copies['VA002']->id)
            ->instance()->estoque;

        $this->assertSame(2, (int) $linhas->firstWhere('item', 'Título A')->disponiveis);
    }

    public function test_a_busca_filtra_por_nome_do_item(): void
    {
        $venda = $this->vender(['VA001']);

        $linhas = $this->tela()
            ->call('abrir', $venda->id)
            ->call('abrirTroca')
            ->set('buscaEstoque', 'Título B')
            ->instance()->estoque;

        $this->assertCount(1, $linhas);
        $this->assertSame('Título B', $linhas->first()->item);
    }

    public function test_o_popup_nao_oferece_exemplar_vendido(): void
    {
        $venda = $this->vender(['VA001']);

        $codigos = $this->tela()
            ->call('abrir', $venda->id)
            ->call('abrirTroca')
            ->call('abrirLinha', $this->copies['VA002']->shipment_item_id)
            ->instance()->exemplaresDaLinha->pluck('codigo')->all();

        $this->assertNotContains('VA001', $codigos, 'esse está vendido');
        $this->assertContains('VA002', $codigos);
    }

    /** O escolhido continua no pop-up, marcado — não some sob o dedo. */
    public function test_o_escolhido_continua_visivel_e_marcado(): void
    {
        $venda = $this->vender(['VA001']);

        $componente = $this->tela()
            ->call('abrir', $venda->id)
            ->call('abrirTroca')
            ->call('abrirLinha', $this->copies['VA002']->shipment_item_id)
            ->call('adicionarEntrada', $this->copies['VA002']->id);

        $this->assertContains('VA002', $componente->instance()->exemplaresDaLinha->pluck('codigo')->all());
        $this->assertTrue($componente->instance()->jaEscolhido($this->copies['VA002']->id));
    }

    /**
     * O rascunho tem dois donos — `entrando` na troca e `itensFinais` na
     * correção. Somar os dois é o que impede a lista de oferecer duas vezes o
     * mesmo exemplar quando se alterna entre os modos sem fechar o painel.
     */
    public function test_o_rascunho_da_correcao_tambem_sai_da_lista(): void
    {
        $venda = $this->vender(['VA001']);

        $linhas = $this->tela()
            ->call('abrir', $venda->id)
            ->call('abrirCorrecao')
            ->call('adicionarAoFinal', $this->copies['VB001']->id)
            ->instance()->estoque;

        $this->assertSame(3, (int) $linhas->firstWhere('item', 'Título B')->disponiveis);
    }

    public function test_estornar_pela_tela_devolve_o_estoque(): void
    {
        $venda = $this->vender(['VA001']);

        $this->tela()->call('abrir', $venda->id)->call('estornar')->assertHasNoErrors();

        $this->assertNotNull($venda->fresh()->cancelada_em);
        $this->assertSame(Copy::DISPONIVEL, Copy::find($this->copies['VA001']->id)->status);
    }

    /**
     * Regra de negócio chegando à tela como aviso, não como erro 500:
     * corrigir venda com troca reescreveria o valor que a troca preservou.
     */
    public function test_corrigir_venda_com_troca_avisa_em_vez_de_estourar(): void
    {
        $venda = $this->vender(['VA001']);
        $item  = $venda->items()->first()->id;

        $this->tela()->call('abrir', $venda->id)->call('abrirTroca')
            ->call('alternarSaida', $item)
            ->call('adicionarEntrada', $this->copies['VB001']->id)
            ->call('$set', 'trocaFormaId', $this->pix->id)
            ->call('salvarTroca');

        $this->tela()
            ->call('abrir', $venda->id)
            ->call('abrirCorrecao')
            ->call('salvarCorrecao')
            ->assertHasNoErrors();          // avisa por toast, não quebra

        $this->assertNull($venda->fresh()->corrigida_em, 'a correção não pode ter passado');
    }
}
