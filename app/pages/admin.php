<?php
/** Admin-Bereich: zentrale Einstellungen (nur Projektleitung/Admin). */
declare(strict_types=1);

Auth::require('admin');

if (is_post()) {
    Csrf::check();
    $section = (string) input('section');

    if ($section === 'ai') {
        $key = trim((string) input('anthropic_api_key'));
        if ($key !== '') { Settings::set('anthropic_api_key', $key); }
        if (input('clear_key')) { Settings::set('anthropic_api_key', ''); }
        Settings::set('anthropic_model', trim((string) input('anthropic_model')) ?: 'claude-sonnet-5');
        Settings::set('ai_gate_model', trim((string) input('ai_gate_model')) ?: 'claude-haiku-4-5-20251001');
        Settings::set('ai_min_standard', trim((string) input('ai_min_standard')) ?: Claude::DEFAULT_MIN_STANDARD);
        Settings::set('ai_extra_guidance', trim((string) input('ai_extra_guidance')));
        flash('success', 'KI-Einstellungen gespeichert.');
    } elseif ($section === 'general') {
        Settings::set('pitch_slots', (string) max(1, (int) input('pitch_slots', 7)));
        Settings::set('fallback_slots', (string) max(0, (int) input('fallback_slots', 2)));
        Settings::set('current_phase', (string) input('current_phase', 'evaluation'));
        flash('success', 'Allgemeine Einstellungen gespeichert.');
    } elseif ($section === 'security') {
        Settings::set('require_2fa', input('require_2fa') ? '1' : '0');
        flash('success', 'Sicherheitseinstellungen gespeichert.');
    }
    redirect(url('admin'));
}

// aktueller Zustand
$storedKey = (string) Settings::get('anthropic_api_key', '');
$envKey    = (string) cfg('anthropic_api_key', '');
$keyMasked = $storedKey !== '' ? ('••••••••' . substr($storedKey, -4)) : '';
$model     = (string) Settings::get('anthropic_model', cfg('anthropic_model', 'claude-sonnet-5'));
$models    = ['claude-sonnet-5' => 'Sonnet 5 (empfohlen, gute Balance)',
             'claude-opus-4-8' => 'Opus 4.8 (stärkste Bewertung)',
             'claude-haiku-4-5-20251001' => 'Haiku 4.5 (schnell/günstig)'];
$phase     = (string) Settings::get('current_phase', 'evaluation');
$phases    = ['preparation' => 'Vorbereitung', 'evaluation' => 'Bewertung', 'pitch' => 'Pitch-Day', 'closed' => 'Abgeschlossen'];

ob_start(); ?>
<div class="page-head"><h1>Admin – Einstellungen</h1></div>

<div class="grid cols-2">
  <!-- KI-Integration -->
  <div class="card">
    <div class="card__head">KI-Integration (Anthropic Claude)</div>
    <div class="card__body">
      <p class="muted" style="font-size:14px">
        Status:
        <?php if ($storedKey !== ''): ?><span class="pill teal">Key gespeichert (<?= e($keyMasked) ?>)</span>
        <?php elseif ($envKey !== ''): ?><span class="pill blue">Key aus Deploy-Secret aktiv</span>
        <?php else: ?><span class="pill red">kein Key hinterlegt</span><?php endif; ?>
      </p>
      <form method="post" action="<?= url('admin') ?>">
        <?= Csrf::field() ?><input type="hidden" name="section" value="ai">
        <div class="field">
          <label>Anthropic API-Key</label>
          <input type="password" name="anthropic_api_key" autocomplete="off"
                 placeholder="<?= $storedKey !== '' ? 'gespeichert – zum Ersetzen neuen Key eingeben' : 'sk-ant-…' ?>">
          <div class="help">Wird verschlüsselt übertragen und in der Datenbank gespeichert. Überschreibt das Deploy-Secret.</div>
        </div>
        <div class="field">
          <label>Modell – KI-Vorbewertung (inhaltliche Note)</label>
          <select name="anthropic_model">
            <?php foreach ($models as $mk => $ml): ?>
              <option value="<?= e($mk) ?>" <?= $model === $mk ? 'selected' : '' ?>><?= e($ml) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Modell – Struktur-Check (Mindeststandard)</label>
          <select name="ai_gate_model">
            <?php $gm = (string) Settings::get('ai_gate_model', 'claude-haiku-4-5-20251001'); foreach ($models as $mk => $ml): ?>
              <option value="<?= e($mk) ?>" <?= $gm === $mk ? 'selected' : '' ?>><?= e($ml) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="help">Günstiges Modell (Haiku) genügt – reiner Vollständigkeits-/Struktur-Check gegen die Vorlage.</div>
        </div>
        <div class="field">
          <label>Mindeststandard-Gate (Definition für die KI)</label>
          <textarea name="ai_min_standard" rows="6"><?= e((string) Settings::get('ai_min_standard', Claude::DEFAULT_MIN_STANDARD)) ?></textarea>
          <div class="help">Woran erkennt die KI, dass ein Plan den Mindeststandard <em>nicht</em> erfüllt (→ kann ohne weitere Sichtung aussortiert werden)?</div>
        </div>
        <div class="field">
          <label>Zusätzliche Bewertungshinweise (optional)</label>
          <textarea name="ai_extra_guidance" rows="3" placeholder="z. B. besonderer Fokus, Tonalität, Gewichtung …"><?= e((string) Settings::get('ai_extra_guidance', '')) ?></textarea>
        </div>
        <?php if ($storedKey !== ''): ?>
          <label style="font-weight:400;font-size:13px"><input type="checkbox" name="clear_key" value="1"> gespeicherten Key entfernen</label>
        <?php endif; ?>
        <div class="mt"><button class="btn btn--primary">Speichern</button></div>
      </form>
    </div>
  </div>

  <!-- Allgemein -->
  <div class="card">
    <div class="card__head">Wettbewerb</div>
    <div class="card__body">
      <form method="post" action="<?= url('admin') ?>">
        <?= Csrf::field() ?><input type="hidden" name="section" value="general">
        <div class="field"><label>Aktuelle Phase</label>
          <select name="current_phase">
            <?php foreach ($phases as $pk => $pl): ?>
              <option value="<?= e($pk) ?>" <?= $phase === $pk ? 'selected' : '' ?>><?= e($pl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="grid cols-2">
          <div class="field"><label>Pitch-Plätze</label><input type="number" name="pitch_slots" min="1" value="<?= (int) Settings::getInt('pitch_slots', 7) ?>"></div>
          <div class="field"><label>Nachrücker</label><input type="number" name="fallback_slots" min="0" value="<?= (int) Settings::getInt('fallback_slots', 2) ?>"></div>
        </div>
        <button class="btn btn--primary">Speichern</button>
      </form>
    </div>
  </div>

  <!-- Sicherheit / 2FA -->
  <div class="card">
    <div class="card__head">Sicherheit &amp; 2-Faktor-Authentifizierung</div>
    <div class="card__body">
      <form method="post" action="<?= url('admin') ?>">
        <?= Csrf::field() ?><input type="hidden" name="section" value="security">
        <div class="field">
          <label><input type="checkbox" name="require_2fa" value="1" <?= Settings::getBool('require_2fa') ? 'checked' : '' ?>>
            2FA für alle Nutzer empfehlen/erzwingen</label>
          <div class="help">Einstellung wird gespeichert. Die TOTP-Einrichtung je Nutzer (Authenticator-App)
            folgt als nächster Schritt.</div>
        </div>
        <button class="btn btn--primary">Speichern</button>
      </form>
    </div>
  </div>

  <!-- Systeminfo -->
  <div class="card">
    <div class="card__head">System</div>
    <div class="card__body">
      <p><strong>Version:</strong> <a href="<?= url('changelog') ?>"><?= e(APP_VERSION) ?></a></p>
      <p><strong>PHP:</strong> <?= e(PHP_VERSION) ?></p>
      <p><strong>Umgebung:</strong> <?= e((string) cfg('app_env')) ?></p>
      <p><strong>Angemeldet als:</strong> <?= e((string) (Auth::user()['email'] ?? '')) ?></p>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
$title = 'Admin';
require APP_PATH . '/pages/_layout.php';
