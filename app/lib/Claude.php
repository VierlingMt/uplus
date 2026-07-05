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
                        'summary'    => ['type' => 'string', 'description' => 'Gesamteinschätzung (3-5 Sätze).'],
                        'strengths'  => ['type' => 'string', 'description' => 'Wichtigste Stärken (Stichpunkte).'],
                        'weaknesses' => ['type' => 'string', 'description' => 'Wichtigstes Verbesserungspotenzial (Stichpunkte).'],
                    ]
                ),
                'required'   => array_merge(array_keys(Criteria::BUSINESSPLAN), ['summary']),
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
            'ok'         => true,
            'model'      => $model,
            'scores'     => $scores,
            'summary'    => $toolInput['summary'] ?? null,
            'strengths'  => $toolInput['strengths'] ?? null,
            'weaknesses' => $toolInput['weaknesses'] ?? null,
            'total'      => $total,
            'raw'        => $body,
            'error'      => null,
        ];
    }

    /**
     * Struktur-/Mindeststandard-Check (günstiges Modell): prüft, ob der Plan die
     * Abschnitte der Businessplan-Vorlage jeweils inhaltlich behandelt (nicht nur
     * Stichworte). Dient als Gate, um offensichtlich nicht bearbeitete Pläne ohne
     * weitere Sichtung auszusortieren.
     */
    public static function structureCheck(string $pdfPath): array
    {
        $key = Settings::get('anthropic_api_key', cfg('anthropic_api_key'));
        if (!$key) {
            return ['ok' => false, 'error' => 'Kein Anthropic-API-Key hinterlegt (Admin → KI-Integration).'];
        }
        if (!is_file($pdfPath)) {
            return ['ok' => false, 'error' => 'Businessplan-Datei nicht gefunden.'];
        }

        $model = Settings::get('ai_gate_model', cfg('anthropic_gate_model', 'claude-haiku-4-5-20251001'));
        $def   = (string) Settings::get('ai_min_standard', self::DEFAULT_MIN_STANDARD);
        $sections = Criteria::templateSections();

        $listText = '';
        $props = [];
        $required = [];
        foreach ($sections as $s) {
            $opt = $s['required'] ? '' : ' (optional)';
            $listText .= "\n### {$s['title']}{$opt} (Schlüssel: {$s['key']})\nVorgegebene Leitfragen (zählen NICHT als Inhalt):\n- " . implode("\n- ", $s['aspects']) . "\n";
            $props[$s['key']] = [
                'type' => 'object',
                'description' => $s['title'],
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['behandelt', 'oberflaechlich', 'fehlt'],
                                 'description' => 'behandelt = mehrere zusammenhängende, konkrete Sätze der Schüler:innen; oberflaechlich = nur Stichpunkte/ein, zwei Sätze/Floskeln; fehlt = kein eigener Text (nur Überschrift/Leitfrage/Platzhalter oder gar nichts)'],
                    'own_sentences' => ['type' => 'integer', 'minimum' => 0,
                                 'description' => 'Geschätzte Anzahl EIGENER, inhaltstragender Sätze der Schüler:innen unter dieser Überschrift – Überschrift, Leitfrage und Platzhalter NICHT mitzählen.'],
                    'note'   => ['type' => 'string'],
                ],
                'required' => ['status', 'own_sentences'],
            ];
            $required[] = $s['key'];
        }

        $prompt = <<<TXT
Du bist erfahrenes Jurymitglied und machst eine schnelle Triage von Schüler-
Businessplänen (10. Klasse, PDF). Ziel: erkennen, welche Pläne so wenig
EIGENE Bearbeitung haben, dass man sie beim ersten Durchsehen aussortieren würde.
Es geht NICHT um die inhaltliche Note, sondern um die tatsächliche Ausarbeitungstiefe.

ENTSCHEIDEND – bitte genau lesen:
- Die Überschriften und Leitfragen (z. B. „Vertrieb & Kommunikation – Wie machst du
  auf dich aufmerksam?") sind von den VERANSTALTERN VORGEGEBEN. Sie sind KEIN Inhalt
  und dürfen NIE als Bearbeitung gewertet werden. Bewerte ausschließlich den Text,
  den die Schüler:innen SELBST darunter geschrieben haben.
- Zähle Deckblätter, Bilder, Logos, Inhaltsverzeichnis, Vorlagen-Platzhalter und
  wiederholte Leitfragen NICHT als Inhalt. Ein Plan kann optisch „voll" wirken (Bilder,
  Deckblatt) und trotzdem kaum Eigentext enthalten.
- Miss Substanz, nicht Seitenzahl. Ein Deckblatt mit Bild + je zwei Wörter pro
  Überschrift ist NICHT bearbeitet.

Vergib je Abschnitt EINEN Status – streng:
- "behandelt": echte Ausarbeitung – mehrere zusammenhängende, konkrete Sätze, die den
  Punkt erklären (nicht nur benennen).
- "oberflaechlich": nur angerissen – Stichpunkte, ein, zwei Halbsätze, Floskeln oder
  bloßes Benennen ohne echte Ausführung.
- "fehlt": kein eigener Text – nur Überschrift/Leitfrage/Platzhalter oder gar nichts.

Schätze zusätzlich je Abschnitt "own_sentences" (Anzahl eigener, inhaltstragender
Sätze der Schüler:innen) und am Ende "own_words_total": die grobe Gesamtzahl der
SELBST geschriebenen Wörter im ganzen Plan – OHNE Überschriften, Leitfragen,
Platzhalter, Deckblatt und Bildunterschriften.

Bewertet werden die fünf KERN-Abschnitte (Geschäftsidee, Vertrieb & Wettbewerb,
Team & Partner, Dein Unternehmen, Finanzen). Zusammenfassung und Anhang sind OPTIONAL
und dürfen NICHT gegen den Plan zählen.

Abschnitte:
{$listText}

Gib in "reason" 1-2 Sätze zum Gesamteindruck der EIGEN-Bearbeitungstiefe.
Die endgültige Aussortier-Entscheidung trifft das System anhand von Abschnitts-Tiefe
UND geschätzter Eigentext-Menge. Nutze ausschließlich das Tool "submit_structure_check".
TXT;

        $props['own_words_total'] = ['type' => 'integer', 'minimum' => 0,
            'description' => 'Grobe Gesamtzahl selbst geschriebener Wörter im ganzen Plan (ohne Überschriften/Leitfragen/Platzhalter/Deckblatt/Bildunterschriften).'];
        $props['meets_minimum_standard'] = ['type' => 'boolean'];
        $props['reason'] = ['type' => 'string'];
        $required[] = 'own_words_total';
        $required[] = 'meets_minimum_standard';
        $required[] = 'reason';

        $tool = [
            'name' => 'submit_structure_check',
            'description' => 'Struktur-/Vollständigkeitsprüfung des Businessplans abgeben.',
            'input_schema' => ['type' => 'object', 'properties' => $props, 'required' => $required],
        ];

        $payload = [
            'model' => $model,
            'max_tokens' => 1500,
            'tools' => [$tool],
            'tool_choice' => ['type' => 'tool', 'name' => 'submit_structure_check'],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode((string) file_get_contents($pdfPath))]],
                    ['type' => 'text', 'text' => $prompt],
                ],
            ]],
        ];

        [$code, $body, $err] = self::post($key, $payload);
        if ($err) { return ['ok' => false, 'error' => 'Verbindungsfehler: ' . $err]; }
        if ($code !== 200) { return ['ok' => false, 'error' => 'API-Fehler (HTTP ' . $code . '): ' . substr($body, 0, 400)]; }

        $data = json_decode($body, true);
        $in = null;
        foreach ($data['content'] ?? [] as $b) {
            if (($b['type'] ?? '') === 'tool_use') { $in = $b['input'] ?? null; break; }
        }
        if (!is_array($in)) { return ['ok' => false, 'error' => 'Unerwartete API-Antwort.']; }

        $secOut = [];
        foreach ($sections as $s) {
            $secOut[$s['key']] = [
                'title'  => $s['title'],
                'status' => $in[$s['key']]['status'] ?? 'fehlt',
                'own_sentences' => isset($in[$s['key']]['own_sentences']) ? max(0, (int) $in[$s['key']]['own_sentences']) : null,
                'note'   => $in[$s['key']]['note'] ?? '',
                'required' => $s['required'],
            ];
        }

        return [
            'ok'            => true,
            'model'         => $model,
            'meets_minimum' => isset($in['meets_minimum_standard']) ? (int) (bool) $in['meets_minimum_standard'] : null,
            'own_words'     => isset($in['own_words_total']) ? max(0, (int) $in['own_words_total']) : null,
            'reason'        => $in['reason'] ?? null,
            'sections'      => $secOut,
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
