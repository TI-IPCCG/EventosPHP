<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gatilhos de "um exemplar não sai duas vezes".
 *
 * Por que existem: o índice UNIQUE em `copy_ativo` impede vender o mesmo
 * exemplar duas vezes, mas só DENTRO de cada tabela. Ele NÃO impede sortear um
 * exemplar que já foi vendido — índice não cruza tabelas. Testado: sem estes
 * gatilhos, o INSERT em liv_writeoff_items de uma cópia com status 'vendido'
 * passa direto.
 *
 * ORDEM DE GRAVAÇÃO que eles impõem ao código:
 *   1. INSERT do item (venda ou baixa)  ← exemplar ainda 'disponivel'
 *   2. UPDATE de liv_copies.status      ← vira 'vendido' ou 'baixado'
 * No cancelamento, o inverso: status volta para 'disponivel' e os itens
 * recebem cancelado_em.
 *
 * ⚠ O que ISTO NÃO resolve: a corrida entre duas transações simultâneas —
 * ambas podem ler 'disponivel' antes de qualquer uma gravar. Essa trava é do
 * Service: transação + SELECT ... FOR UPDATE na linha de liv_copies antes de
 * inserir. As três camadas se somam.
 *
 * ⚠ Exige o privilégio TRIGGER. Se o host não conceder, o app continua
 * correto — perde só esta rede de segurança.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `liv_sale_items_bi`');
        DB::unprepared('DROP TRIGGER IF EXISTS `liv_writeoff_items_bi`');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `liv_sale_items_bi`
            BEFORE INSERT ON `liv_sale_items`
            FOR EACH ROW
            BEGIN
                DECLARE v_status VARCHAR(20);

                SELECT `status` INTO v_status
                  FROM `liv_copies` WHERE `id` = NEW.`copy_id`;

                IF v_status <> 'disponivel' THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'Exemplar indisponivel para venda (ja vendido, baixado ou devolvido)';
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER `liv_writeoff_items_bi`
            BEFORE INSERT ON `liv_writeoff_items`
            FOR EACH ROW
            BEGIN
                DECLARE v_status VARCHAR(20);

                SELECT `status` INTO v_status
                  FROM `liv_copies` WHERE `id` = NEW.`copy_id`;

                IF v_status <> 'disponivel' THEN
                    SIGNAL SQLSTATE '45000'
                      SET MESSAGE_TEXT = 'Exemplar indisponivel para baixa (ja vendido, baixado ou devolvido)';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `liv_sale_items_bi`');
        DB::unprepared('DROP TRIGGER IF EXISTS `liv_writeoff_items_bi`');
    }
};
