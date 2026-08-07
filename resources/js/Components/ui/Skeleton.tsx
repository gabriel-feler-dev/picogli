/**
 * Esqueleto de carregamento — Spec 008 §D3, §D8.
 *
 * ⚠️ **Ocupa a forma final, não uma barra genérica.** Um esqueleto do tamanho
 * errado faz a tela pular quando o conteúdo chega — que é exatamente o susto
 * que o movimento deveria evitar.
 *
 * ⚠️ **É a única exceção ao "nada em laço"** (§D3). A pulsação é permitida
 * porque representa espera que está de fato acontecendo. Um laço decorativo,
 * que continua rodando depois que o conteúdo chegou, não é.
 */
export function Skeleton({ className }: { className?: string }) {
    return (
        <div
            aria-hidden="true"
            className={`animate-pulse rounded-md bg-slate-200 motion-reduce:animate-none dark:bg-slate-800 ${className ?? ''}`}
        />
    );
}

/** Bloco de linhas de texto, para lista que ainda vai chegar. */
export function SkeletonLines({ lines = 3 }: { lines?: number }) {
    return (
        <div className="space-y-2" role="status" aria-label="Carregando">
            {Array.from({ length: lines }).map((_, i) => (
                <Skeleton key={i} className={i === lines - 1 ? 'h-4 w-2/3' : 'h-4 w-full'} />
            ))}
        </div>
    );
}
