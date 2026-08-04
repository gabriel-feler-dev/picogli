/**
 * Tipos que espelham os value objects do PHP.
 *
 * ⚠️ Divergência entre estes tipos e o payload real NÃO é pega pelo
 * compilador — TypeScript não conhece o PHP. Por isso o teste de feature
 * verifica a FORMA do payload que o controller entrega (ver spec.md FR-203).
 *
 * Regra desta camada (NFR-201): o React recebe números prontos. Não calcula
 * média, percentual, percentil, nem classifica faixa. Se um tipo aqui expõe
 * um valor cru sem o equivalente já traduzido, é sinal de que a tradução
 * escapou para o cliente — e isso é bug de arquitetura, não de tela.
 */

export type GlucoseRange = 'very_low' | 'low' | 'target' | 'high' | 'very_high';

export type MetricStatus = 'met' | 'above' | 'below' | 'unreliable';

/** Cobertura — Artigo V. Sempre presente junto de qualquer métrica. */
export interface Coverage {
    reading_count: number;
    expected_count: number;
    span_in_days: number;
    percentage: number;
}

export interface PageProps {
    [key: string]: unknown;
}
