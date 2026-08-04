import type { GlucoseRange } from '@/types';

/**
 * Aparência por FAIXA — nunca por valor.
 *
 * ⚠️ Este mapa recebe uma faixa já classificada pelo servidor. Se algum
 * componente precisar de `if (glucose > 180)`, é sinal de que a classificação
 * escapou para o cliente (NFR-201) — os limiares clínicos existem num único
 * lugar: `config/clinical.php`.
 *
 * `label` existe porque cor não pode ser o único sinal (NFR-203). Daltonismo
 * para vermelho/verde é comum, e vermelho/verde é exatamente a codificação
 * natural para glicemia.
 */
export const rangePalette: Record<GlucoseRange, { fill: string; label: string }> = {
    very_low: { fill: '#b91c1c', label: 'muito baixa' },
    low: { fill: '#f87171', label: 'baixa' },
    target: { fill: '#10b981', label: 'na faixa' },
    high: { fill: '#fbbf24', label: 'alta' },
    very_high: { fill: '#ea580c', label: 'muito alta' },
};

export const emptyCell = { fill: '#cbd5e1', label: 'sem leitura' };
