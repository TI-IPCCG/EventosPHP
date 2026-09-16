{{-- Table-based com estilo embutido: cliente de e-mail não entende CSS externo
     nem flex. Mesmo molde do reset-password, cores do Emerald Archive. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;background:#F7F7F6;font-family:Arial,Helvetica,sans-serif;color:#212529">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F7F7F6;padding:24px 0">
        <tr><td align="center">
            <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="max-width:480px;width:100%;background:#FFFFFF;border-radius:14px;overflow:hidden;border:1px solid #D9DBD6">
                <tr><td style="background:#334C3B;padding:22px 26px">
                    <div style="color:#FFFFFF;font-size:20px;font-weight:bold;font-family:Georgia,serif">IPCCG</div>
                    <div style="color:#BD7522;font-size:13px">{{ $inscricao->event->nome }}</div>
                </td></tr>

                <tr><td style="padding:26px 26px 8px">
                    <p style="margin:0 0 12px;font-size:16px">Olá, {{ $inscricao->nome }}.</p>
                    <p style="margin:0 0 4px;font-size:15px;line-height:1.6">
                        Sua inscrição está confirmada. Apresente o código abaixo na entrada —
                        pode ser direto do celular, não precisa imprimir.
                    </p>
                </td></tr>

                {{-- O QR, embutido: funciona sem internet e sem "exibir imagens" --}}
                <tr><td align="center" style="padding:6px 26px 0">
                    <img src="{{ $message->embed(app(App\Services\Participantes\QrService::class)->arquivoDoPng($inscricao)) }}"
                         alt="QR Code da credencial" width="220" height="220"
                         style="display:block;border:0;width:220px;height:220px">
                </td></tr>

                {{-- O código grande: a saída quando o QR não lê --}}
                <tr><td align="center" style="padding:10px 26px 0">
                    <div style="font-family:'Courier New',monospace;font-size:30px;font-weight:bold;letter-spacing:3px;color:#334C3B">
                        {{ $inscricao->codigo }}
                    </div>
                    <div style="font-size:13px;color:#5A7161;margin-top:4px">
                        Se o QR não ler, informe este código.
                    </div>
                </td></tr>

                <tr><td style="padding:20px 26px 0">
                    @if ($textoDoEvento)
                        <p style="margin:0 0 14px;font-size:14px;line-height:1.6">{{ $textoDoEvento }}</p>
                    @endif

                    <p style="margin:0 0 6px;font-size:14px"><strong>Quando</strong></p>
                    <p style="margin:0 0 16px;font-size:14px;color:#5A7161">
                        {{ $inscricao->event->inicio->format('d/m/Y') }}
                        @if ($inscricao->event->fim && ! $inscricao->event->fim->isSameDay($inscricao->event->inicio))
                            a {{ $inscricao->event->fim->format('d/m/Y') }}
                        @endif
                        @if ($inscricao->event->local) · {{ $inscricao->event->local }} @endif
                    </p>

                    <p style="margin:0 0 22px">
                        <a href="{{ $urlCredencial }}" style="display:inline-block;background:#334C3B;color:#FFFFFF;text-decoration:none;padding:12px 22px;border-radius:8px;font-size:15px;font-weight:bold">Ver em tela cheia ou imprimir</a>
                    </p>

                    <p style="margin:0 0 20px;font-size:12px;color:#5A7161;word-break:break-all">
                        Se o botão não funcionar, copie e cole no navegador:<br>{{ $urlCredencial }}
                    </p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
