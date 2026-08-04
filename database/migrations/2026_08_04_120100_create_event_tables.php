<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As sete tabelas de evento produzidas pelo importador.
 *
 * ## Decisão de tempo (FR-007, Artigo VIII.5)
 *
 * Toda tabela carrega o mesmo quarteto:
 *
 *   recorded_at_local  exatamente o que o CSV diz — hora de parede do aparelho
 *   recorded_at_utc    derivado no import usando imports.timezone
 *   local_date         denormalizado, para agregação diária
 *   local_hour         denormalizado, para perfil por hora
 *
 * `local_date`/`local_hour` são colunas NORMAIS, preenchidas pelo importador.
 * Colunas geradas resolveriam no banco, mas a sintaxe difere entre SQLite,
 * MySQL e Postgres — e portabilidade é o Artigo IX.
 *
 * O risco aceito (divergirem se alguém escrever fora do importador) é coberto
 * por teste de invariante, não por confiança.
 *
 * ## Chave única em TODA tabela (FR-006)
 *
 * Relatórios se sobrepõem no tempo. Sem chave única + upsert, reimportar
 * duplica dados e envenena todas as métricas — silenciosamente.
 */
return new class extends Migration
{
    /** Colunas comuns a todo evento com instante. */
    private function timeColumns(Blueprint $table): void
    {
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->foreignId('import_id')->constrained();

        $table->dateTime('recorded_at_local');
        $table->dateTime('recorded_at_utc');
        $table->date('local_date');
        $table->unsignedTinyInteger('local_hour');

        // §A6 — coluna `Index` do CSV. Monotônica, desempata hora ambígua no
        // fim do horário de verão, quando recorded_at_local se repete.
        $table->decimal('device_index', 12, 5)->nullable();
    }

    private function timeIndexes(Blueprint $table): void
    {
        $table->index(['user_id', 'local_date']);
        $table->index(['user_id', 'local_hour']);
    }

    public function up(): void
    {
        // ── Leituras de CGM — a tabela de volume, ~288/dia ─────────────────
        Schema::create('sensor_readings', function (Blueprint $table) {
            $table->id();
            $this->timeColumns($table);

            $table->smallInteger('glucose_mgdl');
            $table->decimal('isig', 6, 2)->nullable();
            $table->string('sensor_exception', 64)->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'recorded_at_local']);
            $this->timeIndexes($table);
        });

        // ── Glicemia capilar — SEPARADA do sensor, de propósito ────────────
        // Métricas de CGM (TIR, GMI, CV) só valem sobre a série do sensor.
        // Tabela única convidaria ao bug de capilar entrar no cálculo de TIR:
        // silencioso, e o resultado continua plausível. Separar torna o erro
        // impossível por estrutura, não por disciplina.
        Schema::create('bg_readings', function (Blueprint $table) {
            $table->id();
            $this->timeColumns($table);

            $table->smallInteger('glucose_mgdl');
            $table->string('source', 64)->nullable();
            $table->boolean('used_for_calibration')->default(false);

            $table->timestamps();

            // glucose_mgdl entra na chave: o mesmo instante pode registrar
            // leituras distintas em linhas separadas do bloco Pump.
            $table->unique(['user_id', 'recorded_at_local', 'glucose_mgdl']);
            $this->timeIndexes($table);
        });

        // ── Refeições (linhas BWZ) ─────────────────────────────────────────
        Schema::create('meals', function (Blueprint $table) {
            $table->id();
            $this->timeColumns($table);

            $table->decimal('carbs_g', 6, 2);

            // Configuração VIGENTE no momento do bolus — é o que permite
            // reconstruir o perfil do aparelho sem o relatório de definições.
            $table->decimal('carb_ratio', 5, 2)->nullable();
            $table->decimal('insulin_sensitivity', 5, 2)->nullable();
            $table->smallInteger('target_low')->nullable();
            $table->smallInteger('target_high')->nullable();

            $table->smallInteger('bg_input')->nullable();
            $table->decimal('estimate_u', 8, 3)->nullable();
            $table->decimal('correction_u', 8, 3)->nullable();
            $table->decimal('food_u', 8, 3)->nullable();
            $table->decimal('active_insulin_u', 8, 3)->nullable();
            $table->string('bwz_status', 32)->nullable();

            // NÃO vêm do CSV — calculados no pós-import pelo MealEnricher,
            // consultando sensor_readings já gravadas.
            $table->smallInteger('peak_2h')->nullable();
            $table->smallInteger('delta_2h')->nullable();
            $table->smallInteger('glucose_4h')->nullable();

            // Input do usuário (Spec 007): "pizza", "café da manhã".
            $table->string('label')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'recorded_at_local']);
            $this->timeIndexes($table);
        });

        // ── Doses de insulina ──────────────────────────────────────────────
        Schema::create('insulin_doses', function (Blueprint $table) {
            $table->id();
            $this->timeColumns($table);

            $table->string('kind', 32);
            $table->string('raw_source', 64)->nullable();
            $table->boolean('is_automatic')->default(false);

            // ⚠️ Artigo VIII.3 — units_selected NUNCA entra em soma. Está aqui
            // para tornar visível a entrega parcial (selected ≠ delivered).
            // Somar as duas dobra o total: 295,150 U viram 590,300 U.
            $table->decimal('units_selected', 8, 3)->nullable();
            $table->decimal('units_delivered', 8, 3)->nullable();

            // §A9 — contador de 1 byte que CICLA (6–255 no export de
            // referência; o 214 reaparece 3x). Serve para ligar pedido↔entrega
            // DENTRO de uma janela, nunca como identificador global.
            $table->unsignedSmallInteger('bolus_number')->nullable();

            $table->string('cancellation_reason', 64)->nullable();

            // Instante da confirmação de entrega (~5 min após o pedido).
            // recorded_at_local guarda o do PEDIDO, que é quando a pessoa agiu
            // e é o que casa com a refeição.
            $table->dateTime('delivered_at_local')->nullable();

            $table->foreignId('meal_id')->nullable()->constrained('meals')->nullOnDelete();

            /**
             * ⚠️ Chave de deduplicação — resolve um furo real no FR-006.
             *
             * A chave natural seria (user_id, recorded_at_local, kind,
             * bolus_number). Mas `bolus_number` é NULLABLE, e tanto MySQL
             * quanto SQLite tratam NULL como DISTINTO em índice único: duas
             * doses sem número no mesmo instante não colidiriam, e cada
             * reimportação inseriria de novo.
             *
             * `dedupe_key` é o mesmo tuplo serializado com o nulo explícito,
             * NOT NULL. Determinístico, portável, e sem valor-sentinela
             * (`bolus_number = 0` seria um chute sobre o domínio do contador).
             *
             * Preenchido por InsulinDose::makeDedupeKey().
             */
            $table->string('dedupe_key', 64);

            $table->timestamps();

            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['user_id', 'recorded_at_local']);
            $this->timeIndexes($table);
        });

        // ── Total diário de insulina automática (bloco 2 do CSV) ───────────
        // NÃO é bolus pontual. Ignorar este bloco subestima a insulina total
        // em ~60% num usuário de 780G com loop fechado.
        Schema::create('daily_auto_insulin', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_id')->constrained();

            $table->date('local_date');
            $table->decimal('units_delivered', 8, 3);

            $table->timestamps();

            $table->unique(['user_id', 'local_date']);
        });

        // ── Basal manual programada ────────────────────────────────────────
        Schema::create('basal_rates', function (Blueprint $table) {
            $table->id();
            $this->timeColumns($table);

            $table->decimal('rate_uh', 6, 3);

            $table->timestamps();

            $table->unique(['user_id', 'recorded_at_local']);
            $this->timeIndexes($table);
        });

        // ── Eventos de dispositivo ─────────────────────────────────────────
        Schema::create('device_events', function (Blueprint $table) {
            $table->id();
            $this->timeColumns($table);

            // Comprimentos curtos de propósito: as quatro colunas entram num
            // índice único, e no MySQL com utf8mb4 cada char custa 4 bytes.
            // 32 + 191 mantém a chave folgada abaixo do limite do InnoDB.
            $table->string('category', 32);
            $table->string('code', 191);

            $table->json('payload')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'recorded_at_local', 'category', 'code']);
            $table->index(['user_id', 'category']);
            $this->timeIndexes($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_events');
        Schema::dropIfExists('basal_rates');
        Schema::dropIfExists('daily_auto_insulin');
        Schema::dropIfExists('insulin_doses');
        Schema::dropIfExists('meals');
        Schema::dropIfExists('bg_readings');
        Schema::dropIfExists('sensor_readings');
    }
};
