<?php

declare(strict_types=1);

namespace App\Domain\Import\Value;

use DateTimeImmutable;

/**
 * Metadados do cabeçalho de um export do CareLink (linhas 1–3).
 *
 * ⚠️ Note que os campos de identificação do paciente ficam num array
 * SEPARADO (`$patient`), não como propriedades da classe. Isso é
 * deliberado: o Artigo VII da constituição exige que nenhum desses
 * campos chegue a um provedor de IA.
 *
 * Mantê-los agrupados e nomeados faz com que qualquer código que serialize
 * este objeto para envio externo tenha que mencionar `patient`
 * explicitamente — em vez de arrastá-los sem perceber junto com o modelo
 * do dispositivo.
 *
 * Ver research.md §Cabeçalho e §PII no arquivo.
 */
final readonly class FileHeader
{
    /**
     * @param  array<string, string>  $patient  PII — nunca sai para IA (Artigo VII)
     * @param  array<string, string|null>  $unknownKeys  chaves não reconhecidas → parse_warnings
     */
    public function __construct(
        public ?string $deviceModel = null,
        public ?string $deviceSerial = null,
        public ?string $hardwareVersion = null,
        public ?string $firmwareVersion = null,
        public ?string $softwareVersion = null,
        public ?string $cgmModel = null,
        public ?DateTimeImmutable $periodStart = null,
        public ?DateTimeImmutable $periodEnd = null,
        public array $patient = [],
        public array $unknownKeys = [],
    ) {}

    /**
     * Atributos seguros para persistir em `imports`, sem os campos de paciente.
     *
     * `device_serial` está aqui de propósito: é gravado no banco (serve para
     * distinguir dispositivos do mesmo usuário), mas é PII e nunca deve ser
     * enviado à IA. Quem controla essa fronteira é o PayloadSanitizer, não
     * esta classe.
     */
    public function toImportAttributes(): array
    {
        return [
            'device_model' => $this->deviceModel,
            'device_serial' => $this->deviceSerial,
            'firmware_version' => $this->firmwareVersion,
            'cgm_model' => $this->cgmModel,
            'period_start' => $this->periodStart?->format('Y-m-d'),
            'period_end' => $this->periodEnd?->format('Y-m-d'),
        ];
    }

    public function hasUnknownKeys(): bool
    {
        return $this->unknownKeys !== [];
    }
}
