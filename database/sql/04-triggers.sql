-- =====================================================================
--  App EVENTOS — IPCCG
--  Arquivo 04 de 05: TRIGGERS DA RN09
--  Schema: ipccgorg_Eventos          Rodar DEPOIS do 02
-- ---------------------------------------------------------------------
--  Por que existe: o índice UNIQUE em `copy_ativo` impede vender o mesmo
--  exemplar duas vezes, mas só DENTRO de cada tabela. Ele não impede
--  SORTEAR um exemplar que já foi VENDIDO — índice não cruza tabelas.
--  Estes dois gatilhos fecham essa porta: nenhuma saída é aceita se o
--  exemplar não estiver 'disponivel'.
--
--  ⚠ O QUE ISTO NÃO RESOLVE: corrida entre duas transações simultâneas.
--  Se dois voluntários gravarem no mesmo instante, ambos podem ler
--  'disponivel' antes de qualquer um gravar. A trava dessa corrida é no
--  Service: transação + SELECT ... FOR UPDATE na linha de liv_copies
--  antes de inserir. Estes gatilhos são a rede embaixo disso.
--
--  ⚠ IMPORTAÇÃO: o arquivo usa DELIMITER. O phpMyAdmin entende quando o
--  arquivo inteiro é importado pela aba "Importar". Se você colar na aba
--  "SQL", preencha o campo "Delimitador" com $$ ou importe o arquivo.
--
--  ⚠ PRIVILÉGIO: exige TRIGGER no usuário do banco. Se o host recusar,
--  PODE PULAR ESTE ARQUIVO — o app continua correto, só perde a rede de
--  segurança. Nada mais depende dele.
-- ---------------------------------------------------------------------
--  ORDEM DE GRAVAÇÃO que os gatilhos impõem ao código:
--    1. INSERT do item (venda ou baixa)   ← exemplar ainda 'disponivel'
--    2. UPDATE de liv_copies.status       ← vira 'vendido' ou 'baixado'
--  No cancelamento, o inverso: devolve o status para 'disponivel' e
--  marque cancelado_em nos itens.
-- =====================================================================

DROP TRIGGER IF EXISTS `liv_sale_items_bi`;
DROP TRIGGER IF EXISTS `liv_writeoff_items_bi`;

DELIMITER $$

-- Venda: só aceita exemplar disponível.
CREATE TRIGGER `liv_sale_items_bi`
BEFORE INSERT ON `liv_sale_items`
FOR EACH ROW
BEGIN
  DECLARE v_status VARCHAR(20);
  DECLARE v_codigo VARCHAR(20);

  SELECT `status`, `codigo` INTO v_status, v_codigo
    FROM `liv_copies` WHERE `id` = NEW.`copy_id`;

  IF v_status <> 'disponivel' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'RN09: exemplar indisponivel para venda (ja vendido, baixado ou devolvido)';
  END IF;
END$$

-- Baixa sem venda: mesma regra.
CREATE TRIGGER `liv_writeoff_items_bi`
BEFORE INSERT ON `liv_writeoff_items`
FOR EACH ROW
BEGIN
  DECLARE v_status VARCHAR(20);

  SELECT `status` INTO v_status
    FROM `liv_copies` WHERE `id` = NEW.`copy_id`;

  IF v_status <> 'disponivel' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'RN09: exemplar indisponivel para baixa (ja vendido, baixado ou devolvido)';
  END IF;
END$$

DELIMITER ;
