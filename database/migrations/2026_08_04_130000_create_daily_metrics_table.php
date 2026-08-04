<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache de métricas diárias (FR-108).
 *
 * ⚠️ Sem `import_id` de propósito: uma métrica diária pode agregar leituras de
 * VÁRIOS imports com períodos sobrepostos. Amarrá-la a um import seria mentira,
 * e convidaria a recalcular só o que veio do último arquivo.
 *
 * ⚠️ GMI não é coluna diária. Ele exige ≥14 dias para significar algo (Artigo
 * V), então é sempre calculado sobre período — nunca sobre um dia. Uma coluna
 * `gmi` aqui seria um convite a exibir número inválido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('local_date');

            $table->integer('reading_count');

            // Cobertura DIÁRIA usa 288 como denominador — um dia é um dia
            // inteiro. Difere da cobertura do PERÍODO, que usa o span (§D2).
            $table->decimal('coverage_pct', 5, 2);

            $table->decimal('mean_glucose', 6, 2);
            $table->decimal('sd_glucose', 6, 2);
            $table->decimal('cv_pct', 5, 2);

            $table->decimal('tir_pct', 5, 2);
            $table->decimal('tar_level1_pct', 5, 2);
            $table->decimal('tar_level2_pct', 5, 2);
            $table->decimal('tbr_level1_pct', 5, 2);
            $table->decimal('tbr_level2_pct', 5, 2);

            $table->decimal('total_insulin_u', 8, 2)->nullable();
            $table->decimal('auto_insulin_u', 8, 2)->nullable();
            $table->decimal('bolus_insulin_u', 8, 2)->nullable();
            $table->decimal('total_carbs_g', 8, 2)->nullable();

            // Invalida o cache quando uma fórmula muda, sem precisar de
            // migration nem de apagar a tabela na mão.
            $table->string('metrics_version', 20);
            $table->timestamps();

            $table->unique(['user_id', 'local_date']);
            $table->index(['user_id', 'metrics_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_metrics');
    }
};
