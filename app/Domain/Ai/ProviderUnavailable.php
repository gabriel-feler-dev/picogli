<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use RuntimeException;

/**
 * O provedor não atendeu — e a razão vem classificada.
 *
 * ⚠️ Exceção, e não valor de retorno, de propósito: o caminho normal de
 * `Provider::generate()` é devolver texto. Uma falha de rede não é um resultado
 * alternativo, é a ausência de resultado — e forçar todo chamador a inspecionar
 * um `AiResult` possivelmente vazio espalharia essa checagem por todo lado.
 *
 * ⚠️ **Mas ela para na `ModelChain`.** Nada acima dela vê exceção de provedor: o
 * `NarrativeGenerator` recebe `null` e cai para o fallback (Artigo I). Exceção de
 * IA não pode chegar à tela.
 */
final class ProviderUnavailable extends RuntimeException
{
    public function __construct(
        public readonly ProviderFailure $failure,
        public readonly string $model,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $failure->value);
    }
}
