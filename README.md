# App Eventos — IPCCG

App de eventos da igreja, organizado em módulos. O primeiro é a **Livraria**.

- **Escopo funcional:** `../Livraria de Eventos - Escopo Funcional v3.1.pdf`
- **Arquitetura e dicionário de dados:** [`Docs/arquitetura.md`](Docs/arquitetura.md)

> **Estado:** dá para montar e operar um evento inteiro pela interface —
> fornecedores, catálogo, remessa, venda, estoque e painel ao vivo, com
> **54 testes verdes**. Faltam a tela de baixa sem venda, o fechamento
> (acerto e devolução) e as etiquetas para impressão.

O catálogo **não é só de livros**: cada item pertence a uma categoria que define
seus próprios campos (autor/editora para livro, marca/material para camiseta), e
categorias como camiseta têm **variações com saldo próprio** (P, M, G). Itens
têm fotos, com uma capa. Ver `Docs/arquitetura.md` §6.

---

## Estrutura do código

```
app/
  Models/            núcleo: User, Membership, SystemRole, Permission, Event, Church
    Concerns/        BelongsToChurch — isolamento multi-tenant
    Scopes/          ChurchScope
    Livraria/        os 17 models do módulo
  Http/Middleware/
    SetChurchContext aplica o fuso da congregação (só no caminho web)
  Providers/
    AppServiceProvider  Gate::before mapeia ability = slug da permissão
  Services/Livraria/
    StockGuard       trava de concorrência (SELECT ... FOR UPDATE)
    SaleService      registrar e estornar venda
    WriteoffService  baixa sem venda e cancelamento
    EventResult      apuração do resultado do evento
    CodeGenerator    códigos dos exemplares (ECC001), sequência por evento
    ShipmentService  monta a remessa e materializa os exemplares
    PhotoService     upload, miniatura e capa única
database/
  migrations/        26 migrations (dev e hosts com terminal)
  sql/               os mesmos objetos em SQL puro (cPanel sem terminal)
  seeders/           permissões e catálogo inicial
scripts/
  comparar-schema.py prova que migrations e SQL geram o MESMO schema
resources/views/components/
  layouts/           app (público) e admin (sidebar + drawer no mobile)
  auth/⚡login       login multi-tenant: identidade + vínculo
  ⚡painel           painel ao vivo do evento
  ⚡eventos          lista e escolha do evento ativo
  livraria/⚡venda        A MESA — busca, carrinho, pagamento
  livraria/⚡estoque      saldo por item e variação
  livraria/⚡fornecedores editoras e confecções, com o prefixo do código
  livraria/⚡catalogo     itens, campos por categoria, variações e fotos
  livraria/⚡remessa      o que vem de cada fornecedor + custos do evento
public/assets/
  app.css            Emerald Archive + componentes do módulo
tests/Feature/
  AutenticacaoTest        login, vínculo, super-admin
  TelasRenderizamTest     página inteira (componente + layout)
  Livraria/ResultadoDoEventoTest   o cenário de referência
  Livraria/TelaDeVendaTest         a mesa, ponta a ponta
```

### A tela de venda

Quatro toques para um item: digitar, tocar no item, tocar na forma de pagamento,
concluir. A busca devolve **linhas de estoque** (item + variação), não
exemplares: o voluntário escolhe "Camiseta · M" e qual unidade física sai é
problema do sistema. Digitar um código adiciona direto, que é o caminho mais
rápido quando a etiqueta está à mão.

A forma de pagamento é botão, não `select`: um toque em vez de três.

### O catálogo se monta sozinho

O formulário do item **não conhece** "autor" nem "modelo": ele lê
`liv_category_fields` e desenha os campos que a categoria pede, com validação
vinda da própria definição. Acrescentar "ISBN" é cadastro, não desenvolvimento.

Tamanho fica de fora desses campos de propósito — tamanho divide estoque, então
é variação, e cada uma tem saldo próprio.

### A remessa gera os exemplares

Salvar uma linha cria os exemplares com código sequencial por fornecedor
(`EFL001`, `EFL002`…). Mudar a quantidade depois acerta o estoque: aumentar gera
só os que faltam; diminuir remove **apenas exemplares disponíveis**, e recusa se
a conta não fechar sem mexer no que já foi vendido — apagar um exemplar vendido
levaria receita e dívida junto.

### Avisos e confirmações

**Toda ação avisa por toast**, não por `session flash`. O flash só aparece na
próxima renderização de página — numa tela Livewire, onde nada recarrega, ele
some ou chega atrasado. Foi o que deixou a venda sem confirmação nenhuma.

```php
$this->dispatch('toast', tipo: 'ok',    mensagem: 'Item salvo.');
$this->dispatch('toast', tipo: 'erro',  titulo: 'Não deu', mensagem: '…');
```

Tipos: `ok` · `info` · `aviso` · `erro`. Erro e aviso ficam 8 s na tela (exigem
leitura); os demais, 4 s.

> ⚠ As cores do `.alert` são **translúcidas**. Isso funciona embutido na página,
> mas um toast flutuando por cima do conteúdo fica ilegível — por isso
> `.toasts .toast` sobrepõe o tom a um `var(--surface)` opaco.

**Ações de risco pedem confirmação**, proporcional ao estrago:

| Ação | Proteção |
|---|---|
| Concluir venda | **Modal** com item a item, taxa em separado e total |
| Reduzir a quantidade de uma linha da remessa | Aviso na tela com a contagem + confirmação — apaga exemplares e códigos |
| Remover linha da remessa | Confirmação dizendo quantos exemplares somem |
| Remover forma de pagamento já usada | **Bloqueado** — só desativar |
| Remover variação já em remessa | **Bloqueado** — levaria o saldo do tamanho |
| Encerrar evento · trocar evento ativo | Confirmação (muda o contexto de tudo) |
| Desativar fornecedor · remover foto/custo | Confirmação simples |

### Reserva de carrinho

Exemplar no carrinho de alguém aparece como **"Reservados: x"** na linha do
estoque. Não bloqueia: quem quiser pode adicionar mesmo assim, e recebe um
aviso quando os reservados igualam ou passam os disponíveis — a combinação é
entre os voluntários, na mesa.

Mora em `liv_cart_holds`, não no `status` do exemplar. O status é o estado
**físico** (vendido, baixado, devolvido); estar num carrinho é estado de
**sessão**. Com `expira_em` (20 min), carrinho abandonado se cura sozinho, sem
cron — basta filtrar as reservas vigentes.

> ⚠ **O horário vem do fuso da CONGREGAÇÃO** (`Church::fuso()`), nunca de
> `now()` puro. O middleware aplica o fuso da igreja na web, mas comando de
> terminal fica em UTC — 3 horas de diferença fariam uma reserva criada pela
> tela parecer expirada em qualquer rotina. E os horários gravados são
> **hora-de-parede**: `$hold->expira_em->isFuture()` mente, porque o cast rotula
> a string com o fuso do ambiente. Para saber se vale, use o escopo
> `vigentes()`, que compara no banco.

### Desconto: fornecedor e item

`liv_suppliers.desconto_padrao` vale para tudo daquele fornecedor;
`liv_products.desconto` é a **exceção** para um item — a editora dá 40% em tudo
mas negocia diferente numa linha. `NULL` no item significa "usa o do
fornecedor", que é o caso comum: mudar a condição geral não exige tocar em item
nenhum.

> ⚠ Isso é só **sugestão** de custo ao montar a remessa. O que vale para o
> acerto é o `custo_unitario` gravado na linha, que é snapshot — renegociar o
> desconto amanhã não pode mexer num evento já fechado. Há teste travando isso.

### Categorias e o campo universal

`Categorias` cria a categoria e os campos que ela pede — é assim que "Caneca"
com "Capacidade" entra sem desenvolvimento. Remover um campo só faz ele deixar
de ser pedido: **o valor já preenchido nos itens continua guardado**.

`observacoes` fica no item, como coluna, e não dentro do JSON `atributos`:
atributo é o que a categoria define, observação existe para todo item sempre.

### Armadilhas que custaram caro aqui

- **`SET NAMES utf8mb4` na primeira linha de todo `.sql`.** Sem ele o cliente
  mysql assume latin1 e grava acento em duplo-encoding. O sintoma só aparece na
  tela (`IPCCG Â€" Igreja…`), muito depois de o dado estar corrompido. Pegamos
  isso olhando uma captura de tela, não rodando teste.
- **Ordenação precisa de desempate.** `ORDER BY ordem` sem `ORDER BY id` faz
  P/M/G aparecerem embaralhados a cada busca.
- **`class="form"` em todo formulário.** É ela que dá o espaçamento entre os
  campos (`gap: 14px`). Sem ela, label e input ficam colados e a tela parece
  quebrada.
- **A `.sidebar` não define cor de texto.** Bloco novo dentro dela precisa
  declarar `color: var(--sidebar-fg)`, senão herda a cor escura do corpo e fica
  invisível no fundo escuro.
- **Use as classes do design system, não invente nome.** `.content-area` traz um
  `min-width: 0` que impede a página estourar na horizontal; um `.content`
  inventado não traz. Os botões são `<button>` puro mais `secondary`,
  `btn-ghost`, `btn-danger`, `btn-sm` — **não existe `.btn`**, e escrever
  `class="btn primary"` deixa o botão sem estilo nenhum.
- **Regra de validação em array é um item por regra.** `['nullable',
  'string|max:255']` faz o Laravel procurar uma regra chamada literalmente
  `string|max:255` e estourar. A sintaxe com pipe só vale em string única.

### Três camadas para "um exemplar não sai duas vezes"

Elas se somam; nenhuma substitui a outra:

1. **Índice UNIQUE** em `copy_ativo` — impede a MESMA tabela repetir o exemplar
2. **Gatilhos** `BEFORE INSERT` — impedem vender e depois sortear o mesmo exemplar
   (o índice não cruza tabelas)
3. **`StockGuard`** com `SELECT ... FOR UPDATE` — a única que resolve a corrida
   entre duas transações simultâneas

### Ordem de gravação que os gatilhos impõem

`INSERT` do item primeiro, `UPDATE` do status depois — o exemplar precisa ainda
estar `disponivel` no momento do INSERT. Está encapsulado nos Services; quem
gravar por fora deles precisa respeitar isso.

---

## Rodando

```bash
mysqld --user=root &            # não há systemd neste ambiente
mysql < docker/init.sql         # cria os dois schemas e o usuário
composer install
php artisan migrate --force
php artisan db:seed --class=DemoSeeder --force    # ← ver aviso abaixo
php artisan test
php artisan serve --host=0.0.0.0 --port=8011      # abre em localhost:8011
```

Login da demonstração: **coordenador@ipccg.org.br** ou
**voluntario@ipccg.org.br**, senha `demo1234`.

> ⚠ **`db:seed` sem `--class` NÃO cria usuário nenhum.** O `DatabaseSeeder`
> carrega só dado de referência — permissões e categorias — porque é ele que
> roda em produção. Quem cria pessoas, catálogo, evento e vendas é o
> **`DemoSeeder`**, que se recusa a rodar quando `APP_ENV=production`.
>
> Rodar `migrate:fresh` seguido de `db:seed` deixa um banco onde **é impossível
> entrar**: existem permissões, mas nenhuma conta. Já aconteceu — se o login
> disser "credenciais inválidas" logo depois de recriar o banco, é isto.

### Os três seeders

| Seeder | O que carrega | Onde roda |
|---|---|---|
| `PermissionSeeder` | as 11 permissões do catálogo de slugs | todo ambiente, inclusive produção |
| `CatalogoSeeder` | categorias com seus campos e motivos de baixa | todo ambiente — é o ponto de partida da congregação |
| `DemoSeeder` | pessoas, fornecedores, itens, evento e vendas | **só desenvolvimento** |

`DatabaseSeeder` chama os dois primeiros. O `DemoSeeder` é sempre explícito.

---

## Banco de dados

Rode os arquivos **em ordem**, dentro do schema `ipccgorg_Eventos`:

| Arquivo | O que faz | Obrigatório |
|---|---|---|
| [`database/sql/01-core.sql`](database/sql/01-core.sql) | Núcleo: identidade, ACL e `events` — 8 tabelas | sim |
| [`database/sql/02-modulo-livraria.sql`](database/sql/02-modulo-livraria.sql) | Módulo livraria, prefixo `liv_` — 18 tabelas | sim |
| [`database/sql/03-seed-referencia.sql`](database/sql/03-seed-referencia.sql) | Permissões, perfis, **categorias e seus campos**, motivos de baixa, primeiro acesso | sim |
| [`database/sql/04-triggers.sql`](database/sql/04-triggers.sql) | Gatilhos da RN09 (um exemplar não sai duas vezes) | recomendado |
| [`database/sql/05-modulo-participantes.sql`](database/sql/05-modulo-participantes.sql) | Módulo participantes, prefixo `par_` — 7 tabelas | sim |
| [`database/sql/99-teste-de-cenario.sql`](database/sql/99-teste-de-cenario.sql) | Encena um evento com livro **e** camiseta e confere a aritmética | **só em dev** |

Antes de rodar o `03`, ajuste no topo do arquivo:
- `SET @church := 1;` → id da congregação em `ipccgorg_ModernApps.churches`
- o e-mail e o nome do primeiro administrador

O `03` é idempotente e termina com um `SELECT` de conferência.

### Passos no cPanel

1. **MySQL Databases** → criar `ipccgorg_Eventos`
2. **MySQL Databases** → anexar o **mesmo usuário** que já acessa
   `ipccgorg_ModernApps`, com ALL PRIVILEGES (a conexão cross-schema das
   congregações depende disso)
3. **phpMyAdmin** → selecionar `ipccgorg_Eventos` → importar 01, 02, 03, 04
4. Conferir o `SELECT` final do `03`

> O `04` usa `DELIMITER`. Importe-o pela aba **Importar** (o phpMyAdmin
> entende); colando na aba **SQL**, preencha o campo "Delimitador" com `$$`.
> Se o host não conceder o privilégio TRIGGER, **pule o 04** — o app continua
> correto, só perde a rede de segurança da RN09.

### Duas advertências que o MySQL 8.4 emite (e por que ficam)

- **`TINYINT(1)` — "integer display width is deprecated".** É exatamente o que o
  `$table->boolean()` do Laravel gera, e vários clientes usam a largura 1 para
  reconhecer booleano. Trocar por `TINYINT` calaria o aviso e divergiria do que
  as migrations produzem.
- **`VALUES()` em `ON DUPLICATE KEY UPDATE`.** A sintaxe nova, com alias, só
  existe do MySQL 8.0.19 e do MariaDB 10.3.3 em diante, e não sabemos a versão
  do cPanel. Aviso, não erro.

---

## Ambiente de desenvolvimento

Instalado e verificado neste ambiente (Ubuntu 26.04, aarch64):

| Requisito | Versão | Observação |
|---|---|---|
| PHP | 8.2.32 | mesma linha da produção (`DEPLOY.md §8`) |
| Extensões | pdo_mysql, mbstring, openssl, tokenizer, ctype, bcmath, curl, fileinfo, xml, zip, intl, gd | todas as exigidas pelo `DEPLOY.md §8` |
| Composer | 2.8.x | instalado pelo instalador oficial, com conferência de hash |
| MySQL | 8.4.10 | produção é MySQL 8 |
| Node / npm | presentes | não usados (CSS puro, sem build) |

> **PHP 8.2 no Ubuntu 26.04:** o padrão da distro é o 8.5, que diverge da
> produção. O `php8.2-cli` existe no archive, mas conflita com o
> `php-common` do Ubuntu — instale fixando a versão:
> `apt-get install php-common=2:93 php8.2-cli php8.2-mysql ...`

### Subir o MySQL (não há systemd neste ambiente)

```bash
mysqld --user=root &        # config em /etc/mysql/conf.d/proot.cnf
mysqladmin ping             # deve responder "mysqld is alive"
```

O arquivo `/etc/mysql/conf.d/proot.cnf` desliga AIO nativo e o
`performance_schema`, que não funcionam sob PRoot.

### Criar os schemas de dev

```bash
mysql < docker/init.sql
```

Cria `ipccgorg_Eventos` e `ipccgorg_ModernApps`, o usuário `eventos`/`eventos`
com acesso aos dois, e uma linha em `churches` — espelhando produção.

---

## O que já foi testado de verdade

Executado contra MySQL 8.4 real, não só revisado.

**Suíte automatizada:** `php artisan test` → **14 testes, 41 asserções, verdes.**
Rodam contra o MySQL real (não SQLite): o schema depende de coluna gerada com
índice único, gatilho e ENUM, e testar noutro banco daria verde num lugar que
não é o que roda em produção.

**Paridade migrations × SQL:** `scripts/comparar-schema.py` → 276 colunas,
113 índices e 48 chaves estrangeiras idênticas nos dois caminhos.

**No SQL puro:**

- os 5 arquivos SQL rodam do zero, em ordem, sem erro → 26 tabelas, 2 gatilhos
- **resultado do evento** (RF24/RN04) bate com a conta à mão em um cenário
  encenado: receita 126,00 − devido 108,00 − custos 200,00 − taxas 2,03
- **RN09, mesma tabela:** vender o mesmo exemplar duas vezes é rejeitado pelo
  índice UNIQUE em `copy_ativo`
- **RN09, entre tabelas:** sortear um exemplar já vendido é rejeitado pelo
  gatilho do `04` (o índice sozinho *não* pegava — foi por isso que o `04` existe)
- **cancelamento:** cancelar a venda devolve o exemplar para `disponivel` e ele
  pode ser vendido de novo
- **acentuação:** gravar e ler "Acentuação: Coração — São João" volta idêntico
  byte a byte pelo PDO com `charset=utf8mb4`
- **categoria com campos próprios:** livro grava `{autor, editora, num_paginas}`
  e camiseta grava `{modelo, marca, material, cor}` na mesma coluna JSON, e uma
  única listagem serve os dois
- **variação com saldo próprio:** vendida 1 camiseta M, o saldo mostra P 2/2,
  **M 2/3**, G 2/2 — o tamanho esgota sozinho, como tem de ser
- **remessa sem item repetido:** cadastrar o mesmo livro (variação NULL) ou o
  mesmo tamanho duas vezes na mesma remessa é recusado; tamanho **diferente**
  passa
- **uma capa por item:** a segunda foto marcada como capa é recusada pelo índice
  `capa_unica`; trocar a capa exige zerar a anterior na mesma transação
