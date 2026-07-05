<?php
/**
 * Anthropic-Claude-Client fuer die KI-Vorbewertung der Businesspläne.
 *
 * Claude liest die eingereichte PDF nativ (Document-Block) und liefert per
 * erzwungenem Tool-Call strukturierte Bewertungen (0-10 + Begruendung) zu den
 * fuenf Businessplan-Kriterien.
 */

declare(strict_types=1);

final class Claude
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const VERSION = '2023-06-01';

    /** Standard-Definition des Mindeststandards (in Admin -> KI-Integration änderbar). */
    public const DEFAULT_MIN_STANDARD =
        "Der Mindeststandard ist NICHT erfüllt, wenn z. B.:\n" .
        "- der Plan überwiegend leer ist oder nur die Vorlage/Platzhalter enthält,\n" .
        "- mehrere der fünf Kernbereiche gar nicht bearbeitet wurden,\n" .
        "- der Inhalt unverständlich, off-topic oder erkennbar ohne Mühe erstellt ist,\n" .
        "- der Plan extrem kurz/oberflächlich ist (bloße Stichworte ohne Ausarbeitung).\n" .
        "Erfüllt ist er, wenn erkennbar ernsthaft gearbeitet wurde – auch mit Schwächen.";

    /**
     * Businessplan bewerten.
     * @return array{ok:bool, model:string, scores:array, summary:?string,
     *               strengths:?string, weaknesses:?string, total:?float,
     *               raw:?string, error:?string}
     */
    public static function evaluateBusinessPlan(string $pdfPath): array
    {
        // Zuerst App-Einstellungen (Admin-Menü), dann Deploy-Secret/Config.
        $key = Settings::get('anthropic_api_key', cfg('anthropic_api_key'));
        if (!$key) {
            return self::fail('Kein Anthropic-API-Key hinterlegt (Admin → Einstellungen → KI-Integration).');
        }
        if (!is_file($pdfPath)) {
            return self::fail('Businessplan-Datei nicht gefunden.');
        }

        $model = Settings::get('anthropic_model', cfg('anthropic_model', 'claude-sonnet-5'));
        $pdfB64 = base64_encode((string) file_get_contents($pdfPath));

        // Kriterien-Beschreibung fuer den Prompt aufbereiten
        $rubric = '';
        foreach (Criteria::BUSINESSPLAN as $k => $c) {
            $rubric .= "\n### {$c['title']} (Schluessel: {$k})\n- " . implode("\n- ", $c['points']) . "\n";
        }
        $scaleText = '';
        foreach (Criteria::SCALE as $p => $desc) {
            $scaleText .= "{$p} = {$desc}\n";
        }

        // In der App editierbare Leitlinien (Admin -> KI-Integration).
        $minStd = (string) Settings::get('ai_min_standard', self::DEFAULT_MIN_STANDARD);
        $extra  = trim((string) Settings::get('ai_extra_guidance', ''));
        $extraBlock = $extra !== '' ? "\nZusätzliche Hinweise der Projektleitung:\n{$extra}\n" : '';

        $prompt = <<<TXT
Du bist erfahrenes Jurymitglied des Schüler-Businessplanwettbewerbs "Unternehmen Plus"
der Wirtschaftsjunioren Forchheim (Teilnehmende: Gymnasiast:innen der 10. Klasse).

Bewerte den beigefügten Businessplan (PDF) fair, wohlwollend aber ehrlich anhand
der fünf Kriterien. Vergib je Kriterium 0-10 Punkte nach dieser Skala:
{$scaleText}
Berücksichtige das Altersniveau (Schüler:innen, kein Profi-Startup). KI-Nutzung war
erlaubt. Bewertungskriterien:
{$rubric}

MINDESTSTANDARD-GATE:
Beurteile zusätzlich, ob der Plan den Mindeststandard erfüllt, den man von einem
Schülerteam mit ernsthaftem Bemühen erwarten kann. Maßstab:
{$minStd}
Setze meets_minimum_standard = true, wenn erkennbar ernsthaft gearbeitet wurde (auch
mit Schwächen). Setze false nur bei klarem Nicht-Bemühen und begründe das konkret,
damit ein solcher Plan ohne weitere Sichtung aussortiert werden kann.
{$extraBlock}
Gib pro Kriterium eine kurze, konkrete Begründung (2-4 Sätze, deutsch) und nenne
Stärken sowie Verbesserungspotenzial. Nutze ausschließlich das Tool "submit_evaluation".
TXT;

        $tool = [
            'name'         => 'submit_evaluation',
            'description'  => 'Strukturierte Bewertung des Businessplans abgeben.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => array_merge(
                    self::criteriaSchema(),
                    [
                        'meets_minimum_standard'  => ['type' => 'boolean', 'description' => 'Erfüllt der Plan den Mindeststandard eines ernsthaft bemühten Schülerteams?'],
                        'minimum_standard_reason' => ['type' => 'string', 'description' => 'Kurze Begründung zum Mindeststandard-Urteil.'],
                        'summary'    => ['type' => 'string', 'description' => 'Gesamteinschätzung (3-5 Sätze).'],
                        'strengths'  => ['type' => 'string', 'description' => 'Wichtigste Stärken (Stichpunkte).'],
                        'weaknesses' => ['type' => 'string', 'description' => 'Wichtigstes Verbesserungspotenzial (Stichpunkte).'],
                    ]
                ),
                'required'   => array_merge(array_keys(Criteria::BUSINESSPLAN), ['meets_minimum_standard', 'minimum_standard_reason', 'summary']),
            ],
        ];

        $payload = [
            'model'       => $model,
            'max_tokens'  => 2000,
            'tools'       => [$tool],
            'tool_choice' => ['type' => 'tool', 'name' => 'submit_evaluation'],
            'messages'    => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'document', 'source' => [
                        'type' => 'base64', 'media_type' => 'application/pdf', 'data' => $pdfB64,
                    ]],
                    ['type' => 'text', 'text' => $prompt],
                ],
            ]],
        ];

        [$httpCode, $body, $curlErr] = self::post($key, $payload);
        if ($curlErr) {
            return self::fail('Verbindungsfehler: ' . $curlErr, $model);
        }
        if ($httpCode !== 200) {
            return self::fail('API-Fehler (HTTP ' . $httpCode . '): ' . substr($body, 0, 500), $model);
        }

        $data = json_decode($body, true);
        $toolInput = null;
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'submit_evaluation') {
                $toolInput = $block['input'] ?? null;
                break;
            }
        }
        if (!is_array($toolInput)) {
            return self::fail('Unerwartete API-Antwort (kein tool_use).', $model);
        }

        $scores = [];
        $total = 0.0;
        foreach (Criteria::BUSINESSPLAN as $k => $_) {
            $score = isset($toolInput[$k]['score']) ? (float) $toolInput[$k]['score'] : 0.0;
            $score = max(0.0, min(10.0, $score));
            $scores[$k] = ['score' => $score, 'rationale' => (string) ($toolInput[$k]['rationale'] ?? '')];
            $total += $score;
        }

        return [
            'ok'            => true,
            'model'         => $model,
            'scores'        => $scores,
            'meets_minimum' => isset($toolInput['meets_minimum_standard']) ? (int) (bool) $toolInput['meets_minimum_standard'] : null,
            'min_reason'    => $toolInput['minimum_standard_reason'] ?? null,
            'summary'       => $toolInput['summary'] ?? null,
            'strengths'     => $toolInput['strengths'] ?? null,
            'weaknesses'    => $toolInput['weaknesses'] ?? null,
            'total'         => $total,
            'raw'           => $body,
            'error'         => null,
        ];
    }

    /** JSON-Schema-Eigenschaften je Businessplan-Kriterium. */
    private static function criteriaSchema(): array
    {
        $props = [];
        foreach (Criteria::BUSINESSPLAN as $k => $c) {
            $props[$k] = [
                'type'        => 'object',
                'description' => $c['title'],
                'properties'  => [
                    'score'     => ['type' => 'number', 'minimum' => 0, 'maximum' => 10],
                    'rationale' => ['type' => 'string'],
                ],
                'required'    => ['score', 'rationale'],
            ];
        }
        return $props;
    }

    private static function post(string $key, array $payload): array
    {
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'x-api-key: ' . $key,
                'anthropic-version: ' . self::VERSION,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 120,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return [$code, (string) $body, $err];
    }

    private static function fail(string $msg, string $model = ''): array
    {
        return [
            'ok' => false, 'model' => $model, 'scores' => [], 'meets_minimum' => null,
            'min_reason' => null, 'summary' => null, 'strengths' => null,
            'weaknesses' => null, 'total' => null, 'raw' => null, 'error' => $msg,
        ];
    }
}
