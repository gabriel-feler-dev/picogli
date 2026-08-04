/**
 * Tipos que espelham os value objects do PHP.
 *
 * ⚠️ Divergência entre estes tipos e o payload real NÃO é pega pelo
 * compilador — TypeScript não conhece o PHP. Por isso o teste de feature
 * verifica a FORMA do payload que o controller entrega.
 *
 * Regra desta camada (NFR-201): o React recebe números prontos. Não calcula
 * média, percentual, percentil, nem classifica faixa. Note que nenhum tipo aqui
 * expõe um valor cru SEM o equivalente já traduzido ao lado — se passar a
 * expor, é sinal de que a tradução escapou para o cliente.
 */

export type GlucoseRange = 'very_low' | 'low' | 'target' | 'high' | 'very_high';

export type MetricStatus = 'met' | 'not_met' | 'unreliable';

export interface TranslatedMetricPayload {
    key: string;
    label: string;
    plain_value: string;
    technical_value: string;
    target_label: string | null;
    status: MetricStatus;
    explanation: string;
}

/** Cobertura — Artigo V. Sempre presente junto de qualquer métrica. */
export interface CoveragePayload {
    reading_count: number;
    expected_count: number;
    /** Span REAL, não arredondado: 13.8, não 14. */
    span_in_days: number;
    percentage: number;
    summary: string;
    span_note: string;
    readings_note: string;
}

export interface ValidityPayload {
    status: 'valid' | 'insufficient_days' | 'insufficient_coverage';
    is_valid: boolean;
    /** Motivo distinguível: faltar dias e sensor fora do ar pedem ações diferentes. */
    message: string | null;
}

export interface HourlyBucketPayload {
    hour: number;
    count: number;
    mean: number | null;
    percent_above: number | null;
    percent_below: number | null;
    /** Classificada no SERVIDOR. null quando a hora não tem leitura. */
    dominant_range: GlucoseRange | null;
}

export interface HourlyPercentilePayload {
    hour: number;
    count: number;
    /** null, nunca 0: zero pareceria glicose de 0 mg/dL. */
    p5: number | null;
    p25: number | null;
    p50: number | null;
    p75: number | null;
    p95: number | null;
}

export interface DailyMetricPayload {
    local_date: string;
    reading_count: number;
    coverage_pct: number;
    mean_glucose: number;
    tir_pct: number;
    cv_pct: number;
    below_pct: number;
    /** Matiz da célula, derivado da meta em config — não de limiar em JS. */
    tir_status: MetricStatus;
    /** Artigo V no nível do dia: cobertura baixa precisa ser visível. */
    low_coverage: boolean;
}

export interface SensorGapPayload {
    start: string;
    end: string;
    minutes: number;
}

export interface PeriodSummaryPayload {
    period: { from: string; to: string };
    coverage: CoveragePayload;
    validity: ValidityPayload;
    /** Limites clínicos vindos do servidor: `70` e `180` não são constante em JS. */
    ranges: Record<GlucoseRange, { min: number | null; max: number | null }>;
    metrics: TranslatedMetricPayload[];
    hourly_profile: HourlyBucketPayload[];
    hourly_percentiles: HourlyPercentilePayload[];
    daily_metrics: DailyMetricPayload[];
    gaps: SensorGapPayload[];
    has_stale_metrics: boolean;
    stale_message: string | null;
}
