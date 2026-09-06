# Deploy — App Eventos (cPanel)

App Laravel 12 + Livewire 4, PHP **8.2**, dois schemas MySQL
(`ipccgorg_Eventos` operacional + `ipccgorg_ModernApps` central).

> **Regra de ouro:** só a pasta `public/` do Laravel pode ficar acessível pela
> web. Todo o resto (`app`, `config`, `vendor`, `.env`) fica **fora** da
> `public_html`.

Espelha o deploy do **Escala de Membros**. As diferenças que importam estão
marcadas com 🆕 — a principal é o **symlink de uploads** (§6), que o Escala não
tem porque não recebe arquivo do usuário.

---

## 0. Método: deploy automático (GitHub Actions + FTPS)

Publicar código é **`git push` na `main`**. O workflow
`.github/workflows/deploy.yml` baixa o repositório e envia o **código-fonte**
por **FTPS** para `apps-core/eventos`.

**Por que FTPS e não SSH/rsync:** a 21 é a **única porta aberta** no cPanel da
igreja. FTPS criptografa e passa por ela.

🆕 **Conta de FTP compartilhada.** O usuário de deploy tem home em
`apps-core/` — a pasta-mãe de todos os apps — e não na pasta deste app. Por
isso o workflow usa `server-dir: ./eventos/`, e não `./` como o Escala.

```
conta FTP  →  home: apps-core/
                ├── escala/     ← server-dir: ./escala/
                └── eventos/    ← server-dir: ./eventos/
```

> Uma conta FTP do cPanel aponta para **um** diretório, mas nada obriga que
> seja o do app: apontando para a pasta-mãe, o mesmo usuário atende todos os
> apps e cada workflow escreve no seu subdiretório.
>
> ⚠ **O preço é o raio de alcance:** o secret vazado de qualquer repo dá
> escrita em **todos** os apps. Se um dia isso incomodar, crie a conta
> dedicada (home em `apps-core/eventos`) e troque o `server-dir` para `./`.
> O `.ftp-deploy-sync-state.json` fica no mesmo lugar físico nos dois casos,
> então **o envio incremental não se perde na migração**.

**Segredos** (GitHub → Settings → Secrets and variables → Actions):
`FTP_HOST` = `ipccg.org.br`, `FTP_USER` = o e-mail **completo** da conta de
deploy, `FTP_PASSWORD`.

**Envio incremental:** do 2º deploy em diante sobe só o que mudou.

### O `vendor/` NÃO sobe pelo FTPS — é publicado à mão

Empurrar os **6.459 arquivos** do `vendor/` de produção numa única sessão FTPS faz o
servidor derrubar a conexão ("Server sent FIN packet unexpectedly"). Ele está
no `exclude` e vai à mão, só quando o `composer.lock` muda (raro):

```bash
# 1) gere o vendor de PRODUÇÃO (sem dev, autoload otimizado):
composer install --no-dev --optimize-autoloader
# 2) zipe só o vendor:
zip -r vendor.zip vendor
# 3) File Manager do cPanel → apps-core/eventos/ → upload → "Extract",
#    sobrescrevendo.
```

**Também não sobe** (`exclude`): `.env`, `storage/`, `.git`, `.github`,
`tests`, `docker/`, `scripts/`, `phpunit.xml`, `node_modules`. Assim o `.env` e
o `storage/` de produção são preservados — subir os de dev derrubaria o app e
**apagaria as fotos já enviadas**.

⚠️ **O pipeline não roda comando no servidor.** `migrate` é manual (§5).

---

## 1. Estrutura de pastas no cPanel

Alvo: `ipccg.org.br/eventos` (subpasta do domínio principal).

```
/home2/ipccgorg/
├── apps-core/                     ← pasta-mãe, FORA da web
│   ├── escala/
│   └── eventos/                   ← este app
│       ├── app/ bootstrap/ config/ database/ resources/ routes/
│       ├── storage/ vendor/ artisan .env
│       └── public/                ← a "portaria" do Laravel
└── public_html/
    ├── escala   → (symlink) → /home2/ipccgorg/apps-core/escala/public
    └── eventos  → (symlink) → /home2/ipccgorg/apps-core/eventos/public
```

Criar o symlink (Terminal do cPanel):
```bash
ln -s /home2/ipccgorg/apps-core/eventos/public /home2/ipccgorg/public_html/eventos
```

> ✅ Com symlink **não precisa editar o `index.php`**: como a `public/` fica ao
> lado do `vendor/`, os caminhos relativos resolvem sozinhos.

**Sem symlink (plano B):** mova o conteúdo de `apps-core/eventos/public/` para
`public_html/eventos/` e ajuste os dois caminhos do `index.php`:

```php
require __DIR__.'/../../apps-core/eventos/vendor/autoload.php';
$app = require_once __DIR__.'/../../apps-core/eventos/bootstrap/app.php';
```

Confira que o `.htaccess` foi junto.

---

## 2. Banco de dados

**Dois schemas, um único usuário MySQL** com ALL PRIVILEGES nos dois — a
consulta cross-schema das congregações depende disso:

1. **MySQL Databases** → criar `ipccgorg_Eventos`
2. **MySQL Databases** → anexar ao mesmo usuário que já acessa
   `ipccgorg_ModernApps`
3. **phpMyAdmin** → selecionar `ipccgorg_Eventos` → importar **nesta ordem**:

| Arquivo | O que faz | Obrigatório |
|---|---|---|
| `database/sql/01-core.sql` | identidade, ACL e `events` — 8 tabelas | sim |
| `database/sql/02-modulo-livraria.sql` | módulo livraria, prefixo `liv_` — 17 tabelas | sim |
| `database/sql/03-seed-referencia.sql` | permissões, perfis, categorias, motivos de baixa, primeiro admin | sim |
| `database/sql/04-triggers.sql` | gatilhos da RN09 (um exemplar não sai duas vezes) | recomendado |

**Antes de rodar o `03`, edite no arquivo:**
- `SET @church := 1;` → o id real em `ipccgorg_ModernApps.churches`
  (`SELECT id, name FROM churches;`)
- o **e-mail e o nome** do primeiro administrador (linha do `INSERT INTO users`)

O `03` é idempotente e termina com um `SELECT` de conferência.

> O `04` usa `DELIMITER`: importe pela aba **Importar**. Colando na aba **SQL**,
> preencha "Delimitador" com `$$`. Se o host não conceder o privilégio TRIGGER,
> **pule o 04** — o app continua correto, só perde a rede de segurança da RN09.

⚠️ **Não** rode o `99-teste-de-cenario.sql` em produção (é dado de teste).

### Primeiro acesso

O `03` cria o admin com a senha literal `DEFINIR-VIA-TINKER`, que **não é um
hash válido** — ninguém entra com ela. Defina a senha de verdade:

- **Com Terminal:** `php artisan tinker` →
  `App\Models\User::where('email','ti@ipccg.org.br')->update(['password' => bcrypt('umaSenhaForte')]);`
- **Sem Terminal:** gere o hash no dev
  (`php -r "echo password_hash('umaSenhaForte', PASSWORD_BCRYPT);"`) e faça o
  `UPDATE users SET password = '<hash>' WHERE email = '...';` pelo phpMyAdmin.
- Ou pela tela **"Esqueci minha senha"**, com o SMTP já configurado.

---

## 3. O `.env` de produção (criar no servidor)

Não sobe pelo FTPS — crie uma vez pelo File Manager, em
`apps-core/eventos/.env`:

```dotenv
APP_NAME="Eventos IPCCG"
APP_ENV=production
APP_KEY=            # §4
APP_DEBUG=false
APP_URL=https://ipccg.org.br/eventos     # ⚠ https E a subpasta
APP_TIMEZONE=America/Sao_Paulo
APP_LOCALE=pt_BR

LOG_CHANNEL=stack
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ipccgorg_Eventos
DB_USERNAME=<usuario>
DB_PASSWORD=<senha>
DB_MODERN_APPS_DATABASE=ipccgorg_ModernApps   # mesmo usuário, só o schema muda

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=public

MAIL_MAILER=smtp
MAIL_HOST=mail.ipccg.org.br
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=<conta>
MAIL_PASSWORD=<senha>
MAIL_FROM_ADDRESS=<conta>
MAIL_FROM_NAME="Eventos IPCCG"
```

⚠️ `APP_DEBUG=false` em produção: com `true`, a tela de erro expõe o `.env`
inteiro, senha do banco incluída.

---

## 4. Gerar o `APP_KEY`

Sem isso, sessão e criptografia quebram.
- Com Terminal: `php artisan key:generate`
- Sem Terminal: gere no dev (`php artisan key:generate --show`) e cole o
  `base64:...` no `.env` do servidor.

---

## 5. Migrations (só se houver Terminal)

Se o host tiver Terminal, o §2 pode ser substituído por:
```bash
php artisan migrate --force
php artisan db:seed --force        # PermissionSeeder + CatalogoSeeder
```

⚠️ **Nunca** rode o `DemoSeeder` em produção — ele cria pessoas, catálogo e
vendas de mentira. (Ele se recusa a rodar com `APP_ENV=production`, mas não
conte com isso.)

⚠️ Se você importou o SQL do §2 **e** depois rodar `migrate`, faça antes o
**baseline** da tabela `migrations` — senão o Laravel tenta recriar tudo.

---

## 6. 🆕 Storage: pastas, permissões e uploads

### 6.1 A árvore do `storage/` — só no primeiro deploy

`storage/**` está no `exclude` (para preservar logs, sessões e fotos de
produção), o que significa que **as pastas dele não sobem pelo FTPS**. No
primeiro deploy elas não existem no servidor, e o Laravel estoura 500 na
primeira requisição — ele não cria essas pastas sozinho.

Crie a árvore uma vez, pelo Terminal ou pelo File Manager:

```bash
cd /home2/ipccgorg/apps-core/eventos
mkdir -p storage/app/public storage/app/private \
         storage/framework/cache/data storage/framework/sessions \
         storage/framework/views storage/logs \
         bootstrap/cache
```

> Do 2º deploy em diante isso não se repete — e é justamente o `exclude` que
> protege o que está lá dentro.

### 6.2 Permissões e o symlink de uploads

O Eventos **recebe arquivo do usuário** (fotos dos itens, via
`Storage::disk('public')`). Isso exige duas coisas que o Escala não precisa:

```bash
chmod -R 775 storage bootstrap/cache
php artisan storage:link        # cria public/storage → storage/app/public
```

**Sem Terminal**, crie o symlink à mão:
```bash
ln -s /home2/ipccgorg/apps-core/eventos/storage/app/public \
      /home2/ipccgorg/apps-core/eventos/public/storage
```

> `public/storage` está no `.gitignore` e **não vai pelo FTPS** — e ainda bem:
> no dev ele aponta para um caminho absoluto da máquina de desenvolvimento, que
> não existe no servidor.
>
> **Sintoma de que faltou:** o item salva, mas a foto aparece quebrada.

Confira também que `storage/app/public` existe e é gravável — é onde as fotos
ficam de fato.

---

## 7. PHP 8.2 na subpasta

MultiPHP Manager → confirme que `ipccg.org.br` (e a subpasta `/eventos`) roda
**PHP 8.2**. Em "Select PHP Version", garanta: `pdo_mysql`, `mbstring`,
`openssl`, `tokenizer`, `ctype`, `bcmath`, `curl`, `fileinfo`, `xml`, `zip`,
`intl`, `gd`.

> `gd` é obrigatório aqui (o Escala não precisa): é o que gera a **miniatura**
> das fotos dos itens.

**Sem cron.** O Eventos não tem tarefa agendada — nada a configurar em Cron
Jobs.

---

## 8. Checklist pós-deploy

- [ ] `https://ipccg.org.br/eventos` → cai no login (não em erro 500)
- [ ] **Clicar num botão** → se "não faz nada", é Livewire × HTTPS: confira o
      `APP_URL` (https, com `/eventos`)
- [ ] CSS e ícones carregam — se quebrados, revise o `APP_URL`
- [ ] Login funciona e a sidebar aparece
- [ ] **Ajuda** abre (o menu, no rodapé da sidebar)
- [ ] Criar um evento e **ativá-lo**
- [ ] Cadastrar fornecedor → item → **subir uma foto** (valida o §6)
- [ ] Montar uma remessa → conferir que os **códigos** saíram (`ECC001`…)
- [ ] Registrar uma venda de ponta a ponta → conferir no Painel

---

## 9. Armadilhas conhecidas

- 🆕 **Arquivos com ⚡ no nome.** São **9** neste app — todos os componentes
  Livewire (`resources/views/components/**/⚡*.blade.php`). Se o emoji se
  perder no envio ou na extração do zip, o Livewire não acha o componente e a
  tela quebra com "component not found". **Confira os nomes no File Manager
  após o primeiro deploy.**
- **HTTPS × Livewire:** `APP_URL` errado (http, ou sem `/eventos`) faz os
  botões pararem de responder.
- **Foto quebrada:** falta o symlink do §6.
- **Cache de config:** se rodar `config:cache`, rode `config:clear` sempre que
  editar o `.env`.
- **Não precisa de `node_modules`:** não há build de JS. O CSS é estático em
  `public/assets/` e o Livewire serve o próprio script.
- **`storage/` não gravável — ou inexistente:** erro 500 de log/sessão. No
  primeiro deploy a árvore de pastas nem chega ao servidor (está no
  `exclude`); veja o §6.1.
- **Acentuação:** todo `.sql` deste projeto começa com `SET NAMES utf8mb4`. Se
  importar por outro caminho, garanta o charset — o sintoma
  (`IPCCG Â€" Igreja…`) só aparece na tela, muito depois do dado já estar
  corrompido.
