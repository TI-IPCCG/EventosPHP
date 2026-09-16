<?php

namespace App\Mail;

use App\Models\Participantes\Registration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A credencial do participante.
 *
 * TRÊS CAMINHOS REDUNDANTES na mesma mensagem, porque no dia do evento
 * qualquer um deles pode falhar:
 *
 *  1. o QR como imagem EMBUTIDA (CID) — e não <img src="url">, que vazaria o
 *     token para quem hospeda a imagem e sumiria sem internet. Embutido, o
 *     e-mail funciona offline, que é o caso de quem já o baixou;
 *  2. o CÓDIGO em corpo grande, com a instrução escrita. É a mitigação de maior
 *     valor por linha do plano inteiro: resolve tela rachada, câmera ruim, QR
 *     borrado na impressão e celular sem bateria emprestado do vizinho;
 *  3. o LINK da credencial, que abre a versão imprimível.
 */
class CredencialMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Registration $inscricao,
        public string $urlCredencial,
        public ?string $textoDoEvento = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Sua credencial — '.$this->inscricao->event->nome,
        );
    }

    /**
     * A imagem entra pela view, com $message->embed(): ele anexa o arquivo e
     * devolve o cid: na mesma chamada.
     *
     * ⚠ Não declarar attachments() aqui também — os dois caminhos somados
     * anexariam o PNG duas vezes na mesma mensagem.
     */
    public function content(): Content
    {
        return new Content(view: 'emails.credencial');
    }
}
