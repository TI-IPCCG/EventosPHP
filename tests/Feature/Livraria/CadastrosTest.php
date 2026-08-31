<?php

namespace Tests\Feature\Livraria;

use App\Models\Event;
use App\Models\Livraria\Category;
use App\Models\Livraria\Copy;
use App\Models\Livraria\Product;
use App\Models\Livraria\Shipment;
use App\Models\Livraria\ShipmentItem;
use App\Models\Livraria\Supplier;
use App\Models\Livraria\Variant;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\SystemRole;
use App\Models\User;
use App\Services\Livraria\ShipmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Cadastros: fornecedores, catálogo (com campos por categoria, variações e
 * fotos) e remessa (que materializa exemplares com código).
 */
class CadastrosTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    private User $coordenador;
    private Event $evento;
    private Category $catLivro;
    private Category $catCamiseta;
    private Supplier $fornecedor;

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
            'name' => 'Coord', 'email' => 'c'.uniqid().'@ipccg.org.br', 'password' => 'segredo123',
        ]);
        Membership::create(['user_id' => $this->coordenador->id, 'church_id' => 1,
            'system_role_id' => $perfil->id, 'status' => true, 'created_at' => now()]);

        $sufixo = uniqid();

        $this->catLivro = Category::create(['church_id' => 1, 'nome' => 'Livro',
            'slug' => "livro-$sufixo", 'usa_variacao' => false]);
        $this->catLivro->fields()->create(['chave' => 'autor', 'rotulo' => 'Autor',
            'tipo' => 'texto', 'obrigatorio' => true, 'mostrar_na_lista' => true, 'ordem' => 1]);
        $this->catLivro->fields()->create(['chave' => 'num_paginas', 'rotulo' => 'Páginas',
            'tipo' => 'inteiro', 'ordem' => 2]);

        $this->catCamiseta = Category::create(['church_id' => 1, 'nome' => 'Camiseta',
            'slug' => "cam-$sufixo", 'usa_variacao' => true, 'rotulo_variacao' => 'Tamanho']);
        $this->catCamiseta->fields()->create(['chave' => 'modelo', 'rotulo' => 'Modelo',
            'tipo' => 'texto', 'obrigatorio' => true, 'ordem' => 1]);

        $this->fornecedor = Supplier::create(['church_id' => 1, 'nome' => 'Editora T',
            'prefixo' => strtoupper(substr($sufixo, 0, 3)), 'condicao_padrao' => 'consignado',
            'desconto_padrao' => 40]);

        $this->evento = Event::create(['church_id' => 1, 'nome' => 'Evento Cadastro',
            'inicio' => today(), 'status' => 'planejamento']);
        session(['event_id' => $this->evento->id]);
    }

    private function como(string $componente)
    {
        return Livewire::actingAs($this->coordenador)->test($componente);
    }

    // ─────────────── fornecedores ───────────────

    public function test_cadastra_fornecedor(): void
    {
        // prefixo aleatório: a base de dev pode ter dados da demo, e o teste não
        // deve falhar por colidir com eles
        $prefixo = 'Z'.random_int(10, 99);
        $nome    = 'Editora '.uniqid();

        $this->como('livraria.fornecedores')
            ->set('nome', $nome)
            ->set('prefixo', mb_strtolower($prefixo))   // minúsculo: deve virar maiúsculo
            ->set('condicao_padrao', 'consignado')
            ->set('desconto_padrao', '40')
            ->call('salvar')
            ->assertHasNoErrors();

        $f = Supplier::where('nome', $nome)->first();
        $this->assertSame($prefixo, $f->prefixo);
        $this->assertTrue($f->isConsignado());
        $this->assertSame('40.00', $f->desconto_padrao);
    }

    public function test_prefixo_repetido_avisa_em_vez_de_estourar(): void
    {
        $this->como('livraria.fornecedores')
            ->set('nome', 'Outra Editora')
            ->set('prefixo', $this->fornecedor->prefixo)
            ->call('salvar')
            ->assertHasErrors('prefixo');
    }

    // ─────────────── catálogo ───────────────

    /** O formulário exige o que a CATEGORIA manda — nada disso está no código. */
    public function test_campo_obrigatorio_da_categoria_e_validado(): void
    {
        $this->como('livraria.catalogo')
            ->set('category_id', $this->catLivro->id)
            ->set('supplier_id', $this->fornecedor->id)
            ->set('nome', 'Livro sem autor')
            ->call('salvar')
            ->assertHasErrors('atributos.autor');
    }

    public function test_cadastra_livro_com_os_atributos_da_categoria(): void
    {
        $this->como('livraria.catalogo')
            ->set('category_id', $this->catLivro->id)
            ->set('supplier_id', $this->fornecedor->id)
            ->set('nome', 'Aquietai-vos')
            ->set('preco_referencia', '49.90')
            ->set('atributos.autor', 'John Piper')
            ->set('atributos.num_paginas', '224')
            ->call('salvar')
            ->assertHasNoErrors();

        $p = Product::where('nome', 'Aquietai-vos')->first();
        $this->assertSame('John Piper', $p->atributo('autor'));
        $this->assertSame('224', (string) $p->atributo('num_paginas'));
        $this->assertFalse($p->usaVariacao());
    }

    public function test_camiseta_exige_ao_menos_uma_variacao(): void
    {
        $this->como('livraria.catalogo')
            ->set('category_id', $this->catCamiseta->id)
            ->set('supplier_id', $this->fornecedor->id)
            ->set('nome', 'Camiseta sem tamanho')
            ->set('atributos.modelo', 'Básica')
            ->call('salvar')
            ->assertHasErrors('variacoes');
    }

    public function test_cadastra_camiseta_com_tamanhos_na_ordem(): void
    {
        $this->como('livraria.catalogo')
            ->set('category_id', $this->catCamiseta->id)
            ->set('supplier_id', $this->fornecedor->id)
            ->set('nome', 'Camiseta Evento')
            ->set('atributos.modelo', 'Básica')
            ->set('variacoes', [['id' => null, 'nome' => 'P'], ['id' => null, 'nome' => 'M'], ['id' => null, 'nome' => 'G']])
            ->call('salvar')
            ->assertHasNoErrors();

        $p = Product::where('nome', 'Camiseta Evento')->first();
        $this->assertSame(['P', 'M', 'G'], $p->variants->pluck('nome')->all());
    }

    /** Trocar de categoria descarta atributo que a nova não tem. */
    public function test_troca_de_categoria_limpa_atributos_orfaos(): void
    {
        $componente = $this->como('livraria.catalogo')
            ->set('category_id', $this->catLivro->id)
            ->set('atributos.autor', 'Alguém')
            ->set('category_id', $this->catCamiseta->id);

        $this->assertArrayNotHasKey('autor', $componente->get('atributos'));
    }

    public function test_foto_gera_miniatura_e_a_primeira_vira_capa(): void
    {
        Storage::fake('public');

        $produto = Product::create(['church_id' => 1, 'category_id' => $this->catLivro->id,
            'supplier_id' => $this->fornecedor->id, 'nome' => 'Com foto',
            'atributos' => ['autor' => 'A'], 'ativo' => true]);

        $this->como('livraria.catalogo')
            ->call('editar', $produto->id)
            ->set('foto', UploadedFile::fake()->image('capa.jpg', 1600, 1600))
            ->call('enviarFoto')
            ->assertHasNoErrors();

        $foto = $produto->photos()->first();
        $this->assertTrue($foto->capa);
        $this->assertNotNull($foto->caminho_thumb);
        $this->assertSame(1200, $foto->largura);          // reduzida ao máximo
        Storage::disk('public')->assertExists($foto->caminho);
        Storage::disk('public')->assertExists($foto->caminho_thumb);
    }

    // ─────────────── remessa ───────────────

    private function livro(string $nome = 'Título'): Product
    {
        return Product::create(['church_id' => 1, 'category_id' => $this->catLivro->id,
            'supplier_id' => $this->fornecedor->id, 'nome' => $nome,
            'preco_referencia' => 50, 'atributos' => ['autor' => 'A'], 'ativo' => true]);
    }

    private function remessa(): Shipment
    {
        return Shipment::firstOrCreate(
            ['event_id' => $this->evento->id, 'supplier_id' => $this->fornecedor->id],
            ['condicao' => 'consignado', 'created_at' => now()],
        );
    }

    public function test_salvar_linha_gera_exemplares_com_codigo_sequencial(): void
    {
        app(ShipmentService::class)->definirItem(
            $this->remessa(), $this->livro('Livro A'), null, 3, 30.00, 45.00
        );

        $codigos = Copy::where('event_id', $this->evento->id)->orderBy('codigo')->pluck('codigo')->all();
        $p = $this->fornecedor->prefixo;

        $this->assertSame(["{$p}001", "{$p}002", "{$p}003"], $codigos);
    }

    /** A sequência continua de onde parou, sem repetir código. */
    public function test_segundo_item_continua_a_sequencia(): void
    {
        $servico = app(ShipmentService::class);
        $servico->definirItem($this->remessa(), $this->livro('Livro A'), null, 2, 30, 45);
        $servico->definirItem($this->remessa(), $this->livro('Livro B'), null, 2, 24, 36);

        $codigos = Copy::where('event_id', $this->evento->id)->pluck('codigo')->all();
        $this->assertCount(4, $codigos);
        $this->assertCount(4, array_unique($codigos));
    }

    public function test_aumentar_quantidade_gera_so_os_que_faltam(): void
    {
        $servico = app(ShipmentService::class);
        $livro   = $this->livro('Livro A');

        $servico->definirItem($this->remessa(), $livro, null, 2, 30, 45);
        $servico->definirItem($this->remessa(), $livro, null, 5, 30, 45);

        $this->assertSame(5, Copy::where('event_id', $this->evento->id)->count());
    }

    public function test_reduzir_quantidade_tira_apenas_disponiveis(): void
    {
        $servico = app(ShipmentService::class);
        $livro   = $this->livro('Livro A');
        $servico->definirItem($this->remessa(), $livro, null, 5, 30, 45);

        $servico->definirItem($this->remessa(), $livro, null, 3, 30, 45);

        $this->assertSame(3, Copy::where('event_id', $this->evento->id)->count());
    }

    /** Reduzir abaixo do que já saiu apagaria receita: tem de recusar. */
    public function test_nao_reduz_abaixo_do_que_ja_foi_vendido(): void
    {
        $servico = app(ShipmentService::class);
        $livro   = $this->livro('Livro A');
        $item    = $servico->definirItem($this->remessa(), $livro, null, 4, 30, 45);

        // três já saíram
        Copy::where('shipment_item_id', $item->id)->limit(3)->update(['status' => Copy::VENDIDO]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/não dá para reduzir/i');

        $servico->definirItem($this->remessa(), $livro, null, 2, 30, 45);
    }

    public function test_nao_remove_linha_com_exemplar_que_ja_saiu(): void
    {
        $servico = app(ShipmentService::class);
        $item    = $servico->definirItem($this->remessa(), $this->livro('Livro A'), null, 2, 30, 45);
        Copy::where('shipment_item_id', $item->id)->limit(1)->update(['status' => Copy::VENDIDO]);

        $this->expectException(RuntimeException::class);
        $servico->removerItem($item->fresh());
    }

    public function test_item_com_variacao_exige_escolher_o_tamanho(): void
    {
        $camisa = Product::create(['church_id' => 1, 'category_id' => $this->catCamiseta->id,
            'supplier_id' => $this->fornecedor->id, 'nome' => 'Camiseta',
            'atributos' => ['modelo' => 'B'], 'ativo' => true]);
        Variant::create(['product_id' => $camisa->id, 'nome' => 'M', 'ordem' => 1]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tamanho/i');

        app(ShipmentService::class)->definirItem($this->remessa(), $camisa, null, 3, 20, 40);
    }

    public function test_cada_tamanho_vira_uma_linha_de_estoque_propria(): void
    {
        $camisa = Product::create(['church_id' => 1, 'category_id' => $this->catCamiseta->id,
            'supplier_id' => $this->fornecedor->id, 'nome' => 'Camiseta',
            'atributos' => ['modelo' => 'B'], 'ativo' => true]);

        $servico = app(ShipmentService::class);

        foreach (['P' => 2, 'M' => 4] as $tam => $qtd) {
            $v = Variant::create(['product_id' => $camisa->id, 'nome' => $tam, 'ordem' => 1]);
            $servico->definirItem($this->remessa(), $camisa, $v, $qtd, 20, 40);
        }

        $this->assertSame(2, ShipmentItem::where('product_id', $camisa->id)->count());
        $this->assertSame(6, Copy::where('event_id', $this->evento->id)->count());
    }

    public function test_lanca_custo_do_evento_pela_tela(): void
    {
        $this->como('livraria.remessa')
            ->set('supplier_id', $this->fornecedor->id)
            ->set('custo_descricao', 'Frete ida e volta')
            ->set('custo_valor', '400')
            ->set('custo_rateio', 'direto')
            ->call('salvarCusto')
            ->assertHasNoErrors();

        $this->assertSame('400.00', $this->evento->costs()->value('valor'));
    }

    // ─────────────── desconto: fornecedor x item ───────────────

    /** Sem desconto próprio, o item herda o do fornecedor (40%). */
    public function test_item_sem_desconto_proprio_usa_o_do_fornecedor(): void
    {
        $livro = $this->livro('Herda o desconto');
        $livro->update(['preco_referencia' => 50]);

        $this->assertSame(40.0, $livro->fresh()->descontoEfetivo());
        $this->assertSame('fornecedor', $livro->fresh()->origemDoDesconto());
        $this->assertSame(30.0, $livro->fresh()->custoSugerido());   // 50 − 40%
    }

    /** Desconto no item é exceção e prevalece. */
    public function test_desconto_do_item_prevalece_sobre_o_do_fornecedor(): void
    {
        $livro = $this->livro('Desconto próprio');
        $livro->update(['preco_referencia' => 50, 'desconto' => 25]);

        $this->assertSame(25.0, $livro->fresh()->descontoEfetivo());
        $this->assertSame('item', $livro->fresh()->origemDoDesconto());
        $this->assertSame(37.5, $livro->fresh()->custoSugerido());   // 50 − 25%
    }

    /** Sem desconto em lugar nenhum, não há sugestão — o custo é digitado. */
    public function test_sem_desconto_em_lugar_nenhum_nao_ha_sugestao(): void
    {
        $forn = Supplier::create(['church_id' => 1, 'nome' => 'Sem desconto',
            'prefixo' => 'S'.random_int(10, 99), 'desconto_padrao' => null]);

        $livro = Product::create(['church_id' => 1, 'category_id' => $this->catLivro->id,
            'supplier_id' => $forn->id, 'nome' => 'Sem desconto',
            'preco_referencia' => 50, 'atributos' => ['autor' => 'A'], 'ativo' => true]);

        $this->assertNull($livro->descontoEfetivo());
        $this->assertNull($livro->custoSugerido());
        $this->assertSame('nenhum', $livro->origemDoDesconto());
    }

    /** A remessa sugere o custo com o desconto do item, não o do fornecedor. */
    public function test_remessa_sugere_custo_com_o_desconto_do_item(): void
    {
        $livro = $this->livro('Na remessa');
        $livro->update(['preco_referencia' => 50, 'desconto' => 10]);

        Livewire::actingAs($this->coordenador)->test('livraria.remessa')
            ->set('supplier_id', $this->fornecedor->id)
            ->set('product_id', $livro->id)
            ->assertSet('custo_unitario', '45.00')     // 50 − 10%, não 30 (40% do fornecedor)
            ->assertSet('preco_venda', '50.00');
    }

    /** Renegociar o desconto não mexe no que já foi gravado na remessa. */
    public function test_mudar_o_desconto_depois_nao_altera_remessa_ja_montada(): void
    {
        $livro = $this->livro('Snapshot');
        $livro->update(['preco_referencia' => 50, 'desconto' => 40]);

        $item = app(ShipmentService::class)->definirItem($this->remessa(), $livro, null, 2, 30.00, 45.00);

        $livro->update(['desconto' => 10]);

        $this->assertSame('30.00', $item->fresh()->custo_unitario);
    }

    // ─────────────── atalho estoque → catálogo ───────────────

    /** O nome do item no estoque leva direto à edição dele no catálogo. */
    public function test_estoque_linka_para_a_edicao_do_item(): void
    {
        $livro = $this->livro('Livro do atalho');
        app(ShipmentService::class)->definirItem($this->remessa(), $livro, null, 2, 30, 45);

        Livewire::actingAs($this->coordenador)->test('livraria.estoque')
            ->assertSee(route('livraria.catalogo', ['editando' => $livro->id]), escape: false);
    }

    /** Quem não pode editar o catálogo vê o nome, não o link. */
    public function test_operador_de_mesa_nao_ve_o_atalho(): void
    {
        $livro = $this->livro('Livro do atalho');
        app(ShipmentService::class)->definirItem($this->remessa(), $livro, null, 1, 30, 45);

        $perfil = SystemRole::create(['church_id' => 1, 'name' => 'Mesa '.uniqid()]);
        $perfil->permissions()->sync(
            Permission::whereIn('slug', ['livraria.ver', 'livraria.vender'])->pluck('id')
        );

        $operador = User::create(['name' => 'Op', 'email' => 'op'.uniqid().'@ipccg.org.br',
            'password' => 'segredo123']);
        Membership::create(['user_id' => $operador->id, 'church_id' => 1,
            'system_role_id' => $perfil->id, 'status' => true, 'created_at' => now()]);

        Livewire::actingAs($operador)->test('livraria.estoque')
            ->assertSee('Livro do atalho')
            ->assertDontSee(route('livraria.catalogo', ['editando' => $livro->id]), escape: false);
    }

    /** O catálogo abre com o item já carregado. */
    public function test_catalogo_abre_o_item_vindo_pela_url(): void
    {
        $livro = $this->livro('Livro pela URL');

        Livewire::actingAs($this->coordenador)
            ->withQueryParams(['editando' => $livro->id])
            ->test('livraria.catalogo')
            ->assertSet('editando', $livro->id)
            ->assertSet('nome', 'Livro pela URL');
    }

    /** Link antigo, item apagado: abre em branco em vez de estourar. */
    public function test_url_com_item_inexistente_abre_formulario_vazio(): void
    {
        Livewire::actingAs($this->coordenador)
            ->withQueryParams(['editando' => 999999])
            ->test('livraria.catalogo')
            ->assertOk()
            ->assertSet('editando', null);
    }

    public function test_sem_permissao_de_catalogo_recebe_403(): void
    {
        $intruso = User::create(['name' => 'X', 'email' => 'x'.uniqid().'@ipccg.org.br', 'password' => 'segredo123']);
        Membership::create(['user_id' => $intruso->id, 'church_id' => 1, 'status' => true, 'created_at' => now()]);

        $this->actingAs($intruso)->get(route('livraria.catalogo'))->assertForbidden();
        $this->actingAs($intruso)->get(route('livraria.remessa'))->assertForbidden();
    }
}
