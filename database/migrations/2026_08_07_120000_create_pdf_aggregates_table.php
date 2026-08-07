<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agregados extraídos de relatório em PDF (Spec 007, FR-705, §D6).
 *
 * ## ⚠️⚠️ Tabela SEPARADA, e é a decisão que sustenta o item 3
 *
 * **Não existe caminho em que um PDF grave linha em `sensor_readings`,
 * `insulin_doses`, `meals` ou qualquer outra tabela de evento.**
 *
 * *Por quê tão rígido:* as nove tabelas de evento são a fundação determinística do
 * produto — chaves únicas, upsert idempotente, 10 armadilhas documentadas, e as
 * métricas de três fases construídas em cima. Um agregado de PDF ali traria número
 * de granularidade e procedência diferentes, e **nenhuma métrica saberia disso**:
 * o `StatisticsCalculator` trataria um "TIR 78%" resumido como se fosse a soma de
 * 3.616 leituras.
 *
 * Aqui, o agregado é o que é: um resumo, marcado como resumo, exibido como resumo.
 *
 * ## Artigo IX
 *
 * ⚠️ Tipos portáveis. `string` para a métrica e para a procedência, `decimal` para
 * o valor, `date` para o período. Nada de `enum` de dialeto — acrescentar uma
 * métrica não pode virar migration.
 *
 * ⚠️ **Esta é a QUINTA migration da pendência de MariaDB** (`period_reports`, as
 * três colunas de narrativa, `chat_conversations`, `chat_messages`, e esta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_aggregates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // De qual importação veio — para poder desfazer uma extração ruim
            // sem tocar em nada mais.
            $table->foreignId('import_id')->nullable()->constrained()->nullOnDelete();

            // Vocabulário fechado pelo enum `PdfMetric`, string na coluna.
            $table->string('metric', 48);
            $table->decimal('value', 10, 3);
            $table->string('unit', 16);

            $table->date('period_start');
            $table->date('period_end');

            /*
             * ⚠️ A coluna que impede a confusão (§D7).
             *
             * Sempre `pdf_aggregate` — validado no construtor do `PdfAggregate`.
             * Ela existe para que a tela **não possa** exibir este número como se
             * fosse métrica de CSV: é o Artigo V por analogia, e "nunca esconder o
             * denominador" vale também para "de onde isso veio".
             */
            $table->string('source', 24)->default('pdf_aggregate');

            $table->timestamps();

            // Reimportar o mesmo relatório ATUALIZA; não empilha. Artigo VIII.4
            // por analogia.
            $table->unique(['user_id', 'metric', 'period_start', 'period_end']);
            $table->index(['user_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_aggregates');
    }
};
