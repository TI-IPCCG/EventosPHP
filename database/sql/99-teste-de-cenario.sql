-- =====================================================================
--  App EVENTOS — ARQUIVO DE TESTE (dev apenas, NÃO rodar em produção)
--  Encena um evento com LIVRO e CAMISETA, e confere se a aritmética do
--  resultado bate com a conta à mão.
-- ---------------------------------------------------------------------
--  Cenário:
--    Livro A   custo 30,00  venda 45,00   5 exemplares
--    Livro B   custo 24,00  venda 36,00   5 exemplares
--    Camiseta  custo 20,00  venda 40,00   P:2  M:3  G:2
--    Frete R$ 200,00        PIX 0%        Crédito 4,5%
--
--    Venda 1 (PIX):     Livro A + Livro B   = 81,00   taxa 0,00
--    Venda 2 (Crédito): Livro A             = 45,00   taxa 2,03
--    Venda 3 (PIX):     Camiseta M          = 40,00   taxa 0,00
--    Baixa: 1 exemplar do Livro B por Sorteio (gera custo)
--
--  Conta à mão:
--    receita   = 45 + 36 + 45 + 40                    = 166,00
--    devido    = 30 + 24 + 30 + 20 (vendidos)
--              + 24 (sorteio, RN10)                   = 128,00
--    custos    = 200,00
--    taxas     =   2,03
--    RESULTADO = 166 − 128 − 200 − 2,03               = −164,03
-- =====================================================================

SET NAMES utf8mb4;
SET @church := 1;

DELETE FROM `events`        WHERE `nome` = 'Evento de Teste';
DELETE FROM `liv_suppliers` WHERE `prefixo` IN ('TST','CAM');

SET @catLivro := (SELECT id FROM `liv_categories` WHERE `church_id`=@church AND `slug`='livro');
SET @catCam   := (SELECT id FROM `liv_categories` WHERE `church_id`=@church AND `slug`='camiseta');

-- ── fornecedores ─────────────────────────────────────────────────────
INSERT INTO `liv_suppliers` (`church_id`,`nome`,`prefixo`,`condicao_padrao`,`desconto_padrao`,`ativo`,`created_at`) VALUES
  (@church,'Editora de Teste',  'TST','consignado',40.00,1,NOW()),
  (@church,'Confecção de Teste','CAM','firme',      NULL,1,NOW());
SET @supL := (SELECT id FROM `liv_suppliers` WHERE `church_id`=@church AND `prefixo`='TST');
SET @supC := (SELECT id FROM `liv_suppliers` WHERE `church_id`=@church AND `prefixo`='CAM');

-- ── itens: cada categoria com os SEUS campos ─────────────────────────
INSERT INTO `liv_products` (`church_id`,`category_id`,`supplier_id`,`nome`,`preco_referencia`,`atributos`,`ativo`,`created_at`) VALUES
  (@church,@catLivro,@supL,'Título A',50.00,
     JSON_OBJECT('autor','Autor A','editora','Editora de Teste','num_paginas',224,'ano',2024),1,NOW()),
  (@church,@catLivro,@supL,'Título B',40.00,
     JSON_OBJECT('autor','Autor B','editora','Editora de Teste','num_paginas',160),1,NOW()),
  (@church,@catCam,  @supC,'Camiseta Simpósio 2026',45.00,
     JSON_OBJECT('modelo','Básica','marca','Malwee','material','Algodão 30.1','cor','Preta'),1,NOW());
SET @prodA := (SELECT id FROM `liv_products` WHERE `nome`='Título A'               AND `supplier_id`=@supL);
SET @prodB := (SELECT id FROM `liv_products` WHERE `nome`='Título B'               AND `supplier_id`=@supL);
SET @prodC := (SELECT id FROM `liv_products` WHERE `nome`='Camiseta Simpósio 2026' AND `supplier_id`=@supC);

-- ── variações: só a camiseta tem ─────────────────────────────────────
INSERT INTO `liv_variants` (`product_id`,`nome`,`ordem`,`ativo`) VALUES
  (@prodC,'P',1,1), (@prodC,'M',2,1), (@prodC,'G',3,1);
SET @varP := (SELECT id FROM `liv_variants` WHERE `product_id`=@prodC AND `nome`='P');
SET @varM := (SELECT id FROM `liv_variants` WHERE `product_id`=@prodC AND `nome`='M');
SET @varG := (SELECT id FROM `liv_variants` WHERE `product_id`=@prodC AND `nome`='G');

-- ── fotos: duas na camiseta, uma delas capa ──────────────────────────
INSERT INTO `liv_product_photos` (`product_id`,`caminho`,`caminho_thumb`,`capa`,`ordem`,`bytes`,`largura`,`altura`,`created_at`) VALUES
  (@prodC,'livraria/cam-2026-frente.jpg','livraria/thumb/cam-2026-frente.jpg',1,1,184320,1200,1200,NOW()),
  (@prodC,'livraria/cam-2026-costas.jpg','livraria/thumb/cam-2026-costas.jpg',0,2,171008,1200,1200,NOW());

-- ── evento ───────────────────────────────────────────────────────────
INSERT INTO `events` (`church_id`,`nome`,`local`,`inicio`,`fim`,`status`,`created_at`)
VALUES (@church,'Evento de Teste','Salão',CURDATE(),CURDATE(),'em_andamento',NOW());
SET @ev := LAST_INSERT_ID();

INSERT INTO `liv_event_settings` (`event_id`,`meta_tipo`,`created_at`) VALUES (@ev,'zero_a_zero',NOW());

INSERT INTO `liv_payment_methods` (`event_id`,`nome`,`taxa_percentual`,`ordem`,`ativo`) VALUES
  (@ev,'PIX',0.00,1,1), (@ev,'Crédito',4.50,2,1);
SET @pix := (SELECT id FROM `liv_payment_methods` WHERE `event_id`=@ev AND `nome`='PIX');
SET @cre := (SELECT id FROM `liv_payment_methods` WHERE `event_id`=@ev AND `nome`='Crédito');

INSERT INTO `liv_event_costs` (`event_id`,`supplier_id`,`descricao`,`valor`,`rateio`,`created_at`)
VALUES (@ev,@supL,'Frete ida e volta',200.00,'direto',NOW());

-- ── remessas ─────────────────────────────────────────────────────────
INSERT INTO `liv_shipments` (`event_id`,`supplier_id`,`condicao`,`created_at`) VALUES (@ev,@supL,'consignado',NOW());
SET @shipL := LAST_INSERT_ID();
INSERT INTO `liv_shipments` (`event_id`,`supplier_id`,`condicao`,`created_at`) VALUES (@ev,@supC,'firme',NOW());
SET @shipC := LAST_INSERT_ID();

-- livros: variant_id NULL
INSERT INTO `liv_shipment_items` (`shipment_id`,`product_id`,`variant_id`,`quantidade`,`custo_unitario`,`preco_venda`) VALUES
  (@shipL,@prodA,NULL,5,30.00,45.00),
  (@shipL,@prodB,NULL,5,24.00,36.00);
-- camiseta: uma linha POR TAMANHO, cada uma com saldo próprio
INSERT INTO `liv_shipment_items` (`shipment_id`,`product_id`,`variant_id`,`quantidade`,`custo_unitario`,`preco_venda`) VALUES
  (@shipC,@prodC,@varP,2,20.00,40.00),
  (@shipC,@prodC,@varM,3,20.00,40.00),
  (@shipC,@prodC,@varG,2,20.00,40.00);

SET @siA := (SELECT id FROM `liv_shipment_items` WHERE `shipment_id`=@shipL AND `product_id`=@prodA);
SET @siB := (SELECT id FROM `liv_shipment_items` WHERE `shipment_id`=@shipL AND `product_id`=@prodB);
SET @siP := (SELECT id FROM `liv_shipment_items` WHERE `shipment_id`=@shipC AND `variant_id`=@varP);
SET @siM := (SELECT id FROM `liv_shipment_items` WHERE `shipment_id`=@shipC AND `variant_id`=@varM);
SET @siG := (SELECT id FROM `liv_shipment_items` WHERE `shipment_id`=@shipC AND `variant_id`=@varG);

-- ── exemplares ───────────────────────────────────────────────────────
INSERT INTO `liv_copies` (`event_id`,`shipment_item_id`,`codigo`,`status`,`created_at`) VALUES
  (@ev,@siA,'TST001','disponivel',NOW()), (@ev,@siA,'TST002','disponivel',NOW()),
  (@ev,@siA,'TST003','disponivel',NOW()), (@ev,@siA,'TST004','disponivel',NOW()),
  (@ev,@siA,'TST005','disponivel',NOW()),
  (@ev,@siB,'TST006','disponivel',NOW()), (@ev,@siB,'TST007','disponivel',NOW()),
  (@ev,@siB,'TST008','disponivel',NOW()), (@ev,@siB,'TST009','disponivel',NOW()),
  (@ev,@siB,'TST010','disponivel',NOW()),
  (@ev,@siP,'CAM001','disponivel',NOW()), (@ev,@siP,'CAM002','disponivel',NOW()),
  (@ev,@siM,'CAM003','disponivel',NOW()), (@ev,@siM,'CAM004','disponivel',NOW()),
  (@ev,@siM,'CAM005','disponivel',NOW()),
  (@ev,@siG,'CAM006','disponivel',NOW()), (@ev,@siG,'CAM007','disponivel',NOW());

-- ── venda 1: PIX, dois livros numa transação (RF13) ──────────────────
INSERT INTO `liv_sales` (`event_id`,`payment_method_id`,`comprador`,`valor_bruto`,`taxa_percentual`,`taxa_valor`,`vendida_em`,`created_at`)
VALUES (@ev,@pix,'Comprador 1',81.00,0.00,0.00,NOW(),NOW());
SET @s1 := LAST_INSERT_ID();
INSERT INTO `liv_sale_items` (`sale_id`,`copy_id`,`preco`,`custo_unitario`) VALUES
  (@s1,(SELECT id FROM `liv_copies` WHERE `event_id`=@ev AND `codigo`='TST001'),45.00,30.00),
  (@s1,(SELECT id FROM `liv_copies` WHERE `event_id`=@ev AND `codigo`='TST006'),36.00,24.00);
UPDATE `liv_copies` SET `status`='vendido' WHERE `event_id`=@ev AND `codigo` IN ('TST001','TST006');

-- ── venda 2: crédito, taxa por transação (RN02) ──────────────────────
INSERT INTO `liv_sales` (`event_id`,`payment_method_id`,`comprador`,`valor_bruto`,`taxa_percentual`,`taxa_valor`,`vendida_em`,`created_at`)
VALUES (@ev,@cre,'Comprador 2',45.00,4.50,ROUND(45.00*0.045,2),NOW(),NOW());
SET @s2 := LAST_INSERT_ID();
INSERT INTO `liv_sale_items` (`sale_id`,`copy_id`,`preco`,`custo_unitario`) VALUES
  (@s2,(SELECT id FROM `liv_copies` WHERE `event_id`=@ev AND `codigo`='TST002'),45.00,30.00);
UPDATE `liv_copies` SET `status`='vendido' WHERE `event_id`=@ev AND `codigo`='TST002';

-- ── venda 3: uma camiseta M ──────────────────────────────────────────
INSERT INTO `liv_sales` (`event_id`,`payment_method_id`,`comprador`,`valor_bruto`,`taxa_percentual`,`taxa_valor`,`vendida_em`,`created_at`)
VALUES (@ev,@pix,'Comprador 3',40.00,0.00,0.00,NOW(),NOW());
SET @s3 := LAST_INSERT_ID();
INSERT INTO `liv_sale_items` (`sale_id`,`copy_id`,`preco`,`custo_unitario`) VALUES
  (@s3,(SELECT id FROM `liv_copies` WHERE `event_id`=@ev AND `codigo`='CAM003'),40.00,20.00);
UPDATE `liv_copies` SET `status`='vendido' WHERE `event_id`=@ev AND `codigo`='CAM003';

-- ── baixa por sorteio (RF15 / RN10) ──────────────────────────────────
SET @rSorteio := (SELECT id FROM `liv_writeoff_reasons` WHERE `church_id`=@church AND `nome`='Sorteio');
INSERT INTO `liv_writeoffs` (`event_id`,`reason_id`,`autorizado_por`,`observacao`,`registrada_em`,`created_at`)
VALUES (@ev,@rSorteio,'Pr. Fulano','Sorteio da plenária',NOW(),NOW());
SET @w1 := LAST_INSERT_ID();
INSERT INTO `liv_writeoff_items` (`writeoff_id`,`copy_id`,`custo_unitario`,`gera_custo`) VALUES
  (@w1,(SELECT id FROM `liv_copies` WHERE `event_id`=@ev AND `codigo`='TST007'),24.00,1);
UPDATE `liv_copies` SET `status`='baixado' WHERE `event_id`=@ev AND `codigo`='TST007';


-- =====================================================================
--  A. RESULTADO DO EVENTO (RF24 / RN04)
-- =====================================================================
SELECT
  (SELECT IFNULL(SUM(si.preco),0) FROM `liv_sale_items` si JOIN `liv_sales` s ON s.id=si.sale_id
    WHERE s.event_id=@ev AND s.cancelada_em IS NULL AND si.cancelado_em IS NULL)          AS receita,
  (SELECT IFNULL(SUM(si.custo_unitario),0) FROM `liv_sale_items` si JOIN `liv_sales` s ON s.id=si.sale_id
    WHERE s.event_id=@ev AND s.cancelada_em IS NULL AND si.cancelado_em IS NULL)
  + (SELECT IFNULL(SUM(wi.custo_unitario),0) FROM `liv_writeoff_items` wi JOIN `liv_writeoffs` w ON w.id=wi.writeoff_id
    WHERE w.event_id=@ev AND w.cancelada_em IS NULL AND wi.cancelado_em IS NULL AND wi.gera_custo=1) AS devido,
  (SELECT IFNULL(SUM(c.valor),0) FROM `liv_event_costs` c WHERE c.event_id=@ev)           AS custos,
  (SELECT IFNULL(SUM(s.taxa_valor),0) FROM `liv_sales` s
    WHERE s.event_id=@ev AND s.cancelada_em IS NULL)                                       AS taxas;


-- =====================================================================
--  B. SALDO POR ITEM E VARIAÇÃO — "tem no M?" (RF12 / RF21)
--     O saldo sai de um COUNT sobre os exemplares: não existe campo de
--     quantidade para desencontrar do físico.
-- =====================================================================
SELECT
  cat.nome                                   AS categoria,
  p.nome                                     AS item,
  IFNULL(v.nome,'—')                         AS variacao,
  COUNT(*)                                   AS enviados,
  SUM(cp.status='disponivel')                AS disponivel,
  SUM(cp.status='vendido')                   AS vendido,
  SUM(cp.status='baixado')                   AS baixado
FROM `liv_copies` cp
JOIN `liv_shipment_items` si ON si.id = cp.shipment_item_id
JOIN `liv_products`  p   ON p.id  = si.product_id
JOIN `liv_categories` cat ON cat.id = p.category_id
LEFT JOIN `liv_variants` v ON v.id = si.variant_id
WHERE cp.event_id = @ev
-- agrupa pelas CHAVES, não pelos nomes: com sql_mode=only_full_group_by
-- (padrão no MySQL 8, inclusive em produção), agrupar por id deixa todas
-- as colunas daquela tabela funcionalmente dependentes e utilizáveis no
-- SELECT e no ORDER BY. Agrupar por nome não dá essa garantia.
GROUP BY cat.id, p.id, v.id
ORDER BY cat.ordem, p.nome, v.ordem;


-- =====================================================================
--  C. LISTAGEM DA MESA — nome, capa e os campos que a categoria manda
--     mostrar. Prova que o mesmo SELECT serve livro e camiseta.
-- =====================================================================
SELECT
  p.nome,
  cat.nome                                       AS categoria,
  IFNULL(p.atributos->>'$.autor',
         p.atributos->>'$.modelo')               AS destaque,
  IFNULL(p.atributos->>'$.editora',
         p.atributos->>'$.marca')                AS secundario,
  IFNULL(f.caminho_thumb,'(sem foto)')           AS capa
FROM `liv_products` p
JOIN `liv_categories` cat ON cat.id = p.category_id
LEFT JOIN `liv_product_photos` f ON f.product_id = p.id AND f.capa = 1
WHERE p.church_id = @church AND p.ativo = 1
ORDER BY cat.ordem, p.nome;
