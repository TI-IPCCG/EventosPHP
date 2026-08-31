# Arquitetura — App Eventos

Documento vivo. Registra as **decisões** e o **dicionário de dados**; o que foi
pedido está no `Escopo Funcional v3.1` (PDF na raiz do workspace).

> **Estado:** modelagem de dados fechada e **executada com sucesso** contra
> MySQL 8.4 (25 tabelas, 2 gatilhos), com o cálculo do resultado conferido
> contra a conta à mão. Código da aplicação ainda não iniciado.
> Ver "O que já foi testado de verdade" no `README.md`.

---

## 1. O que é este app

Um app de **eventos da igreja**, organizado em **módulos**. O primeiro módulo é
a **Livraria**; outros virão pendurados no mesmo evento.

O núcleo é pequeno de propósito: identidade, ACL e a tabela `events`. Tudo que é
de um módulo mora em tabelas com o prefixo dele (`liv_` para a livraria) e nunca
adiciona coluna ao núcleo. Meta financeira da livraria, por exemplo, está em
`liv_event_settings`, e não em `events` — se cada módulo pudesse acrescentar a
sua coluna, `events` viraria depósito em dois módulos.

---

## 2. Stack

| Camada | Tecnologia |
|---|---|
| Framework | Laravel 12 (PHP 8.2) |
| Reatividade | Livewire 4 (single-file components, prefixo `⚡`) |
| UI local | Alpine.js (embutido no Livewire) |
| Estilo | Emerald Archive em CSS puro (`public/assets/app.css`), zero build |
| Banco | MySQL 8 — dois schemas |
| Dev | Docker (`php:8.2` + `mysql:8.0`), portas 3308/8002 |
| Deploy | cPanel, subpasta `ipccg.org.br/eventos` |

Mesma stack do **EscalaMembros**, de propósito: o time já a conhece, o design
system já existe e o pipeline de deploy já está resolvido.

---

## 3. Bancos e conexões

```
ipccgorg_ModernApps   ← CENTRAL, compartilhado    conexão: mysql_modern_apps
  └── churches                                     (só leitura, aqui)

ipccgorg_Eventos      ← DESTE APP                 conexão: mysql (default)
  ├── núcleo:  users, memberships, system_roles, permissions,
  │            permission_system_role, password_reset_tokens,
  │            notifications, events
  └── livraria: liv_* (13 tabelas)
```

**Não há FK física para `churches`:** o MySQL não cruza schemas com chave
estrangeira. Toda coluna `church_id` é vínculo **lógico**, resolvido pelo
Eloquent — mesmo padrão do EscalaMembros.

### Gotcha herdado do EscalaMembros
Todo model operacional precisa declarar `protected $connection = 'mysql'`
explicitamente. Sem isso, ao navegar uma relação a partir de `Church` (que vive
na conexão central), o Eloquent herda a conexão do pai e consulta no schema
errado.

---

## 4. Identidade — decisão de 29/08/2026

**Este app tem a própria tabela `users`.** Não lê as pessoas do
`ipccgorg_EscalaMembros`.

**Por quê:** o schema global hoje contém apenas `churches`; identidade e ACL
vivem *dentro* do schema do app de escalas. Fazer o Eventos ler de lá criaria
acoplamento entre dois apps e transformaria o Escala em provedor de identidade
por acidente, sem nunca ter sido projetado para isso.

**O que isso custa:** a mesma pessoa terá cadastro e senha nos dois apps.

**Saída futura (não agendada):** promover `users`/`memberships` para o
`ipccgorg_ModernApps` e apontar os dois apps para lá. Não é gratuito — as FKs
físicas de `users` dentro do EscalaMembros (`schedules`, `slot_applications`,
`user_unavailabilities`, `volunteer_role_user`, `schedule_transfers`) teriam de
virar vínculos lógicos, num expand → migrate → contract. Enquanto isso não
acontece, o custo é dois cadastros; a alternativa era acoplamento entre apps,
que é mais caro de desfazer.

Estrutura de identidade, igual à do EscalaMembros: `users` é a **identidade
global** (sem `church_id`) e `memberships` é o **vínculo por congregação**, onde
moram o perfil e o status ATIVO. A mesma pessoa pode ser coordenadora numa
igreja e operadora em outra.

---

## 5. ACL

Modelo completo (`permissions` × `system_roles` via `permission_system_role`),
e não dois perfis fixos, porque este app vai crescer em módulos: cada módulo
novo entra como um **prefixo novo** no catálogo de slugs, sem alterar schema.

Convenção de slug: **`area.acao`**. O prefixo agrupa a permissão na tela de
perfis.

| Slug | O que libera |
|---|---|
| `usuarios.ver` / `usuarios.gerenciar` | pessoas da congregação |
| `perfis.gerenciar` | perfis de acesso |
| `eventos.ver` / `eventos.gerenciar` | agenda de eventos (núcleo) |
| `livraria.ver` | catálogo, saldo e relatórios |
| `livraria.catalogo` | fornecedores e títulos |
| `livraria.remessa` | remessas, custos, preços, etiquetas |
| `livraria.vender` | registrar vendas na mesa |
| `livraria.baixar` | baixa sem venda (RF15) |
| `livraria.fechamento` | acerto, devolução, resultado |

`Gate::before` mapeia ability = slug, então `@can('livraria.vender')` funciona
direto. Super-admin por `users.is_super`.

**Defesa em profundidade:** além do `can:` na rota, toda escrita revalida a
permissão no próprio método (`abort_unless`). Se o gate da rota mudar, a escrita
continua protegida.

---

## 6. Decisões de modelagem que valem explicação

### Exemplar serializado (`liv_copies`)
Uma linha por livro físico, com código único no evento. É o que permite saber
que *aquele* exemplar foi vendido, sorteado ou devolveu — e é a base da RN09
("um exemplar não sai duas vezes"). Herdado da planilha antiga, que já
funcionava assim e acertava nisso.

### O item não é "livro"

Nada no módulo presume livro. Um item pertence a uma **categoria**, e é a
categoria que define quais campos ele tem — `liv_categories` +
`liv_category_fields` + a coluna JSON `liv_products.atributos`.

Por isso as colunas se chamam `nome` e `preco_referencia`, e não `titulo` e
`preco_capa`: camiseta não tem título nem capa.

Escolhemos **JSON com definição de campos** em vez de EAV clássico (uma tabela
de valores). O EAV dá a mesma flexibilidade, mas transforma toda listagem num
pivô e some com a tipagem. Com JSON nativo do MySQL 8 + a tabela de definição
(que existe para montar e validar o formulário), a tela é igualmente dinâmica e
a consulta continua legível. Para filtrar ou ordenar por um atributo específico
com índice, promova-o a coluna gerada quando a necessidade aparecer:

```sql
ALTER TABLE liv_products
  ADD COLUMN autor VARCHAR(150) AS (atributos->>'$.autor') STORED,
  ADD INDEX (autor);
```

### Atributo não é variação — e essa é a distinção que evita o erro caro

**Atributo descreve. Variação divide estoque.**

Autor, editora, número de páginas, marca e material descrevem o item e não
mudam o saldo. Já **tamanho e cor são linhas de estoque separadas**: P, M e G
são três saldos, cada um com seus exemplares, e cada um esgota sozinho.

Se tamanho fosse um campo de texto como marca, duas perguntas ficariam sem
resposta: *"tem no M?"* na mesa, e *"o M esgotou e o GG voltou inteiro?"* no
fechamento — que é justamente o que o relatório de giro existe para dizer.

Daí a tabela `liv_variants`, e `liv_shipment_items` apontando para
`(product_id, variant_id)`. **Livro não usa variação:** `variant_id` fica NULL,
sem linha vestigial nem join extra no caminho quente.

> **Gotcha do NULL em UNIQUE.** Em índice UNIQUE, NULL nunca colide com NULL —
> então `UNIQUE(shipment_id, product_id, variant_id)` deixaria cadastrar o mesmo
> livro duas vezes na mesma remessa. A coluna gerada `variant_key`
> (`IFNULL(variant_id, 0)`) troca NULL por 0 e a unicidade passa a valer nos
> dois casos. Testado: o segundo INSERT do mesmo livro é recusado com
> `Duplicate entry '1-1-0'`.

### Fotos, com uma capa

`liv_product_photos` guarda o caminho relativo ao disco `public` do Laravel
(`storage/app/public/livraria/…`), servido pelo symlink do `storage:link`.

`caminho_thumb` não é luxo: a mesa opera em 4G no meio de um evento, e mandar a
foto original em cada linha da listagem derruba a tela de venda (RNF02). A
miniatura é gerada na subida, com a GD que o `DEPLOY.md §8` já exige.

A capa é garantida por coluna gerada com UNIQUE (`capa_unica`), então não existe
item com duas capas. Trocar a capa é `UPDATE` para 0 e depois para 1, dentro de
uma transação.

**No cPanel:** rodar `php artisan storage:link` uma vez. O deploy por FTPS
exclui `storage/**`, então as fotos enviadas **sobrevivem aos deploys** — mas o
symlink precisa existir no servidor, e o backup do site tem de incluir essa
pasta, senão as fotos ficam fora do backup.

### Snapshot de custo e preço
`custo_unitario` e `preco_venda` são **copiados** para `liv_shipment_items` e de
novo para `liv_sale_items` / `liv_writeoff_items`. Preço de capa e desconto
mudam entre eventos; o acerto de um evento passado precisa do valor que valia
naquele dia. O mesmo vale para `gera_custo` e para a taxa de pagamento.

### Venda é transação, item é item
`liv_sales` + `liv_sale_items`. Uma venda com três livros é **uma** linha de
venda. É o que faz a taxa de cartão incidir por transação e não por item
(RN02) — o erro que a planilha cometia, e que importa porque metade dos
compradores levava mais de um item.

### Custo do evento genérico
`liv_event_costs` com `rateio` configurável. Frete é só o primeiro caso; criar
"estacionamento" é INSERT, não desenvolvimento. **O rateio não afeta o
resultado** — o resultado soma o custo uma vez, pelo valor real (RN03/RN04). O
rateio serve só à sugestão de preço (RF10).

### Trava de banco para a RN09
`liv_sale_items.copy_ativo` e `liv_writeoff_items.copy_ativo` são colunas
geradas (`copy_id` quando ativo, `NULL` quando cancelado) com índice UNIQUE.
Como `NULL` não colide em UNIQUE, o banco garante no máximo **uma saída ativa
por exemplar** — mesmo com dois voluntários tocando "vender" no mesmo segundo
(RNF04). A checagem no Service continua existindo; isto é a segunda linha.

> Se o MySQL do host recusar coluna gerada, remova as duas linhas marcadas
> `-- trava RN09` e mantenha só a trava no Service, dentro de uma transação com
> `SELECT ... FOR UPDATE` sobre a linha de `liv_copies`.

**O índice sozinho não bastava.** Testado contra MySQL 8.4: ele impede vender o
mesmo exemplar duas vezes, mas *não* impede **sortear um exemplar já vendido** —
índice não cruza tabelas. Daí o `04-triggers.sql`: dois gatilhos `BEFORE INSERT`
que recusam qualquer saída de exemplar cujo `status` não seja `disponivel`.

Os gatilhos impõem uma **ordem de gravação** ao código:
1. `INSERT` do item (venda ou baixa) — o exemplar ainda está `disponivel`
2. `UPDATE liv_copies.status` — passa a `vendido` ou `baixado`

No cancelamento, o inverso: devolver o status para `disponivel` e marcar
`cancelado_em` nos itens.

⚠ **O que nem índice nem gatilho resolvem:** a corrida entre duas transações
simultâneas — ambas podem ler `disponivel` antes de qualquer uma gravar. Essa é
responsabilidade do Service: transação com `SELECT ... FOR UPDATE` na linha de
`liv_copies` antes de inserir. As três camadas se somam.

### Gotcha do InnoDB: coluna gerada STORED × ON DELETE CASCADE

O InnoDB **recusa** `ON DELETE CASCADE` (e `SET NULL`, e `SET DEFAULT`) numa
coluna que seja **base de uma coluna gerada STORED**. Descoberto na marra:
`liv_product_photos.capa_unica` deriva de `product_id`, e a FK de `product_id`
precisa cascatear (apagar o item apaga as fotos) — o `CREATE TABLE` falhava com
`ERROR 1215: Cannot add foreign key constraint`.

Solução: essa coluna é **VIRTUAL**, não STORED. VIRTUAL não tem a restrição,
aceita índice UNIQUE do mesmo jeito e nem ocupa espaço.

As demais colunas geradas do schema seguem STORED porque suas FKs usam
`RESTRICT`, que é permitido.

### Gotcha do `sql_mode=only_full_group_by`

Padrão no MySQL 8, inclusive em produção. Em consulta agrupada, **agrupe pelas
chaves** (`GROUP BY cat.id, p.id, v.id`), não pelos nomes: agrupando por chave
primária, todas as colunas daquela tabela ficam funcionalmente dependentes e
podem ser usadas no SELECT e no ORDER BY. Agrupar por nome dá erro 1055.

### Dinheiro em DECIMAL(10,2)
Nunca FLOAT. Arredondamento de ponto flutuante em dinheiro é exatamente o tipo
de erro que a planilha cometia sem avisar.

---

## 7. Dicionário de dados

### Núcleo — `ipccgorg_Eventos`
- **system_roles**: id, church_id(idx), name(50)
- **permissions**: id, slug(100, unique), description(255)
- **permission_system_role**: PK composta, FKs cascade
- **users** (identidade): id, name(150), email(191, unique), password, telefone,
  is_super, timestamps
- **memberships** (vínculo): id, user_id FK cascade, church_id(idx lógico),
  system_role_id FK nullOnDelete, status, `UNIQUE(user_id, church_id)`
- **password_reset_tokens**: email(pk), token, created_at
- **notifications**: uuid(pk), type, notifiable(morphs), data, read_at
- **events**: id, church_id(idx), nome(150), local(150), inicio(date),
  fim(date, null = um dia só), status enum(planejamento/em_andamento/encerrado)

### Módulo Livraria — prefixo `liv_` (17 tabelas)
- **liv_categories**: church_id(idx), nome(60), slug(60), **usa_variacao**,
  rotulo_variacao(30 — "Tamanho", "Cor"), ordem, ativo.
  `UNIQUE(church_id, slug)`
- **liv_category_fields**: category_id FK cascade, **chave**(40 — chave no
  JSON), rotulo(60), tipo enum(texto/texto_longo/inteiro/decimal/data/selecao/
  booleano), opcoes JSON, obrigatorio, **mostrar_na_lista**, ordem.
  `UNIQUE(category_id, chave)`
- **liv_variants**: product_id FK cascade, nome(30 — P, M, G), ordem, ativo.
  `UNIQUE(product_id, nome)`
- **liv_product_photos**: product_id FK cascade, caminho(255),
  caminho_thumb(255), capa, ordem, bytes, largura, altura,
  **capa_unica** (gerada VIRTUAL, UNIQUE)
- **liv_suppliers**: church_id, nome, **prefixo**(4 — gera o código do
  exemplar), condicao_padrao enum(consignado/firme), desconto_padrao, contato,
  ativo. `UNIQUE(church_id, prefixo)`
- **liv_products**: church_id, **category_id** FK restrict, supplier_id FK
  restrict, **nome**(200, idx), **preco_referencia**(null), **atributos** JSON,
  ativo
- **liv_writeoff_reasons**: church_id, nome(60), **gera_custo**(RN10), ativo.
  `UNIQUE(church_id, nome)`
- **liv_event_settings**: event_id **PK** (1:1 com o evento), meta_tipo
  enum(zero_a_zero/valor), meta_valor, observacao
- **liv_payment_methods**: event_id FK cascade, nome(50), taxa_percentual,
  ordem, ativo. `UNIQUE(event_id, nome)`
- **liv_event_costs**: event_id FK, supplier_id FK null, descricao, valor,
  rateio enum(direto/por_unidade_enviada/por_unidade_vendida/percentual_venda)
- **liv_shipments**: event_id FK, supplier_id FK restrict, condicao, observacao.
  Sem UNIQUE: segunda entrega no meio do evento é caso real
- **liv_shipment_items**: shipment_id FK cascade, product_id FK restrict,
  **variant_id** FK restrict (NULL = sem variação), quantidade, custo_unitario,
  preco_venda, **variant_key** (gerada STORED).
  `UNIQUE(shipment_id, product_id, variant_key)`
- **liv_copies**: event_id (denormalizado) FK, shipment_item_id FK, codigo(20),
  status enum(disponivel/reservado/vendido/baixado/devolvido).
  `UNIQUE(event_id, codigo)`, idx `(event_id, status)`
- **liv_sales**: event_id FK, payment_method_id FK null, registrado_por FK null,
  comprador(null), valor_bruto, taxa_percentual, taxa_valor, **vendida_em**
  (separado de created_at → RF17), cancelada_em, cancelada_por
- **liv_sale_items**: sale_id FK cascade, copy_id FK restrict, preco,
  custo_unitario, cancelado_em, **copy_ativo** (gerada, UNIQUE)
- **liv_writeoffs**: event_id FK, reason_id FK restrict, **autorizado_por**
  (texto — quem autoriza nem sempre tem login), registrado_por FK null,
  observacao, registrada_em, cancelada_em, cancelada_por
- **liv_writeoff_items**: writeoff_id FK cascade, copy_id FK restrict,
  custo_unitario, **gera_custo** (snapshot), cancelado_em, **copy_ativo**
  (gerada, UNIQUE)

---

## 8. Como o resultado é calculado (RN04)

Nada disso é coluna armazenada — é derivado, sempre:

```
receita        = Σ liv_sale_items.preco           (vendas não canceladas)
devido         = Σ liv_sale_items.custo_unitario  (vendas não canceladas)
               + Σ liv_writeoff_items.custo_unitario
                   onde gera_custo = 1            (baixas não canceladas)
custos         = Σ liv_event_costs.valor
taxas          = Σ liv_sales.taxa_valor           (vendas não canceladas)

RESULTADO      = receita − devido − custos − taxas
```

Devolvidos não aparecem: sob consignação eles simplesmente não geram dívida
(RN01). O relatório os mostra à parte, como custo que não precisou ser pago.

---

## 9. Pendências do escopo que ainda tocam o modelo

- **P01** — condição comercial por fornecedor: já cabe em
  `liv_suppliers.condicao_padrao` e `liv_shipments.condicao`. Só falta o dado.
- **P02** — se forem 3+ voluntários na mesa, reabrir a conciliação por pessoa.
  `liv_sales.registrado_por` já guarda quem registrou, então é questão de tela,
  não de schema.
- **P03** — taxas reais: `liv_payment_methods` por evento, só falta o dado.
- **P06** — quem autoriza baixa: muda a permissão `livraria.baixar` no perfil
  Operador, não o schema.
