#!/usr/bin/env python3
"""
Compara o schema gerado pelas MIGRATIONS com o gerado pelos ARQUIVOS SQL.

Existem duas fontes de verdade neste projeto, e é de propósito:
  · database/migrations/  → dev e qualquer host com terminal
  · database/sql/         → cPanel, colado no phpMyAdmin sem terminal

Duas fontes divergem sozinhas com o tempo. Este script prova que não
divergiram: compara coluna a coluna, índice a índice e FK a FK.

Uso:
    php artisan migrate:fresh --force          # constrói pelas migrations
    mysql -e "DROP DATABASE IF EXISTS cmp_sql; CREATE DATABASE cmp_sql
              CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    for f in database/sql/0*.sql; do mysql cmp_sql < "$f"; done
    python3 scripts/comparar-schema.py

Sai com código 1 se houver qualquer divergência.
"""

import subprocess, sys

def q(db, sql):
    out = subprocess.run(['mysql','-N','-B','--default-character-set=utf8mb4',db,'-e',sql],
                         capture_output=True, text=True)
    if out.returncode: sys.exit('erro: '+out.stderr)
    return [l.split('\t') for l in out.stdout.strip().split('\n') if l]

COLS = """SELECT table_name, column_name, column_type, is_nullable, column_default,
                 extra, generation_expression
          FROM information_schema.columns
          WHERE table_schema='{db}' AND table_name <> 'migrations'
          ORDER BY table_name, column_name"""
IDX = """SELECT table_name, index_name, non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index)
         FROM information_schema.statistics
         WHERE table_schema='{db}' AND table_name <> 'migrations'
         GROUP BY table_name, index_name, non_unique ORDER BY table_name, index_name"""
FK  = """SELECT k.table_name, k.column_name, k.referenced_table_name, k.referenced_column_name,
                r.delete_rule
         FROM information_schema.key_column_usage k
         JOIN information_schema.referential_constraints r
           ON r.constraint_name=k.constraint_name AND r.constraint_schema=k.table_schema
         WHERE k.table_schema='{db}' ORDER BY k.table_name, k.column_name"""

A, B = 'ipccgorg_Eventos', 'cmp_sql'   # migrations  x  arquivos SQL
problemas = 0

for nome, sql, chave in [('COLUNAS', COLS, 2), ('ÍNDICES', IDX, 2), ('CHAVES ESTRANGEIRAS', FK, 2)]:
    a = {tuple(r[:chave]): r for r in q(A, sql.format(db=A))}
    b = {tuple(r[:chave]): r for r in q(B, sql.format(db=B))}
    so_migration = sorted(set(a) - set(b))
    so_sql       = sorted(set(b) - set(a))
    difere       = [k for k in set(a) & set(b) if a[k] != b[k]]
    print(f"\n── {nome} ── migrations: {len(a)}  |  SQL: {len(b)}")
    if not (so_migration or so_sql or difere):
        print("   ✓ idênticos")
        continue
    problemas += 1
    for k in so_migration: print("   só nas MIGRATIONS:", ' · '.join(k))
    for k in so_sql:       print("   só no SQL        :", ' · '.join(k))
    for k in sorted(difere):
        print("   DIVERGE:", ' · '.join(k))
        for i,(x,y) in enumerate(zip(a[k], b[k])):
            if x != y: print(f"       campo[{i}]  migrations={x!r}   sql={y!r}")

print()
sys.exit(1 if problemas else 0)
