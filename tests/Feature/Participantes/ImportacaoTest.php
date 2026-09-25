<?php

namespace Tests\Feature\Participantes;

use App\Models\Event;
use App\Models\Membership;
use App\Models\Participantes\Registration;
use App\Models\Permission;
use App\Models\SystemRole;
use App\Models\User;
use App\Services\Participantes\ImportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Importar a planilha de inscrições.
 *
 * A origem é um JotForm exportado à mão, então o teste ataca o que a realidade
 * entrega: delimitador variado, BOM do Excel, data em inglês, telefone com
 * código de país no lugar do DDD e cabeçalho que muda de nome.
 */
class ImportacaoTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    private Event $evento;
    private User $coord;

    protected function setUp(): void
    {
        parent::setUp();
        session(['church_id' => 1]);

        foreach (array_keys(\Database\Seeders\PermissionSeeder::CATALOG) as $slug) {
            Permission::firstOrCreate(['slug' => $slug], ['description' => $slug]);
        }

        $this->evento = Event::create(['church_id' => 1, 'nome' => 'Simpósio '.uniqid(),
            'inicio' => '2026-09-25', 'fim' => '2026-09-26', 'status' => 'em_andamento']);

        session(['event_id' => $this->evento->id]);

        $this->coord = $this->pessoaCom(array_keys(\Database\Seeders\PermissionSeeder::CATALOG));
    }

    private function pessoaCom(array $slugs): User
    {
        $perfil = SystemRole::create(['church_id' => 1, 'name' => 'P'.uniqid()]);
        $perfil->permissions()->sync(Permission::whereIn('slug', $slugs)->pluck('id'));

        $u = User::create(['name' => 'Operador', 'email' => 'i'.uniqid().'@ipccg.org.br',
            'password' => 'segredo123']);

        Membership::create(['user_id' => $u->id, 'church_id' => 1,
            'system_role_id' => $perfil->id, 'status' => true, 'created_at' => now()]);

        return $u;
    }

    private function servico(): ImportService
    {
        return app(ImportService::class);
    }

    private function importar(string $texto): array
    {
        $lido = $this->servico()->analisar($texto);

        return $this->servico()->importar(
            $this->evento, $lido['linhas'],
            $this->servico()->mapear($lido['cabecalho']),
            $lido['cabecalho'], $this->coord->id,
        );
    }

    private function planilha(string $delim = "\t"): string
    {
        $linhas = [
            ['Submission Date', 'Nome completo', 'WhatsApp', 'E-mail', 'Igreja que você congrega', 'Cidade'],
            ['Sep 14, 2026', 'Christiane De Oliveira', '(67) 98114-5793', 'crica'.uniqid().'@exemplo.com', 'Assembleia', 'Campo Grande'],
            ['Sep 13, 2026', 'Mateus Slavec', '(55) 67981-6485', 'mateus'.uniqid().'@exemplo.com', 'IPCCG', 'Campo Grande'],
        ];

        return collect($linhas)->map(fn ($l) => implode($delim, $l))->join("\n");
    }

    // ─────────────────────────── leitura ───────────────────────────

    public function test_le_tab_ponto_e_virgula_e_virgula(): void
    {
        foreach (["\t", ';', ','] as $delim) {
            $lido = $this->servico()->analisar($this->planilha($delim));

            $this->assertSame($delim, $lido['delimitador'], "não detectou: [{$delim}]");
            $this->assertCount(2, $lido['linhas']);
            $this->assertSame('Nome completo', $lido['cabecalho'][1]);
        }
    }

    /** O BOM do Excel gruda no primeiro cabeçalho e faz "Nome" não casar com nada. */
    public function test_o_bom_do_excel_nao_atrapalha_o_cabecalho(): void
    {
        $lido = $this->servico()->analisar("\xEF\xBB\xBF".$this->planilha());

        $this->assertSame('Submission Date', $lido['cabecalho'][0]);
        $this->assertSame(0, $this->servico()->mapear($lido['cabecalho'])['inscrita_em']);
    }

    public function test_adivinha_as_colunas_pelo_cabecalho(): void
    {
        $mapa = $this->servico()->mapear(
            ['Submission Date', 'Nome completo', 'WhatsApp', 'E-mail', 'Igreja', 'Cidade']
        );

        $this->assertSame(1, $mapa['nome']);
        $this->assertSame(3, $mapa['email']);
        $this->assertSame(2, $mapa['telefone']);
        $this->assertSame(0, $mapa['inscrita_em']);
        $this->assertNull($mapa['cpf']);
    }

    /**
     * O delimitador sai do CABEÇALHO, não do arquivo todo: nome de igreja com
     * vírgula ("Assembleia de Deus, Missões") apareceria mais que o tab e
     * sequestraria a decisão.
     */
    public function test_virgula_dentro_do_dado_nao_confunde_o_delimitador(): void
    {
        $texto = "Nome completo\tE-mail\n"
            ."Fulano de Tal\tf".uniqid()."@exemplo.com\n"
            ."Ciclana, a Segunda\tc".uniqid()."@exemplo.com";

        $this->assertSame("\t", $this->servico()->analisar($texto)['delimitador']);
    }

    public function test_planilha_so_com_cabecalho_e_recusada(): void
    {
        $this->expectExceptionMessage('pelo menos uma linha');

        $this->servico()->analisar("Nome completo\tE-mail");
    }

    // ─────────────────────────── importação ───────────────────────────

    public function test_importa_e_cria_as_inscricoes(): void
    {
        $r = $this->importar($this->planilha());

        $this->assertSame(2, $r['importados']);
        $this->assertSame(0, $r['repetidos']);
        $this->assertEmpty($r['erros']);

        $this->assertSame(2, Registration::where('event_id', $this->evento->id)->count());
        $this->assertSame('importacao', Registration::where('event_id', $this->evento->id)->first()->origem);
    }

    /** A data do JotForm vem em inglês, e é ela que diz de onde continuar depois. */
    public function test_preserva_a_data_de_inscricao_da_planilha(): void
    {
        $this->importar($this->planilha());

        $christiane = Registration::where('event_id', $this->evento->id)
            ->where('nome', 'Christiane De Oliveira')->first();

        $this->assertSame('2026-09-14', $christiane->inscrita_em->toDateString());
    }

    /** Guardar com o +55 na frente quebraria qualquer WhatsApp futuro. */
    public function test_tira_o_codigo_do_pais_quando_ele_e_inequivoco(): void
    {
        $texto = "Nome completo\tWhatsApp\nCom Pais\t+55 (67) 98114-5793";

        $this->importar($texto);

        $this->assertSame('67981145793',
            Registration::where('event_id', $this->evento->id)->first()->telefone);
    }

    /**
     * ⚠ E NÃO tira quando é ambíguo. `(55) 67981-6485` tem onze dígitos: pode
     * ser o país digitado no campo do DDD — sete linhas da planilha real vieram
     * assim — ou o DDD 55, que existe (Santa Maria/RS). Um palpite errado aqui
     * apaga o DDD de quem mora lá, e ninguém repara até a ligação não
     * completar. Fica como veio, só sem a pontuação.
     */
    public function test_ddd_55_nao_e_confundido_com_codigo_do_pais(): void
    {
        $this->importar($this->planilha());

        $mateus = Registration::where('event_id', $this->evento->id)
            ->where('nome', 'Mateus Slavec')->first();

        $this->assertSame('55679816485', $mateus->telefone);
    }

    /** As colunas não mapeadas viram respostas, com a chave derivada do cabeçalho. */
    public function test_colunas_extras_viram_respostas(): void
    {
        $this->importar($this->planilha());

        $respostas = Registration::where('event_id', $this->evento->id)->first()->respostas;

        $this->assertSame('Campo Grande', $respostas['cidade']);
        $this->assertArrayHasKey('igreja_que_voce_congrega', $respostas);
    }

    /**
     * O erro mais provável de todos: reimportar por engano. O banco recusa a
     * segunda inscrição ativa, e isso é CONTADO, não derruba o lote.
     */
    public function test_reimportar_nao_duplica(): void
    {
        $planilha = $this->planilha();

        $this->importar($planilha);
        $segunda = $this->importar($planilha);

        $this->assertSame(0, $segunda['importados']);
        $this->assertSame(2, $segunda['repetidos']);
        $this->assertEmpty($segunda['erros']);
        $this->assertSame(2, Registration::where('event_id', $this->evento->id)->count());
    }

    /**
     * ⚠ O CASO QUE SÓ APARECEU COM A PLANILHA REAL.
     *
     * Três casais compartilham e-mail nas 61 inscrições. O cônjuge nasce com
     * par_people.email NULL — de propósito, para não tomar o endereço de
     * ninguém — e o efeito colateral é ficar sem chave própria. Sem consultar
     * o histórico antes de criar, cada reimportação criava OUTRO cônjuge, em
     * silêncio, um por rodada.
     *
     * O primeiro ensaio com o arquivo de verdade devolveu "4 importados" numa
     * reimportação que devia devolver zero. Os testes sintéticos passavam
     * todos: nenhum deles tinha duas pessoas na mesma caixa postal.
     */
    public function test_casal_com_email_compartilhado_nao_duplica_ao_reimportar(): void
    {
        $email = 'casal'.uniqid().'@exemplo.com';

        $planilha = "Nome completo\tE-mail\n"
            ."Christiane De Oliveira\t{$email}\n"
            ."Carlos De Oliveira\t{$email}";

        $primeira = $this->importar($planilha);

        $this->assertSame(2, $primeira['importados'], 'os dois são pessoas diferentes');

        $segunda = $this->importar($planilha);

        $this->assertSame(0, $segunda['importados'], 'reimportar não pode criar ninguém');
        $this->assertSame(2, $segunda['repetidos']);
        $this->assertSame(2, Registration::where('event_id', $this->evento->id)->count());
    }

    /** E o cônjuge continua sendo OUTRA pessoa — a correção não pode fundir os dois. */
    public function test_casal_com_email_compartilhado_continua_sendo_duas_pessoas(): void
    {
        $email = 'casal'.uniqid().'@exemplo.com';

        $this->importar("Nome completo\tE-mail\n"
            ."Christiane De Oliveira\t{$email}\n"
            ."Carlos De Oliveira\t{$email}");

        $pessoas = Registration::where('event_id', $this->evento->id)
            ->pluck('person_id')->unique();

        $this->assertCount(2, $pessoas, 'marido e mulher não podem virar a mesma pessoa');
    }

    /** E-mail impossível não descarta a pessoa — ela entra e a portaria acha pelo nome. */
    public function test_email_invalido_nao_descarta_a_pessoa(): void
    {
        $texto = "Nome completo\tE-mail\nSem Email Valido\tisso-nao-e-email";

        $r = $this->importar($texto);

        $this->assertSame(1, $r['importados']);

        $inscrito = Registration::where('event_id', $this->evento->id)->first();
        $this->assertNull($inscrito->email);
        $this->assertFalse((bool) $inscrito->email_valido);
    }

    public function test_linha_em_branco_e_ignorada_sem_erro(): void
    {
        $texto = "Nome completo\tE-mail\n\t\nFulano Existente\tf".uniqid()."@exemplo.com\n\t";

        $r = $this->importar($texto);

        $this->assertSame(1, $r['importados']);
        $this->assertEmpty($r['erros']);
    }

    /** Sem a coluna do nome não dá para inscrever ninguém — recusa antes de começar. */
    public function test_sem_coluna_de_nome_recusa(): void
    {
        $lido = $this->servico()->analisar("Cidade\tE-mail\nCampo Grande\tx@y.com");

        $this->expectExceptionMessage('qual coluna tem o nome');

        $this->servico()->importar($this->evento, $lido['linhas'],
            ['nome' => null], $lido['cabecalho'], $this->coord->id);
    }

    // ─────────────────────────── a tela ───────────────────────────

    public function test_a_tela_le_confere_e_importa(): void
    {
        Livewire::actingAs($this->coord)->test('participantes.importar')
            ->set('texto', $this->planilha())
            ->call('analisar')
            ->assertSet('analisado', true)
            ->assertSet('mapa.nome', 1)
            ->call('importar')
            ->assertHasNoErrors();

        $this->assertSame(2, Registration::where('event_id', $this->evento->id)->count());
    }

    /** Ler não grava nada: o passo 1 existe para conferir antes. */
    public function test_analisar_nao_grava_inscricao(): void
    {
        Livewire::actingAs($this->coord)->test('participantes.importar')
            ->set('texto', $this->planilha())
            ->call('analisar');

        $this->assertSame(0, Registration::where('event_id', $this->evento->id)->count());
    }

    /**
     * A rota e o mount() barram juntos — por isso não dá para montar o
     * componente e chamar analisar(): o mount aborta antes de existir
     * componente para chamar. A defesa em duas camadas continua nos métodos,
     * para o caso de a tela um dia abrir para quem só consulta.
     */
    public function test_importar_exige_permissao(): void
    {
        $porteiro = $this->pessoaCom(['eventos.ver', 'participantes.ver', 'participantes.checkin']);

        $this->actingAs($porteiro)->get(route('participantes.importar'))->assertForbidden();

        $this->assertSame(0, Registration::where('event_id', $this->evento->id)->count());
    }
}
