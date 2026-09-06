{{-- E-mail em tabela e estilo embutido de propósito: cliente de e-mail não
     entende CSS externo nem flex. As cores são as do Emerald Archive. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;background:#F7F7F6;font-family:Arial,Helvetica,sans-serif;color:#212529">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F7F7F6;padding:24px 0">
        <tr><td align="center">
            <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="max-width:480px;width:100%;background:#FFFFFF;border-radius:14px;overflow:hidden;border:1px solid #D9DBD6">
                <tr><td style="background:#334C3B;padding:22px 26px">
                    <div style="color:#FFFFFF;font-size:20px;font-weight:bold;font-family:Georgia,serif">IPCCG</div>
                    <div style="color:#BD7522;font-size:13px">Eventos</div>
                </td></tr>
                <tr><td style="padding:26px">
                    <p style="margin:0 0 12px;font-size:16px">Olá, {{ $name }}.</p>
                    <p style="margin:0 0 18px;font-size:15px;line-height:1.6">
                        Recebemos um pedido para redefinir a senha do seu acesso.
                        Clique no botão abaixo para criar uma nova senha:
                    </p>
                    <p style="margin:0 0 22px">
                        <a href="{{ $url }}" style="display:inline-block;background:#334C3B;color:#FFFFFF;text-decoration:none;padding:12px 22px;border-radius:8px;font-size:15px;font-weight:bold">Redefinir minha senha</a>
                    </p>
                    <p style="margin:0 0 8px;font-size:13px;color:#5A7161;line-height:1.6">
                        Este link expira em 60 minutos. Se você não pediu isso, pode ignorar
                        este e-mail — sua senha continua a mesma.
                    </p>
                    <p style="margin:16px 0 0;font-size:12px;color:#5A7161;word-break:break-all">
                        Se o botão não funcionar, copie e cole no navegador:<br>{{ $url }}
                    </p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
