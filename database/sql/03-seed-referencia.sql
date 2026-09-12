-- =====================================================================
--  App EVENTOS — IPCCG
--  Arquivo 03 de 05: DADOS DE REFERÊNCIA
--  Schema: ipccgorg_Eventos          Rodar DEPOIS do 01 e do 02
-- ---------------------------------------------------------------------
--  Este arquivo é IDEMPOTENTE: pode rodar de novo sem duplicar nada.
-- =====================================================================

SET NAMES utf8mb4;


-- ---------------------------------------------------------------------
-- 1. CATÁLOGO DE PERMISSÕES  (dado de código, igual em toda igreja)
--    Convenção: "area.acao". O prefixo agrupa a permissão na tela de
--    perfis, então ao criar o próximo módulo basta um prefixo novo.
--
--    O MySQL 8.4 avisa que VALUES() em ON DUPLICATE KEY está depreciado
--    e sugere a sintaxe com alias (... AS novo ... novo.description).
--    Mantemos VALUES() de propósito: a sintaxe com alias só existe a
--    partir do MySQL 8.0.19 e do MariaDB 10.3.3, e não sabemos a versão
--    exata do cPanel. É um aviso, não um erro — o INSERT roda igual.
-- ---------------------------------------------------------------------
INSERT INTO `permissions` (`slug`, `description`) VALUES
  -- núcleo
  ('usuarios.ver',        'Ver a lista de pessoas da congregação'),
  ('usuarios.gerenciar',  'Cadastrar, ativar, editar e remover pessoas'),
  ('perfis.gerenciar',    'Gerenciar perfis de acesso e suas permissões'),
  ('eventos.ver',         'Ver os eventos da congregação'),
  ('eventos.gerenciar',   'Criar, editar e encerrar eventos'),
  -- módulo livraria
  ('livraria.ver',        'Ver a livraria do evento: catálogo, saldo e relatórios'),
  ('livraria.catalogo',   'Gerenciar fornecedores e o catálogo de títulos'),
  ('livraria.remessa',    'Montar remessas, lançar custos, definir preços e gerar etiquetas'),
  ('livraria.vender',     'Registrar vendas na mesa'),
  ('livraria.baixar',     'Dar baixa em exemplar sem venda (sorteio, cortesia, doação, perda)'),
  ('livraria.fechamento', 'Fechar o evento: acerto dos fornecedores, devolução e resultado')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);


-- ---------------------------------------------------------------------
-- 2. PERFIS DA CONGREGAÇÃO
--    ⚠ TROQUE o @church pelo id da congregação em
--      ipccgorg_ModernApps.churches  (SELECT id, name FROM churches;)
-- ---------------------------------------------------------------------
SET @church := 1;

INSERT INTO `system_roles` (`church_id`, `name`)
SELECT @church, 'Administrador' FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `system_roles` WHERE `church_id` = @church AND `name` = 'Administrador');

INSERT INTO `system_roles` (`church_id`, `name`)
SELECT @church, 'Coordenador da Livraria' FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `system_roles` WHERE `church_id` = @church AND `name` = 'Coordenador da Livraria');

INSERT INTO `system_roles` (`church_id`, `name`)
SELECT @church, 'Operador de Mesa' FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `system_roles` WHERE `church_id` = @church AND `name` = 'Operador de Mesa');

SET @admin := (SELECT id FROM `system_roles` WHERE `church_id` = @church AND `name` = 'Administrador');
SET @coord := (SELECT id FROM `system_roles` WHERE `church_id` = @church AND `name` = 'Coordenador da Livraria');
SET @oper  := (SELECT id FROM `system_roles` WHERE `church_id` = @church AND `name` = 'Operador de Mesa');

-- Administrador: TODAS. É o perfil que a tela de cadastro promete quando diz
-- "seu acesso precisa ser liberado pelo Administrador" — sem ele, o app fala
-- de um papel que não existe em lugar nenhum.
INSERT IGNORE INTO `permission_system_role` (`system_role_id`, `permission_id`)
SELECT @admin, p.id FROM `permissions` p;

-- Coordenador: tudo do módulo + eventos. Inclui usuarios.gerenciar: sem ele o
-- coordenador vê a fila de quem se cadastrou e não consegue liberar ninguém.
INSERT IGNORE INTO `permission_system_role` (`system_role_id`, `permission_id`)
SELECT @coord, p.id FROM `permissions` p
WHERE p.slug IN ('eventos.ver','eventos.gerenciar','usuarios.ver','usuarios.gerenciar',
                 'livraria.ver','livraria.catalogo','livraria.remessa',
                 'livraria.vender','livraria.baixar','livraria.fechamento');

-- Operador de mesa: vende e consulta. Baixa entra aqui ou não conforme a
-- resposta da pendência P06 — se só o coordenador puder autorizar,
-- remova 'livraria.baixar' desta lista.
INSERT IGNORE INTO `permission_system_role` (`system_role_id`, `permission_id`)
SELECT @oper, p.id FROM `permissions` p
WHERE p.slug IN ('eventos.ver','livraria.ver','livraria.vender','livraria.baixar');


-- ---------------------------------------------------------------------
-- 3. CATEGORIAS DE ITEM E SEUS CAMPOS
--    O catálogo NÃO é só de livros. Cada categoria define os campos que
--    o item pede; os valores caem no JSON liv_products.atributos.
--
--    `usa_variacao = 1` marca a categoria cujos itens têm saldo por
--    tamanho/cor (camiseta). Livro não usa: um livro é um saldo só.
-- ---------------------------------------------------------------------
INSERT INTO `liv_categories` (`church_id`,`nome`,`slug`,`usa_variacao`,`rotulo_variacao`,`ordem`,`ativo`,`created_at`) VALUES
  (@church,'Livro',    'livro',    0, NULL,      1, 1, NOW()),
  (@church,'Camiseta', 'camiseta', 1, 'Tamanho', 2, 1, NOW()),
  (@church,'Outro',    'outro',    0, NULL,      9, 1, NOW())
ON DUPLICATE KEY UPDATE `ativo` = 1;

SET @catLivro := (SELECT id FROM `liv_categories` WHERE `church_id`=@church AND `slug`='livro');
SET @catCam   := (SELECT id FROM `liv_categories` WHERE `church_id`=@church AND `slug`='camiseta');

-- Campos do LIVRO
INSERT INTO `liv_category_fields`
  (`category_id`,`chave`,`rotulo`,`tipo`,`opcoes`,`obrigatorio`,`mostrar_na_lista`,`ordem`) VALUES
  (@catLivro,'autor',       'Autor',             'texto',   NULL, 1, 1, 1),
  (@catLivro,'editora',     'Editora',           'texto',   NULL, 0, 0, 2),
  (@catLivro,'num_paginas', 'Número de páginas', 'inteiro', NULL, 0, 0, 3),
  (@catLivro,'isbn',        'ISBN',              'texto',   NULL, 0, 0, 4),
  (@catLivro,'ano',         'Ano de publicação', 'inteiro', NULL, 0, 0, 5)
ON DUPLICATE KEY UPDATE `rotulo` = VALUES(`rotulo`);

-- Campos da CAMISETA. Tamanho NÃO está aqui de propósito: tamanho é
-- variação (liv_variants), porque cada um tem saldo próprio.
INSERT INTO `liv_category_fields`
  (`category_id`,`chave`,`rotulo`,`tipo`,`opcoes`,`obrigatorio`,`mostrar_na_lista`,`ordem`) VALUES
  (@catCam,'modelo',   'Modelo',   'texto',   NULL, 1, 1, 1),
  (@catCam,'marca',    'Marca',    'texto',   NULL, 0, 0, 2),
  (@catCam,'material', 'Material', 'texto',   NULL, 0, 0, 3),
  (@catCam,'cor',      'Cor',      'selecao', JSON_ARRAY('Preta','Branca','Azul','Cinza'), 0, 1, 4)
ON DUPLICATE KEY UPDATE `rotulo` = VALUES(`rotulo`);


-- ---------------------------------------------------------------------
-- 4. MOTIVOS DE BAIXA  (RF30 / RN10)
--    gera_custo = 1 → o exemplar não volta ao fornecedor, logo é devido.
--    O motivo "Doação da editora" é a exceção da RN10: a editora deu o
--    livro, então não se paga por ele.
-- ---------------------------------------------------------------------
INSERT INTO `liv_writeoff_reasons` (`church_id`, `nome`, `gera_custo`, `ativo`) VALUES
  (@church, 'Sorteio',            1, 1),
  (@church, 'Cortesia',           1, 1),
  (@church, 'Doação',             1, 1),
  (@church, 'Perda ou dano',      1, 1),
  (@church, 'Doação da editora',  0, 1)
ON DUPLICATE KEY UPDATE `ativo` = 1;


-- ---------------------------------------------------------------------
-- 5. PRIMEIRO ACESSO
--    A senha NÃO pode ser gerada em SQL (o Laravel usa bcrypt). O passo
--    abaixo cria a pessoa com um hash inválido de propósito; a senha é
--    definida depois, de uma destas formas:
--
--    a) Terminal do cPanel, dentro de apps-core/eventos:
--         php artisan tinker
--         >>> App\Models\User::where('email','ti@ipccg.org.br')
--               ->update(['password' => bcrypt('umaSenhaForte')]);
--
--    b) Ou pela tela "Esqueci minha senha", com o SMTP já configurado.
--
--    ⚠ TROQUE o e-mail e o nome abaixo.
-- ---------------------------------------------------------------------
INSERT INTO `users` (`name`, `email`, `password`, `is_super`, `created_at`)
VALUES ('Administrador', 'ti@ipccg.org.br', 'DEFINIR-VIA-TINKER', 1, NOW())
ON DUPLICATE KEY UPDATE `is_super` = 1;

INSERT IGNORE INTO `memberships` (`user_id`, `church_id`, `system_role_id`, `status`, `created_at`)
SELECT u.id, @church, @admin, 1, NOW()
FROM `users` u WHERE u.email = 'ti@ipccg.org.br';


-- ---------------------------------------------------------------------
-- 6. CONFERÊNCIA
-- ---------------------------------------------------------------------
SELECT 'permissions'  AS tabela, COUNT(*) AS linhas FROM `permissions`
UNION ALL SELECT 'system_roles',           COUNT(*) FROM `system_roles`
UNION ALL SELECT 'permission_system_role', COUNT(*) FROM `permission_system_role`
UNION ALL SELECT 'liv_categories',         COUNT(*) FROM `liv_categories`
UNION ALL SELECT 'liv_category_fields',    COUNT(*) FROM `liv_category_fields`
UNION ALL SELECT 'liv_writeoff_reasons',   COUNT(*) FROM `liv_writeoff_reasons`
UNION ALL SELECT 'users',                  COUNT(*) FROM `users`
UNION ALL SELECT 'memberships',            COUNT(*) FROM `memberships`;
