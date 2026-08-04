# PicoGli

Leitura e interpretação de dados de diabetes em linguagem que quem não é médico entende.

Quem usa bomba de insulina com sensor contínuo gera ~288 medições de glicose por dia. O CareLink da Medtronic transforma isso em relatórios corretos, completos — e escritos para endocrinologistas. O paciente vê `TIR 83,9%` e não sabe se é bom, nem o que fazer amanhã.

O dado existe. A tradução não. É isso que o PicoGli faz.

## O que ele entrega

**Dashboards traduzidos** — não "TIR 83,9%", mas "sua glicose ficou na faixa boa 20 horas por dia, a meta é 17h".

**Avaliação automática** — detecção de padrões que a média dilui: deriva por horário do dia, clusters de hipoglicemia, eventos de montanha-russa, dias outlier que dominam uma estatística, e correlação entre falha de sensor e queda de insulina automática do loop fechado.

**Chat sobre os próprios dados** — "por que aquele dia foi ruim?", "minhas madrugadas melhoraram?" — respondido com números reais do banco via tool calling, nunca com estimativa do modelo.

## Princípios

| | |
|---|---|
| **Número é código, texto é IA** | Toda métrica é calculada em PHP/SQL. O modelo nunca calcula — só redige. Sem IA, o produto continua funcionando. |
| **Detecção é regra, redação é modelo** | Padrões vêm de regras determinísticas versionadas com evidência anexada, não de "peça ao modelo para achar padrões". |
| **Nenhum número sem procedência** | Toda afirmação rastreia até uma linha do banco. No chat isso é imposto por arquitetura. |
| **Tom não acusatório** | O texto descreve mecanismo e consequência, nunca caráter. É requisito técnico, não cortesia. |
| **Métrica inválida não é exibida como válida** | GMI e CV exigem ≥14 dias e ≥70% de captura. Abaixo disso, marcado ou omitido. |

## Stack

Laravel 12 · Inertia 2 + React 19 + TypeScript · PostgreSQL · Tailwind · Recharts · Google Gemini (família Flash)

## Arquitetura em uma tela

```
CSV do CareLink
      │  upload
      ▼
ImportCsvJob (fila) ── segmenta 3 blocos ── explode em eventos tipados ── upsert idempotente
      ▼
Banco  sensor_readings · insulin_doses · meals · bg_readings · device_events · daily_auto_insulin
      ▼
MetricsCalculator (PHP puro)  TIR/TAR/TBR · média · DP · CV · GMI · cobertura · episódios
      ▼
PatternEngine (regras R1..R10)  →  Finding[] com evidência numérica
      │
      ├──▶ Gemini: narrativa   (Finding → prosa)
      └──▶ Gemini: chat        (tool calling → SQL)
      ▼
Inertia + React   dashboard · avaliação · chat (SSE)
```

A IA entra no fim, duas vezes, e nunca no caminho crítico. Dashboard e métricas funcionam com o provedor fora do ar.

## Documentação

A especificação completa (anatomia do CSV do CareLink com as armadilhas de parsing verificadas, modelo de dados, pipeline de importação, fórmulas das métricas, as 10 regras do motor de padrões, arquitetura da IA, roadmap e ADRs) é mantida **fora do repositório**: o gabarito de testes contém métricas clínicas reais.

## Dados de paciente

Exports do CareLink contêm nome, número de série da bomba, data de nascimento e o histórico completo de glicemia. **Nada disso é versionado** — ver [`.gitignore`](.gitignore).

Para rodar os testes você precisa do seu próprio export, colocado em `storage/carelink/` (ignorado pelo git).

## Limite clínico

PicoGli é uma ferramenta de leitura dos seus próprios dados. **Não substitui avaliação médica e não recomenda mudanças de tratamento.** Ele descreve, quantifica e identifica padrões; quando um padrão aponta para ajuste de dose, basal, razão de carboidrato ou sensibilidade, a resposta é sempre devolver a pergunta ao endocrinologista. Decisões sobre insulina são do médico.

Essa fronteira está implementada em quatro camadas do código — prompt de narrativa, guardrails do chat, construção da regra que detecta desalinhamento de configuração, e classificador de emergência antes da chamada ao modelo — não apenas em disclaimer.

## Status

Em especificação. Fase 1 (fundação de dados) é a próxima.

## Licença

MIT
