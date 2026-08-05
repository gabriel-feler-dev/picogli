<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relatórios de padrões por período (FR-413).
 *
 * ## Duas versões, e não uma (§D9)
 *
 * ⚠️ `engine_version` identifica as REGRAS que produziram os achados;
 * `metrics_version`, as FÓRMULAS de que os achados derivaram.
 *
 * Um achado calculado sobre métricas da versão `2026.08.1` exibido ao lado de
 * métricas recalculadas na `2026.09.1` é inconsistência que ninguém percebe — o
 * texto continua plausível. Guardar as duas é o que permite dizer, depois, que
 * este relatório precisa ser refeito.
 *
 * ## `finding_count` denormalizado
 *
 * ⚠️ Contar elementos dentro de uma coluna JSON exige função específica de
 * dialeto (`JSON_LENGTH` no MySQL, `json_array_length` no SQLite). O Artigo IX
 * proíbe, e a fase 1 já pagou essa conta com o `strftime` do `MealEnricher`.
 * Uma coluna inteira resolve, e é o que a listagem da tela vai ordenar.
 *
 * ## Chave única por período
 *
 * Reprocessar **atualiza**; não empilha. Sem isso, cada visita à tela que
 * disparasse um recálculo deixaria uma linha nova e o histórico de versões
 * viraria lixo. É o Artigo VIII.4 aplicado por analogia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->date('period_start');
            $table->date('period_end');

            $table->string('engine_version', 32);
            $table->string('metrics_version', 32);

            // `json` e não `jsonb`: vira `json` no MySQL e `TEXT` no SQLite, e o
            // Laravel serializa dos dois lados (Artigo IX).
            $table->json('findings');
            $table->json('rule_failures')->nullable();

            // ⚠️ Denormalizado — ver o bloco acima. `unsignedSmallInteger` e não
            // `tinyInteger`: R4 pode emitir dois achados, e nada garante que
            // regras futuras emitam um só.
            $table->unsignedSmallInteger('finding_count');

            // Cobertura do período no momento da geração. Artigo V: o relatório
            // carrega o próprio denominador, sem depender de recalcular.
            $table->decimal('coverage_pct', 5, 2);
            $table->decimal('span_days', 6, 2);
            $table->string('validity', 32);

            $table->dateTime('generated_at');
            $table->timestamps();

            $table->unique(['user_id', 'period_start', 'period_end']);
            $table->index(['user_id', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_reports');
    }
};
