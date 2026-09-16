<?php

namespace App\Services\Participantes;

use App\Models\Participantes\Registration;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Storage;

/**
 * O QR da credencial.
 *
 * ── O QUE VAI DENTRO ──────────────────────────────────────────────────
 * Só a URL curta com o token: https://.../eventos/p/{token}
 *
 * Nada de JSON nem payload assinado. Três razões, e a primeira é a que decide:
 *
 * 1. A CÂMERA NATIVA de qualquer celular abre uma URL. Isso dispensa embarcar
 *    um leitor de QR no app — que seria a maior fonte de risco técnico do
 *    prazo, e que morre quando a internet oscila.
 * 2. Menos caracteres = menos módulos = QR mais legível em tela rachada e com
 *    pouca luz. Payload assinado (HMAC/JWT) engordaria o código sem ganhar
 *    nada: o token aleatório já é impossível de adivinhar.
 * 3. Nenhum dado pessoal viaja no QR. Crachá perdido não vaza nome nem CPF, e
 *    um QR adulterado não consegue exibir um nome falso — o nome que a portaria
 *    vê vem do banco.
 *
 * A anti-falsificação vem de fora: /p/{token} exige sessão com
 * `participantes.checkin`. O token não é credencial, é chave de busca.
 *
 * ── DOIS FORMATOS, DOIS DESTINOS ──────────────────────────────────────
 * PNG por GD para o e-mail (cliente de e-mail não renderiza SVG), e SVG inline
 * para a credencial impressa (vetor não serrilha no papel).
 */
class QrService
{
    /** Erro nível M: tolera ~15% de dano — dobra de papel, dedo na tela. */
    private const ECC = EccLevel::M;

    public function url(Registration $inscricao): string
    {
        return route('participantes.scan', ['token' => $inscricao->token]);
    }

    /** SVG inline para a credencial. Não depende de extensão nenhuma. */
    public function svg(Registration $inscricao): string
    {
        return (new QRCode(new QROptions([
            'outputInterface'      => QRMarkupSVG::class,
            'eccLevel'             => self::ECC,
            'outputBase64'         => false,
            'quietzoneSize'        => 2,
            'svgUseFillAttributes' => false,
            'cssClass'             => 'qr',
        ])))->render($this->url($inscricao));
    }

    /** PNG binário para embutir no e-mail. Precisa de gd (obrigatória no DEPLOY.md §7). */
    public function png(Registration $inscricao, int $escala = 8): string
    {
        return (new QRCode(new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'eccLevel'        => self::ECC,
            'scale'           => $escala,
            'outputBase64'    => false,
            'quietzoneSize'   => 2,
        ])))->render($this->url($inscricao));
    }

    /**
     * O PNG gravado em disco, gerado uma vez só.
     *
     * Fica em storage/app/public, que o deploy por FTPS preserva (storage está
     * no exclude) — então reenviar a credencial não regera a imagem, e o
     * arquivo sobrevive a qualquer publicação de código.
     */
    public function caminhoDoPng(Registration $inscricao): string
    {
        $caminho = "participantes/{$inscricao->event_id}/{$inscricao->token}.png";

        if (! Storage::disk('public')->exists($caminho)) {
            Storage::disk('public')->put($caminho, $this->png($inscricao));
        }

        return $caminho;
    }

    /** O caminho absoluto, que o Mailable precisa para anexar inline. */
    public function arquivoDoPng(Registration $inscricao): string
    {
        return Storage::disk('public')->path($this->caminhoDoPng($inscricao));
    }
}
