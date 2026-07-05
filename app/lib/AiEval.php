<?php
/**
 * KI-Vorbewertung: Claude aufrufen und Ergebnis persistieren.
 */

declare(strict_types=1);

final class AiEval
{
    /** Aktuellen Businessplan eines Teams per KI bewerten und speichern. */
    public static function run(int $businessPlanId): array
    {
        $bp = Database::one('SELECT * FROM business_plans WHERE id = ?', [$businessPlanId]);
        if (!$bp) {
            return ['ok' => false, 'error' => 'Businessplan nicht gefunden.'];
        }
        $path = UPLOAD_PATH . '/plans/' . basename((string) $bp['stored_name']);

        // Kopf anlegen (status running)
        $evalId = Database::insert(
            'INSERT INTO ai_evaluations (business_plan_id, model, status) VALUES (?, ?, ?)',
            [$businessPlanId, (string) cfg('anthropic_model'), 'running']
        );

        $res = Claude::evaluateBusinessPlan($path);

        if (!$res['ok']) {
            Database::run('UPDATE ai_evaluations SET status = ?, error_message = ? WHERE id = ?',
                ['error', $res['error'], $evalId]);
            return ['ok' => false, 'error' => $res['error']];
        }

        Database::run(
            'UPDATE ai_evaluations SET status=?, model=?, total_score=?, summary=?, strengths=?, weaknesses=?, raw_json=? WHERE id=?',
            ['done', $res['model'], $res['total'], $res['summary'], $res['strengths'], $res['weaknesses'], $res['raw'], $evalId]
        );
        foreach ($res['scores'] as $key => $s) {
            Database::run(
                'INSERT INTO ai_evaluation_scores (ai_evaluation_id, criterion_key, score, rationale) VALUES (?,?,?,?)',
                [$evalId, $key, $s['score'], $s['rationale']]
            );
        }
        return ['ok' => true, 'eval_id' => $evalId, 'total' => $res['total']];
    }

    /** Struktur-/Mindeststandard-Check (günstiges Modell) durchführen und speichern. */
    public static function runStructureCheck(int $businessPlanId): array
    {
        $bp = Database::one('SELECT * FROM business_plans WHERE id = ?', [$businessPlanId]);
        if (!$bp) {
            return ['ok' => false, 'error' => 'Businessplan nicht gefunden.'];
        }
        $path = UPLOAD_PATH . '/plans/' . basename((string) $bp['stored_name']);

        $id = Database::insert(
            'INSERT INTO structure_checks (business_plan_id, status) VALUES (?, ?)',
            [$businessPlanId, 'running']
        );
        $res = Claude::structureCheck($path);
        if (!$res['ok']) {
            Database::run('UPDATE structure_checks SET status=?, error_message=? WHERE id=?', ['error', $res['error'], $id]);
            return ['ok' => false, 'error' => $res['error']];
        }
        Database::run(
            'UPDATE structure_checks SET status=?, model=?, meets_minimum=?, reason=?, sections_json=? WHERE id=?',
            ['done', $res['model'], $res['meets_minimum'], $res['reason'], json_encode($res['sections'], JSON_UNESCAPED_UNICODE), $id]
        );
        return ['ok' => true, 'meets_minimum' => $res['meets_minimum']];
    }

    /** Neuesten Struktur-Check zu einem Businessplan laden. */
    public static function latestStructure(int $businessPlanId): ?array
    {
        $r = Database::one('SELECT * FROM structure_checks WHERE business_plan_id=? ORDER BY id DESC LIMIT 1', [$businessPlanId]);
        if (!$r) {
            return null;
        }
        $r['sections'] = $r['sections_json'] ? (json_decode($r['sections_json'], true) ?: []) : [];
        return $r;
    }

    /** Neueste KI-Bewertung (mit Einzelscores) zu einem Businessplan. */
    public static function latest(int $businessPlanId): ?array
    {
        $eval = Database::one(
            'SELECT * FROM ai_evaluations WHERE business_plan_id = ? ORDER BY id DESC LIMIT 1',
            [$businessPlanId]
        );
        if (!$eval) {
            return null;
        }
        $eval['scores'] = Database::all(
            'SELECT criterion_key, score, rationale FROM ai_evaluation_scores WHERE ai_evaluation_id = ?',
            [(int) $eval['id']]
        );
        return $eval;
    }
}
