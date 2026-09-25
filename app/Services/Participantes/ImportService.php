<?php

namespace App\Services\Participantes;

use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Importar a planilha de inscrições.
 *
 * ── O QUE ELE ENFRENTA ────────────────────────────────────────────────
 * A origem é um JotForm exportado à mão, então nada é garantido: o
 * delimitador varia (tab, ponto-e-vírgula, vírgula), o arquivo pode vir com
 * BOM, a data vem em inglês ("Sep 14, 2026"), telefone tem `(55)` que é país
 * e não DDD, e os cabeçalhos mudam de um formulário para outro.
 *
 * ── O QUE ELE NÃO FAZ, DE PROPÓSITO ───────────────────────────────────
 * Não decide quem é quem: isso é do PersonResolver, que já trata casal com
 * e-mail compartilhado, CPF que muda de dono e nome grafado diferente. E não
 * impede reimportação: quem impede é o UNIQUE de `pessoa_ativa` no banco, e
 * aqui a segunda tentativa vira a contagem "já estava inscrita" em vez de
 * derrubar o lote. Reimportar por engano é o erro mais provável de todos.
 *
 * Linha com problema NÃO interrompe as outras: no dia do evento, parar tudo
 * por causa de um e-mail torto é o pior resultado possível.
 */
class ImportService
{
    /** Cabeçalhos que identificam cada campo, por pedaço do nome. */
    private const PISTAS = [
        'nome'        => ['nome', 'name'],
        'email'       => ['mail'],
        'telefone'    => ['whats', 'telefone', 'fone', 'celular', 'phone'],
        'cpf'         => ['cpf', 'documento'],
        'inscrita_em' => ['submission', 'data', 'date', 'envio'],
    ];

    public function __construct(private RegistrationService $inscricoes) {}

    /**
     * Quebra o texto em cabeçalho + linhas, adivinhando o delimitador.
     *
     * @return array{cabecalho: array<string>, linhas: array<array<string>>, delimitador: string}
     */
    public function analisar(string $texto): array
    {
        // BOM do Excel/Windows gruda no primeiro cabeçalho e faz "Nome" deixar
        // de casar com qualquer pista — some antes de tudo.
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', trim($texto));

        if ($texto === '') {
            throw new RuntimeException('Cole a planilha ou escolha um arquivo.');
        }

        $linhasBrutas = preg_split('/\r\n|\r|\n/', $texto);
        $delimitador  = $this->delimitador($linhasBrutas[0]);

        $todas = collect($linhasBrutas)
            ->filter(fn ($l) => trim($l) !== '')
            ->map(fn ($l) => array_map('trim', str_getcsv($l, $delimitador, '"', '\\')))
            ->values();

        if ($todas->count() < 2) {
            throw new RuntimeException('Precisa do cabeçalho e de pelo menos uma linha.');
        }

        return [
            'cabecalho'   => $todas->first(),
            'linhas'      => $todas->slice(1)->values()->all(),
            'delimitador' => $delimitador,
        ];
    }

    /**
     * O delimitador é o candidato que mais aparece NO CABEÇALHO.
     *
     * Olhar o arquivo todo seria pior: nome de igreja com vírgula ("Assembleia
     * de Deus, Missões") apareceria mais que o tab e sequestraria a decisão.
     */
    private function delimitador(string $cabecalho): string
    {
        $placar = collect(["\t", ';', ','])
            ->mapWithKeys(fn ($d) => [$d => substr_count($cabecalho, $d)]);

        return $placar->filter()->isEmpty() ? "\t" : $placar->sortDesc()->keys()->first();
    }

    /**
     * Adivinha qual coluna é o quê, pelo cabeçalho.
     *
     * É palpite, e a tela deixa corrigir — mas acerta o caso comum e poupa
     * cinco decisões de quem está com o evento começando.
     *
     * @param  array<string>  $cabecalho
     * @return array<string, int|null>  campo => índice da coluna
     */
    public function mapear(array $cabecalho): array
    {
        $normalizado = array_map(fn ($c) => Str::lower(Str::ascii($c)), $cabecalho);
        $mapa        = [];

        foreach (self::PISTAS as $campo => $pistas) {
            $mapa[$campo] = null;

            foreach ($normalizado as $i => $titulo) {
                if (in_array($i, $mapa, true)) {
                    continue;      // coluna já falada por outro campo
                }

                foreach ($pistas as $pista) {
                    if (str_contains($titulo, $pista)) {
                        $mapa[$campo] = $i;
                        continue 3;
                    }
                }
            }
        }

        return $mapa;
    }

    /**
     * Importa as linhas.
     *
     * @param  array<array<string>>  $linhas
     * @param  array<string, int|null>  $mapa
     * @param  array<string>  $cabecalho
     * @return array{importados:int, repetidos:int, erros:array<array{linha:int, nome:string, motivo:string}>}
     */
    public function importar(
        Event $evento,
        array $linhas,
        array $mapa,
        array $cabecalho,
        ?int $operadorId = null,
    ): array {
        if (($mapa['nome'] ?? null) === null) {
            throw new RuntimeException('Diga qual coluna tem o nome — sem isso não dá para inscrever ninguém.');
        }

        $importados = 0;
        $repetidos  = 0;
        $erros      = [];

        foreach ($linhas as $n => $colunas) {
            $nome = trim($this->valor($colunas, $mapa['nome']));

            if ($nome === '') {
                continue;          // linha em branco no fim da planilha é rotina
            }

            try {
                $this->inscricoes->inscrever(
                    $evento,
                    $this->dados($colunas, $mapa, $cabecalho, $nome),
                    'importacao',
                    $operadorId,
                );

                $importados++;
            } catch (RuntimeException $e) {
                // "já está inscrita" é resultado esperado de reimportação, não
                // erro: quem reimporta por engano precisa ver isso separado do
                // que realmente falhou.
                str_contains($e->getMessage(), 'já está inscrita')
                    ? $repetidos++
                    : $erros[] = ['linha' => $n + 2, 'nome' => $nome, 'motivo' => $e->getMessage()];
            } catch (\Throwable $e) {
                $erros[] = ['linha' => $n + 2, 'nome' => $nome, 'motivo' => $e->getMessage()];
            }
        }

        return ['importados' => $importados, 'repetidos' => $repetidos, 'erros' => $erros];
    }

    /** @return array<string, mixed> */
    private function dados(array $colunas, array $mapa, array $cabecalho, string $nome): array
    {
        $dados = [
            'nome'     => $nome,
            'email'    => $this->email($this->valor($colunas, $mapa['email'] ?? null)),
            'telefone' => $this->telefone($this->valor($colunas, $mapa['telefone'] ?? null)),
            'cpf'      => preg_replace('/\D/', '', $this->valor($colunas, $mapa['cpf'] ?? null)) ?: null,
        ];

        if (($data = $this->data($this->valor($colunas, $mapa['inscrita_em'] ?? null))) !== null) {
            $dados['inscrita_em'] = $data;
        }

        // Toda coluna não mapeada vira resposta, com a chave derivada do
        // cabeçalho. É a ponte com o formulário da fase 2: a pergunta criada lá
        // gera a MESMA chave e encontra o dado já gravado, sem migração.
        $usadas    = array_filter($mapa, fn ($i) => $i !== null);
        $respostas = [];

        foreach ($cabecalho as $i => $titulo) {
            if (in_array($i, $usadas, true) || trim($titulo) === '') {
                continue;
            }

            if (filled($valor = $this->valor($colunas, $i))) {
                $respostas[Str::slug($titulo, '_')] = $valor;
            }
        }

        $dados['respostas'] = $respostas ?: null;

        return $dados;
    }

    private function valor(array $colunas, ?int $indice): string
    {
        return $indice === null ? '' : trim($colunas[$indice] ?? '');
    }

    /** E-mail impossível não descarta a pessoa: ela entra sem e-mail e entra na portaria pelo nome. */
    private function email(string $bruto): ?string
    {
        $limpo = Str::lower(trim($bruto));

        return filter_var($limpo, FILTER_VALIDATE_EMAIL) ? $limpo : null;
    }

    /**
     * `(55) 67981-6485` é país + número, não DDD + número — sete linhas da
     * planilha real vieram assim. Guardar com o 55 na frente quebraria
     * qualquer tentativa futura de mandar WhatsApp.
     */
    private function telefone(string $bruto): ?string
    {
        $digitos = preg_replace('/\D/', '', $bruto);

        if ($digitos === '') {
            return null;
        }

        if (str_starts_with($digitos, '55') && mb_strlen($digitos) >= 12) {
            $digitos = mb_substr($digitos, 2);
        }

        return $digitos;
    }

    /** A data do JotForm vem em inglês ("Sep 14, 2026"); a digitada, em pt-BR. */
    private function data(string $bruto): ?Carbon
    {
        if (trim($bruto) === '') {
            return null;
        }

        foreach (['d/m/Y', 'd/m/Y H:i', 'd/m/Y H:i:s'] as $formato) {
            try {
                return Carbon::createFromFormat($formato, trim($bruto));
            } catch (\Throwable) {
                // segue para o próximo palpite
            }
        }

        try {
            return Carbon::parse($bruto);     // "Sep 14, 2026" e ISO
        } catch (\Throwable) {
            return null;                       // data ilegível não impede a inscrição
        }
    }
}
