<?php

namespace Tests\Feature\Livraria;

use App\Models\Livraria\Category;
use App\Models\Livraria\CategoryField;
use App\Models\Livraria\Product;
use App\Models\Livraria\Supplier;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\SystemRole;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Criar categoria com campos próprios — e o efeito disso no cadastro do item,
 * que é o ponto todo: acrescentar "Caneca" com "Capacidade" vira cadastro, não
 * desenvolvimento.
 */
class CategoriasTest extends TestCase
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
            'name' => 'Coord', 'email' => 'cat'.uniqid().'@ipccg.org.br', 'password' => 'segredo123',
        ]);
        Membership::create(['user_id' => $this->coordenador->id, 'church_id' => 1,
            'system_role_id' => $perfil->id, 'status' => true, 'created_at' => now()]);
    }

    private function tela()
    {
        return Livewire::actingAs($this->coordenador)->test('livraria.categorias');
    }

    public function test_cria_categoria_e_acrescenta_campos(): void
    {
        $nome = 'Caneca '.uniqid();

        $componente = $this->tela()
            ->set('nome', $nome)
            ->call('salvar')
            ->assertHasNoErrors()
            ->set('campo_rotulo', 'Capacidade (ml)')
            ->set('campo_tipo', 'inteiro')
            ->call('adicionarCampo')
            ->assertHasNoErrors();

        $cat = Category::find($componente->get('editando'));
        $this->assertSame($nome, $cat->nome);

        $campo = $cat->fields()->first();
        $this->assertSame('capacidade_ml', $campo->chave);   // slug sem acento nem parêntese
        $this->assertSame('inteiro', $campo->tipo);
    }

    /** Campo do tipo lista exige as opções e as guarda como array. */
    public function test_campo_de_selecao_guarda_as_opcoes(): void
    {
        $componente = $this->tela()->set('nome', 'Caneca '.uniqid())->call('salvar');

        $componente->set('campo_rotulo', 'Cor')->set('campo_tipo', 'selecao')
            ->call('adicionarCampo')->assertHasErrors('campo_opcoes');

        $componente->set('campo_opcoes', 'Preta, Branca , Azul')
            ->call('adicionarCampo')->assertHasNoErrors();

        $campo = CategoryField::where('category_id', $componente->get('editando'))->first();
        $this->assertSame(['Preta', 'Branca', 'Azul'], $campo->opcoes);
    }

    /** O campo criado aqui já é exigido no cadastro do item. */
    public function test_campo_obrigatorio_passa_a_valer_no_cadastro_do_item(): void
    {
        $componente = $this->tela()
            ->set('nome', 'Caneca '.uniqid())->call('salvar')
            ->set('campo_rotulo', 'Material')->set('campo_obrigatorio', true)
            ->call('adicionarCampo');

        $catId = $componente->get('editando');
        $forn  = Supplier::create(['church_id' => 1, 'nome' => 'F', 'prefixo' => 'K'.random_int(10, 99)]);

        Livewire::actingAs($this->coordenador)->test('livraria.catalogo')
            ->set('category_id', $catId)
            ->set('supplier_id', $forn->id)
            ->set('nome', 'Caneca do evento')
            ->call('salvar')
            ->assertHasErrors('atributos.material');
    }

    public function test_categoria_com_variacao_exige_o_rotulo(): void
    {
        $this->tela()
            ->set('nome', 'Boné '.uniqid())
            ->set('usa_variacao', true)
            ->call('salvar')
            ->assertHasErrors('rotulo_variacao');
    }

    public function test_nome_repetido_avisa(): void
    {
        $nome = 'Repetida '.uniqid();
        $this->tela()->set('nome', $nome)->call('salvar')->assertHasNoErrors();

        $this->tela()->set('nome', $nome)->call('salvar')
            ->assertDispatched('toast', tipo: 'erro');
    }

    /** Remover campo não apaga o que já foi preenchido nos itens. */
    public function test_remover_campo_preserva_o_valor_ja_gravado(): void
    {
        $componente = $this->tela()
            ->set('nome', 'Caneca '.uniqid())->call('salvar')
            ->set('campo_rotulo', 'Marca')->call('adicionarCampo');

        $catId = $componente->get('editando');
        $forn  = Supplier::create(['church_id' => 1, 'nome' => 'F', 'prefixo' => 'M'.random_int(10, 99)]);

        $produto = Product::create(['church_id' => 1, 'category_id' => $catId, 'supplier_id' => $forn->id,
            'nome' => 'Caneca', 'atributos' => ['marca' => 'Tramontina'], 'ativo' => true]);

        $campo = CategoryField::where('category_id', $catId)->first();
        $componente->call('removerCampo', $campo->id);

        $this->assertSame('Tramontina', $produto->fresh()->atributo('marca'));
    }

    public function test_observacoes_e_um_campo_universal_do_item(): void
    {
        $cat  = Category::create(['church_id' => 1, 'nome' => 'Simples '.uniqid(),
            'slug' => 's'.uniqid(), 'usa_variacao' => false]);
        $forn = Supplier::create(['church_id' => 1, 'nome' => 'F', 'prefixo' => 'O'.random_int(10, 99)]);

        Livewire::actingAs($this->coordenador)->test('livraria.catalogo')
            ->set('category_id', $cat->id)
            ->set('supplier_id', $forn->id)
            ->set('nome', 'Item com observação')
            ->set('observacoes', 'Exemplar de mostruário, capa levemente amassada.')
            ->call('salvar')
            ->assertHasNoErrors();

        $this->assertSame('Exemplar de mostruário, capa levemente amassada.',
            Product::where('nome', 'Item com observação')->value('observacoes'));
    }
}
