<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A narrativa gerada, anexada ao relatório de padrões (FR-506, §D8).
 *
 * ⚠️ **As três colunas são nullable, e `null` é o estado NORMAL** — não a
 * exceção. Relatório sem narrativa é o relatório de hoje, que a tela já sabe
 * renderizar. Se a IA nunca funcionar, nada aqui fica inconsistente.
 *
 * ⚠️ `narrative_model` e `narrative_generated_at` não são enfeite: respondem as
 * duas perguntas que aparecem quando um texto sai estranho — **qual modelo
 * escreveu** e **quando**. Sem elas, investigar começa por adivinhar.
 *
 * ## Por que persistir em vez de gerar a cada visita (§D8)
 *
 * Reprodutibilidade, custo e latência — nessa ordem. Um texto que muda a cada F5
 * mina a confiança mais rápido que um texto ruim: a pessoa deixa de acreditar
 * que os números por trás são estáveis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('period_reports', function (Blueprint $table) {
            $table->text('narrative')->nullable();
            $table->string('narrative_model', 64)->nullable();
            $table->dateTime('narrative_generated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('period_reports', function (Blueprint $table) {
            $table->dropColumn(['narrative', 'narrative_model', 'narrative_generated_at']);
        });
    }
};
