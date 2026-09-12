<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A entrada, POR DIA.
 *
 * ⚠ `registration_ativa` é VIRTUAL, e isso NÃO é preferência: a base é
 * `registration_id`, cuja FK CASCATEIA, e o InnoDB recusa CASCADE em coluna-base
 * de coluna gerada STORED (ERROR 1215 — foi o que aconteceu com
 * liv_product_photos.capa_unica). VIRTUAL aceita UNIQUE do mesmo jeito e não
 * ocupa espaço.
 *
 * Efeito: UM check-in ATIVO por (dia, inscrição), garantido pelo BANCO, mesmo
 * com dois leitores de QR no mesmo segundo. O cancelado vira NULL, não colide, e
 * a pessoa pode ser marcada de novo — com o rastro do erro preservado.
 *
 * Ler duplicado é rotina na portaria e aqui vira informação: em vez de duplicar,
 * o segundo operador vê "já entrou às 19h12, por Maria" — que é como se pega
 * crachá compartilhado.
 *
 * ⚠ `registrado_em` é DATETIME (como liv_sales.vendida_em) e NÃO tem DEFAULT
 * CURRENT_TIMESTAMP. CURRENT_TIMESTAMP resolveria no time_zone da SESSÃO MySQL,
 * que não é o fuso da congregação — seria reintroduzir o bug de fuso por baixo.
 * O valor vem sempre do PHP, por Event::agora() → Church::agora($church_id).
 * `now()` puro erra 3 horas em comando de terminal, que não tem sessão web.
 *
 * event_day_id é RESTRICT: apagar um dia que já tem presença é apagar história.
 * Para suspender o dia existe par_event_days.ativo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('par_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained('par_registrations')->cascadeOnDelete();
            $table->foreignId('event_day_id')->constrained('par_event_days')->restrictOnDelete();
            $table->enum('canal', ['qr', 'codigo', 'busca', 'manual', 'retroativo'])->default('qr');
            $table->dateTime('registrado_em');       // hora-de-parede da congregação
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelado_em')->nullable();
            $table->foreignId('cancelado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('observacao', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unsignedBigInteger('registration_ativa')->nullable()
                  ->virtualAs('IF(`cancelado_em` IS NULL, `registration_id`, NULL)');

            $table->unique(['event_day_id', 'registration_ativa']);
            $table->index(['event_day_id', 'registrado_em']);   // contador ao vivo da portaria
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('par_checkins');
    }
};
