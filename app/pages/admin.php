<?php
/** Admin-Bereich: zentrale Einstellungen (Admin & Projektleitung). */
declare(strict_types=1);

Auth::requireManager();

if (is_post()) {
    Csrf::check();
    $section = (string) input('section');

    if ($section === 'ai') {
        $key = trim((string) input('anthropic_api_key'));
        if ($key !== '') { Settings::set('anthropic_api_key', $key); }
        if (input('clear_key')) { Settings::set('anthropic_api_key', ''); }
        Settings::set('anthropic_model', trim((string) input('anthropic_model')) ?: 'claude-sonnet-5');
        Settings::set('ai_gate_model', trim((string) input('ai_gate_model')) ?: 'claude-haiku-4-5-20251001');
        Settings::set('ai_min_score', (string) max(0, min(10, (int) input('ai_min_score', 6))));
        Settings::set('ai_min_words', (string) max(0, (int) input('ai_min_words', 200)));
        Settings::set('ai_min_core', (string) max(0, min(5, (int) input('ai_min_core', 2))));
        Settings::set('ai_min_standard', trim((string) input('ai_min_standard')) ?: Claude::DEFAULT_MIN_STANDARD);
        Settings::set('ai_extra_guidance', trim((string) input('ai_extra_guidance')));
        Settings::set('ai_eval_jurors', input('ai_eval_jurors') ? '1' : '0');
        Audit::log('settings.ai', 'KI-Einstellungen geändert');
        flash('success', 'KI-Einstellungen gespeichert.');
    } elseif ($section === 'general') {
        Settings::set('pitch_slots', (string) max(1, (int) input('pitch_slots', 7)));
        Settings::set('fallback_slots', (string) max(0, (int) input('fallback_slots', 2)));
        Settings::set('pitch_fair', input('pitch_fair') ? '1' : '0');
        Settings::set('fallback_per_school', (string) max(0, (int) input('fallback_per_school', 2)));
        Settings::set('current_phase', (string) input('current_phase', 'evaluation'));
        Audit::log('settings.general', 'Allgemeine Einstellungen geändert (Phase: ' . (string) input('current_phase', 'evaluation') . ')');
        flash('success', 'Allgemeine Einstellungen gespeichert.');
    } elseif ($section === 'security') {
        Settings::set('require_2fa', input('require_2fa') ? '1' : '0');
        Audit::log('settings.security', 'Sicherheitseinstellungen geändert (2FA: ' . (input('require_2fa') ? 'an' : 'aus') . ')');
        flash('success', 'Sicherheitseinstellungen gespeichert.');
    } elseif ($section === 'delivery') {
        // E-Mail-Absender (Magic-Link-Login)
        Settings::set('mail_from', trim((string) input('mail_from')));
        Settings::set('mail_from_name', trim((string) input('mail_from_name')) ?: 'Unternehmen Plus');
        // SMS-Login (seven.io)
        $sevenKey = trim((string) input('seven_api_key'));
        if ($sevenKey !== '') { Settings::set('seven_api_key', $sevenKey); }
        if (input('clear_seven_key')) { Settings::set('seven_api_key', ''); }
        Settings::set('sms_from', trim((string) input('sms_from')) ?: 'UPlus');
        Audit::log('settings.delivery', 'Anmeldungs- & Zustellungs-Einstellungen geändert');
        flash('success', 'Anmeldungs- & Zustellungs-Einstellungen gespeichert.');
    } elseif ($section === 'testmail') {
        $to = strtolower(trim((string) input('test_to')));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Bitte eine gültige Ziel-E-Mail-Adresse angeben.');
        } else {
            $when = date('d.m.Y H:i');
            $text = "Dies ist eine Test-Mail von Unternehmen Plus.\n\n"
                  . "Wenn du diese Nachricht liest, funktioniert der E-Mail-Versand.\n\n"
                  . "Gesendet: {$when} Uhr\n";
            $html = Mailer::brandedHtml(
                'Test-Mail',
                'Dies ist eine <strong>Test-Mail</strong> von Unternehmen Plus.<br><br>'
                . 'Wenn du diese Nachricht siehst, funktioniert der Versand – ideal, um die '
                . 'Zustellbarkeit (SPF/DKIM/DMARC) z. B. über mail-tester.com zu prüfen.',
                null,
                null,
                'Gesendet am ' . $when . ' Uhr.'
            );
            $ok = Mailer::send($to, 'Test-Mail – Unternehmen Plus', $text, $html);
            flash($ok ? 'success' : 'error', $ok
                ? 'Test-Mail an ' . $to . ' übergeben. Bitte Postfach (und Spam) prüfen.'
                : 'Test-Mail konnte nicht gesendet werden – siehe Server-Log.');
        }
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

// Zustellung: E-Mail-Absender + SMS (seven.io)
$mailFrom     = (string) Settings::get('mail_from', cfg('mail_from', ''));
$mailFromName = (string) Settings::get('mail_from_name', cfg('mail_from_name', 'Unternehmen Plus'));
$sevenKey     = (string) Settings::get('seven_api_key', '');
$sevenMasked  = $sevenKey !== '' ? ('••••••••' . substr($sevenKey, -4)) : '';
$smsFrom      = (string) Settings::get('sms_from', 'UPlus');

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
          <label>Mindest-Substanz-Score (Schwellwert 0–10)</label>
          <input type="number" name="ai_min_score" min="0" max="10" value="<?= (int) Settings::getInt('ai_min_score', 6) ?>" style="width:90px">
          <div class="help">Summe der Bearbeitungstiefe über die 5 Kernabschnitte (behandelt=2, oberflächlich=1, fehlt=0).
            Pläne <strong>unter</strong> diesem Wert gelten als „unter Mindeststandard". Höher = strenger (mehr Pläne werden aussortiert).</div>
        </div>
        <div class="field">
          <label>Mindest-Eigentext (Wörter)</label>
          <input type="number" name="ai_min_words" min="0" value="<?= (int) Settings::getInt('ai_min_words', 200) ?>" style="width:110px">
          <div class="help">Geschätzte Anzahl <strong>selbst geschriebener</strong> Wörter (ohne Überschriften, Leitfragen,
            Platzhalter, Deckblatt). Liegt ein Plan darunter, gilt er unabhängig vom Score als „unter Mindeststandard" –
            fängt Pläne, die nur aus der Vorlage + Stichpunkten bestehen. 0 = Regel aus.</div>
        </div>
        <div class="field">
          <label>Mindestzahl wirklich ausgearbeiteter Kernabschnitte</label>
          <input type="number" name="ai_min_core" min="0" max="5" value="<?= (int) Settings::getInt('ai_min_core', 2) ?>" style="width:90px">
          <div class="help">So viele der 5 Kernabschnitte müssen den Status „behandelt" (mehrere konkrete Sätze) haben.
            Sonst gilt der Plan als „unter Mindeststandard". 0 = Regel aus.</div>
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
        <div class="field">
          <label style="font-weight:400"><input type="checkbox" name="ai_eval_jurors" value="1" <?= Settings::getInt('ai_eval_jurors', 0) === 1 ? 'checked' : '' ?>> KI-Vorbewertung auch für Juror:innen sichtbar</label>
          <div class="help">Standardmäßig sehen nur Admin und Projektleitung die KI-Vorbewertung (inhaltliche Note /50).
            Aktivieren, damit auch die Jury sie in Businessplan-Detail, Übersicht und Ranking sieht.</div>
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
        <div class="field"><label>Wettbewerbsjahr</label>
          <?php $adminCycle = Cycle::active(); ?>
          <p style="margin:4px 0"><span class="pill teal"><?= e($adminCycle['year_label'] ?? '– keins aktiv –') ?></span>
            <a href="<?= url('cycles') ?>" style="margin-left:8px">verwalten →</a></p>
          <div class="help">Das aktive Wettbewerbsjahr wird zentral unter „Wettbewerbsjahre“ festgelegt und steuert u. a. die Sponsoren-Anzeige im Dashboard.</div>
        </div>
        <div class="field"><label>Aktuelle Phase</label>
          <select name="current_phase">
            <?php foreach ($phases as $pk => $pl): ?>
              <option value="<?= e($pk) ?>" <?= $phase === $pk ? 'selected' : '' ?>><?= e($pl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="grid cols-2">
          <div class="field"><label>Pitch-Plätze (gesamt)</label><input type="number" name="pitch_slots" min="1" value="<?= (int) Settings::getInt('pitch_slots', 7) ?>"></div>
          <div class="field"><label>Nachrücker gesamt (ohne faire Verteilung)</label><input type="number" name="fallback_slots" min="0" value="<?= (int) Settings::getInt('fallback_slots', 2) ?>"></div>
        </div>
        <div class="field" style="margin-top:4px">
          <label style="font-weight:400;display:flex;gap:8px;align-items:center">
            <input type="checkbox" name="pitch_fair" value="1" <?= Settings::getInt('pitch_fair', 1) === 1 ? 'checked' : '' ?>>
            Faire Verteilung je Schule – die Pitch-Plätze werden gleichmäßig auf die Schulen verteilt; überzählige Plätze gehen an die Schule(n) mit den besten Businessplänen (so kommt keine Schule zu kurz).
          </label>
        </div>
        <div class="grid cols-2">
          <div class="field"><label>Nachrücker je Schule (bei fairer Verteilung)</label><input type="number" name="fallback_per_school" min="0" value="<?= (int) Settings::getInt('fallback_per_school', 2) ?>"></div>
        </div>
        <button class="btn btn--primary">Speichern</button>
      </form>
    </div>
  </div>

  <!-- Anmeldung & Zustellung: E-Mail-Absender + SMS (seven.io) -->
  <div class="card">
    <div class="card__head">Anmeldung &amp; Zustellung (E-Mail / SMS)</div>
    <div class="card__body">
      <p class="muted" style="font-size:14px">
        Der Login ist passwortlos: Magic-Link per E-Mail, optional Einmalcode per SMS.
        <?php if ($sevenKey !== ''): ?><span class="pill teal">SMS aktiv (Key <?= e($sevenMasked) ?>)</span>
        <?php else: ?><span class="pill muted">SMS inaktiv (kein seven.io-Key)</span><?php endif; ?>
      </p>
      <form method="post" action="<?= url('admin') ?>">
        <?= Csrf::field() ?><input type="hidden" name="section" value="delivery">

        <div class="field">
          <label>E-Mail-Absender (Login-Mails)</label>
          <input type="email" name="mail_from" value="<?= e($mailFrom) ?>" placeholder="info@uplus.vimatec.de" autocomplete="off">
          <div class="help">Muss ein echtes Postfach der eigenen Domain sein. Für gute Zustellbarkeit
            SPF/DKIM/DMARC dieser Domain setzen (siehe unten).</div>
        </div>
        <div class="field">
          <label>Absender-Name</label>
          <input type="text" name="mail_from_name" value="<?= e($mailFromName) ?>" placeholder="Unternehmen Plus">
        </div>

        <hr style="margin:18px 0;border:none;border-top:1px solid var(--line)">

        <div class="field">
          <label>seven.io API-Key (SMS-Login)</label>
          <input type="password" name="seven_api_key" autocomplete="off"
                 placeholder="<?= $sevenKey !== '' ? 'gespeichert – zum Ersetzen neuen Key eingeben' : 'seven.io API-Key' ?>">
          <div class="help">Wird in der Datenbank gespeichert. Ist ein Key hinterlegt, erscheint auf der
            Login-Seite zusätzlich „Code per SMS". Empfänger ist die am Nutzer hinterlegte Handynummer.</div>
        </div>
        <div class="field">
          <label>SMS-Absender (max. 11 Zeichen)</label>
          <input type="text" name="sms_from" value="<?= e($smsFrom) ?>" maxlength="11" placeholder="UPlus" style="width:180px">
        </div>
        <?php if ($sevenKey !== ''): ?>
          <label style="font-weight:400;font-size:13px"><input type="checkbox" name="clear_seven_key" value="1"> gespeicherten seven.io-Key entfernen</label>
        <?php endif; ?>
        <div class="mt"><button class="btn btn--primary">Speichern</button></div>
      </form>

      <hr style="margin:18px 0;border:none;border-top:1px solid var(--line)">

      <form method="post" action="<?= url('admin') ?>">
        <?= Csrf::field() ?><input type="hidden" name="section" value="testmail">
        <label>Test-Mail senden</label>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <input type="email" name="test_to" value="<?= e((string) (Auth::user()['email'] ?? '')) ?>"
                 placeholder="ziel@adresse.de" style="flex:1;min-width:220px">
          <button class="btn btn--teal">Senden</button>
        </div>
        <div class="help">Verschickt eine gestaltete Test-Mail über den aktuellen Absender – praktisch
          zum Prüfen der Zustellbarkeit (z. B. die Adresse von mail-tester.com eintragen).</div>
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
