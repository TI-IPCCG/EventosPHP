<?php

namespace Database\Seeders;

use App\Models\Livraria\Category;
use App\Models\Livraria\CategoryField;
use App\Models\Livraria\WriteoffReason;
use Illuminate\Database\Seeder;

/**
 * Dados de partida de uma congregação: categorias com seus campos e os motivos
 * de baixa. Tudo editável pela interface depois — isto é só o ponto de partida
 * para ninguém começar com a tela vazia.
 *
 * Recebe o church_id porque roda por congregação, não globalmente.
 */
class CatalogoSeeder extends Seeder
{
    public function run(int $churchId = 1): void
    {
        $categorias = [
            [
                'nome' => 'Livro', 'slug' => 'livro', 'usa_variacao' => false, 'ordem' => 1,
                'campos' => [
                    ['chave' => 'autor',       'rotulo' => 'Autor',             'tipo' => 'texto',   'obrigatorio' => true,  'mostrar_na_lista' => true],
                    ['chave' => 'editora',     'rotulo' => 'Editora',           'tipo' => 'texto'],
                    ['chave' => 'num_paginas', 'rotulo' => 'Número de páginas', 'tipo' => 'inteiro'],
                    ['chave' => 'isbn',        'rotulo' => 'ISBN',              'tipo' => 'texto'],
                    ['chave' => 'ano',         'rotulo' => 'Ano de publicação', 'tipo' => 'inteiro'],
                ],
            ],
            [
                // Tamanho NÃO entra como campo: tamanho divide estoque, então é variação.
                'nome' => 'Camiseta', 'slug' => 'camiseta', 'usa_variacao' => true,
                'rotulo_variacao' => 'Tamanho', 'ordem' => 2,
                'campos' => [
                    ['chave' => 'modelo',   'rotulo' => 'Modelo',   'tipo' => 'texto', 'obrigatorio' => true, 'mostrar_na_lista' => true],
                    ['chave' => 'marca',    'rotulo' => 'Marca',    'tipo' => 'texto'],
                    ['chave' => 'material', 'rotulo' => 'Material', 'tipo' => 'texto'],
                    ['chave' => 'cor',      'rotulo' => 'Cor',      'tipo' => 'selecao',
                     'opcoes' => ['Preta', 'Branca', 'Azul', 'Cinza'], 'mostrar_na_lista' => true],
                ],
            ],
            ['nome' => 'Outro', 'slug' => 'outro', 'usa_variacao' => false, 'ordem' => 9, 'campos' => []],
        ];

        foreach ($categorias as $dados) {
            $campos = $dados['campos'];
            unset($dados['campos']);

            $cat = Category::withoutGlobalScopes()->updateOrCreate(
                ['church_id' => $churchId, 'slug' => $dados['slug']],
                $dados + ['church_id' => $churchId, 'ativo' => true, 'created_at' => now()],
            );

            foreach ($campos as $i => $campo) {
                CategoryField::updateOrCreate(
                    ['category_id' => $cat->id, 'chave' => $campo['chave']],
                    $campo + ['ordem' => $i + 1],
                );
            }
        }

        // gera_custo = false só para o exemplar que a própria editora doou:
        // esse não é pago porque não saiu do consignado.
        $motivos = [
            ['nome' => 'Sorteio',           'gera_custo' => true],
            ['nome' => 'Cortesia',          'gera_custo' => true],
            ['nome' => 'Doação',            'gera_custo' => true],
            ['nome' => 'Perda ou dano',     'gera_custo' => true],
            ['nome' => 'Doação da editora', 'gera_custo' => false],
        ];

        foreach ($motivos as $m) {
            WriteoffReason::withoutGlobalScopes()->updateOrCreate(
                ['church_id' => $churchId, 'nome' => $m['nome']],
                ['gera_custo' => $m['gera_custo'], 'ativo' => true],
            );
        }
    }
}
