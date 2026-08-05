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

export interface ImportBlockPayload {
    key: 'pump' | 'auto_insulin' | 'sensor';
    label: string;
    lines: number;
    breakdown: { label: string; count: number; discarded: boolean }[];
    events_and_discards: number;
    /**
     * Verdadeiro quando nenhuma linha do bloco ficou sem classificação.
     * Uma linha pode gerar mais de um evento (§A4), então a soma pode passar
     * do total de linhas — a conferência é `>=`, não `===`.
     */
    reconciles: boolean;
}

export interface ImportSummaryPayload {
    id: number;
    filename: string;
    status: 'pending' | 'processing' | 'done' | 'failed';
    device: string | null;
    firmware: string | null;
    cgm: string | null;
    timezone: string;
    glucose_unit: string;
    period: { from: string | null; to: string | null };
    imported_at: string | null;
    blocks: ImportBlockPayload[];
    written: Record<string, number>;
    /** Avisos aparecem na tela. Esconder aviso é o mesmo que não ter aviso. */
    warnings: string[];
}

/*
 * Fase 4 — motor de padrões.
 */

export type FindingSeverity = 'priority' | 'attention' | 'info';

/** Uma linha da evidência, com rótulo e valor JÁ FORMATADOS pelo servidor. */
export interface EvidenceRow {
    key: string;
    label: string;
    value: string;
}

/**
 * Um achado pronto para a tela.
 *
 * ⚠️ Note que não existe aqui nenhum valor cru: `severity` vem com
 * `severity_label`, e a evidência vem com rótulo e valor em texto. Se um dia
 * aparecer um número puro sem o equivalente traduzido ao lado, é sinal de que a
 * decisão escapou para o cliente (NFR-404).
 */
export interface PresentedFindingPayload {
    rule_id: string;
    title: string;
    prose: string;
    severity: FindingSeverity;
    severity_label: string;
    rank: number;
    requires_clinical_handoff: boolean;
    evidence: EvidenceRow[];
}

export interface RuleFailurePayload {
    rule_id: string;
    message: string;
}

/**
 * O pacote da tela de avaliação.
 *
 * ⚠️ `has_report` distingue os DOIS estados vazios: sem relatório ("ainda não há
 * o que analisar") e relatório com zero achados ("nenhum padrão para apontar" —
 * boa notícia, §D10).
 */
/**
 * O texto escrito por IA. ⚠️ `null` é o estado NORMAL — sem ele a tela é
 * exatamente a de ontem, com os dez achados (Artigo I, §D3).
 */
export interface NarrativePayload {
    text: string;
    /** Procedência: qual modelo escreveu e quando. */
    model: string | null;
    generated_at: string | null;
}

export interface EvaluationPayload {
    has_report: boolean;
    period: { from: string; to: string; label: string } | null;
    /** Artigo V — o denominador DAQUELE relatório, não o de hoje. */
    coverage: {
        percentage: number;
        span_days: number;
        validity: string;
        summary: string;
    } | null;
    /** Já ordenados pelo servidor: severidade e depois rank. */
    findings: PresentedFindingPayload[];
    rule_failures: RuleFailurePayload[];
    /** §D9 — relatório gerado por versão anterior. Sinaliza, não recalcula. */
    is_stale: boolean;
    generated_at: string | null;
    /** ⚠️ ENRIQUECIMENTO, nunca substituição. `null` é o estado normal. */
    narrative: NarrativePayload | null;
}
