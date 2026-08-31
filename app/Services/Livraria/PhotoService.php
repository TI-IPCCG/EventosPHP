<?php

namespace App\Services\Livraria;

use App\Models\Livraria\Product;
use App\Models\Livraria\ProductPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Fotos do item, com miniatura.
 *
 * A miniatura não é enfeite: a mesa opera em 4G no meio de um evento, e mandar
 * a foto original em cada linha da listagem derruba a tela de venda. Geramos na
 * subida com a GD, que o DEPLOY.md §8 já exige no servidor.
 *
 * Os arquivos vão para o disco `public` (storage/app/public/livraria), servido
 * pelo symlink do `php artisan storage:link`. O deploy por FTPS exclui
 * storage/**, então as fotos sobrevivem aos deploys.
 */
class PhotoService
{
    private const LARGURA_MAXIMA = 1200;
    private const LARGURA_THUMB  = 240;
    private const QUALIDADE      = 82;

    public function adicionar(Product $produto, UploadedFile $arquivo): ProductPhoto
    {
        $imagem = $this->abrir($arquivo);
        $base   = 'livraria/'.$produto->id.'/'.uniqid();

        $grande = $this->redimensionarEGravar($imagem, self::LARGURA_MAXIMA, "{$base}.jpg");
        $thumb  = $this->redimensionarEGravar($imagem, self::LARGURA_THUMB,  "{$base}-thumb.jpg");

        imagedestroy($imagem);

        return DB::transaction(function () use ($produto, $grande, $thumb) {
            // A primeira foto do item vira a capa sozinha: ninguém deveria
            // precisar de dois passos para o caso óbvio.
            $primeira = ! $produto->photos()->exists();

            return ProductPhoto::create([
                'product_id'    => $produto->id,
                'caminho'       => $grande['caminho'],
                'caminho_thumb' => $thumb['caminho'],
                'capa'          => $primeira,
                'ordem'         => ($produto->photos()->max('ordem') ?? 0) + 1,
                'bytes'         => $grande['bytes'],
                'largura'       => $grande['largura'],
                'altura'        => $grande['altura'],
                'created_at'    => now(),
            ]);
        });
    }

    /**
     * Troca a capa. O banco só aceita uma capa por item (índice único sobre
     * coluna gerada), então zerar a anterior e marcar a nova precisa acontecer
     * na MESMA transação.
     */
    public function definirCapa(ProductPhoto $foto): void
    {
        DB::transaction(function () use ($foto) {
            ProductPhoto::where('product_id', $foto->product_id)
                ->where('capa', true)
                ->update(['capa' => false]);

            $foto->update(['capa' => true]);
        });
    }

    /** Remove a foto e os arquivos. Se era a capa, promove a próxima. */
    public function remover(ProductPhoto $foto): void
    {
        DB::transaction(function () use ($foto) {
            $eraCapa   = $foto->capa;
            $produtoId = $foto->product_id;

            Storage::disk('public')->delete(array_filter([$foto->caminho, $foto->caminho_thumb]));
            $foto->delete();

            if ($eraCapa) {
                $proxima = ProductPhoto::where('product_id', $produtoId)->orderBy('ordem')->first();
                $proxima?->update(['capa' => true]);
            }
        });
    }

    /** @return \GdImage */
    private function abrir(UploadedFile $arquivo)
    {
        $dados  = file_get_contents($arquivo->getRealPath());
        $imagem = @imagecreatefromstring($dados);

        if ($imagem === false) {
            throw new RuntimeException('Arquivo de imagem inválido.');
        }

        return $imagem;
    }

    /**
     * @param  \GdImage  $imagem
     * @return array{caminho: string, bytes: int, largura: int, altura: int}
     */
    private function redimensionarEGravar($imagem, int $larguraAlvo, string $caminho): array
    {
        $largura = imagesx($imagem);
        $altura  = imagesy($imagem);

        // Imagem menor que o alvo não é ampliada: esticar só piora e pesa mais.
        $escala   = min(1, $larguraAlvo / $largura);
        $novaLarg = (int) round($largura * $escala);
        $novaAlt  = (int) round($altura * $escala);

        $destino = imagecreatetruecolor($novaLarg, $novaAlt);
        imagefill($destino, 0, 0, imagecolorallocate($destino, 255, 255, 255));  // PNG transparente vira fundo branco
        imagecopyresampled($destino, $imagem, 0, 0, 0, 0, $novaLarg, $novaAlt, $largura, $altura);

        ob_start();
        imagejpeg($destino, null, self::QUALIDADE);
        $binario = ob_get_clean();
        imagedestroy($destino);

        Storage::disk('public')->put($caminho, $binario);

        return [
            'caminho' => $caminho,
            'bytes'   => strlen($binario),
            'largura' => $novaLarg,
            'altura'  => $novaAlt,
        ];
    }
}
