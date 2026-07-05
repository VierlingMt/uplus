<?php
/** Eigenes Profil: Foto, E-Mail und Handynummer selbst ändern – immer mit Bestätigung. */
declare(strict_types=1);

$readonly = Auth::isImpersonating(); // „Ansehen als" ist eine Nur-Lese-Ansicht

if (is_post() && !$readonly) {
    Csrf::check();
    $action = (string) input('action');
    $uid = (int) Auth::id();
    $me  = Auth::user();

    if ($action === 'save_photo') {
        $photo = save_image('photo', 'usr', 'avatars');
        if ($photo) {
            Database::run('UPDATE users SET photo_path=? WHERE id=?', [$photo, $uid]);
            flash('success', 'Foto gespeichert.');
        }
        redirect(url('profile'));
    }

    // --- E-Mail ändern: Bestätigungslink an die NEUE Adresse -----------------
    if ($action === 'change_email') {
        $new = strtolower(trim((string) input('new_email')));
        if (!filter_var($new, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Bitte eine gültige E-Mail-Adresse angeben.');
        } elseif ($new === strtolower((string) $me['email'])) {
            flash('info', 'Das ist bereits deine aktuelle E-Mail-Adresse.');
        } elseif (Database::value('SELECT id FROM users WHERE email = ? AND id <> ?', [$new, $uid])) {
            flash('error', 'Diese E-Mail-Adresse wird bereits verwendet.');
        } else {
            $raw  = ContactChange::issueEmail($uid, $new);
            $link = abs_url('confirm_email', ['token' => $raw]);
            $ttl  = ContactChange::emailTtlMinutes();

            // Bestätigung an die NEUE Adresse
            $html = Mailer::brandedHtml(
                'Neue E-Mail bestätigen',
                'Hallo ' . e((string) $me['name']) . ',<br><br>'
                . 'du möchtest deine E-Mail-Adresse bei <strong>Unternehmen Plus</strong> auf '
                . '<strong>' . e($new) . '</strong> ändern. Bestätige das mit einem Klick:',
                'Neue Adresse bestätigen',
                $link,
                'Der Link ist ' . $ttl . ' Minuten gültig. Erst nach dem Klick wird die Adresse übernommen. '
                . 'Falls du das nicht angefordert hast, ignoriere diese E-Mail einfach.'
            );
            Mailer::send($new, 'Bestätige deine neue E-Mail-Adresse', "Bestätigungslink: " . $link . "\n(gültig " . $ttl . " Min.)", $html);

            // Sicherheits-Hinweis an die bisherige Adresse
            Mailer::send(
                (string) $me['email'],
                'Änderung deiner E-Mail-Adresse angefordert',
                "Hallo " . $me['name'] . ",\n\nes wurde angefragt, deine E-Mail-Adresse auf " . $new
                . " zu ändern. Die Änderung greift erst, wenn der Bestätigungslink an der neuen Adresse "
                . "angeklickt wird. Warst du das nicht, wende dich an die Projektleitung.\n"
            );

            flash('success', 'Wir haben einen Bestätigungslink an die neue Adresse (' . e($new) . ') geschickt. Erst nach dem Klick wird sie übernommen.');
        }
        redirect(url('profile'));
    }

    // --- Handynummer ändern: SMS-Code an die NEUE Nummer ---------------------
    if ($action === 'change_phone') {
        $norm = phone_normalize((string) input('new_phone'));
        if ($norm === null) {
            flash('error', 'Bitte eine gültige Handynummer angeben (z. B. 0170 …).');
        } elseif ($norm === (string) ($me['phone'] ?? '')) {
            flash('info', 'Das ist bereits deine hinterlegte Handynummer.');
        } elseif (Database::value('SELECT id FROM users WHERE phone = ? AND id <> ?', [$norm, $uid])) {
            flash('error', 'Diese Handynummer wird bereits verwendet.');
        } elseif (!Sms::isConfigured()) {
            flash('error', 'Nummernänderung per SMS ist derzeit nicht verfügbar. Bitte wende dich an die Projektleitung.');
        } else {
            $code = ContactChange::issuePhone($uid, $norm);
            $text = 'Unternehmen Plus: Bestätigungscode für deine neue Handynummer: ' . $code
                  . ' (' . ContactChange::phoneTtlMinutes() . ' Min. gültig).';
            if (Sms::send($norm, $text)) {
                flash('success', 'Wir haben einen Code an die neue Nummer geschickt. Gib ihn unten ein, um die Änderung zu bestätigen.');
            } else {
                flash('error', 'Der Code konnte nicht per SMS gesendet werden. Bitte prüfe die Nummer.');
            }
        }
        redirect(url('profile'));
    }

    // --- Handynummer: Code prüfen und übernehmen -----------------------------
    if ($action === 'verify_phone') {
        $newPhone = ContactChange::verifyPhone($uid, (string) input('code'));
        if ($newPhone !== null) {
            flash('success', 'Handynummer geändert: ' . e($newPhone) . '.');
        } else {
            flash('error', 'Der Code ist ungültig oder abgelaufen. Bitte fordere einen neuen an.');
        }
        redirect(url('profile'));
    }
}

$u = Auth::user();
$roleLabel = ['admin' => 'Admin', 'lead' => 'Projektleitung', 'teacher' => 'Lehrkraft', 'juror' => 'Jury'][$u['role']] ?? $u['role'];
$pendingPhone = $readonly ? null : ContactChange::pendingPhone((int) Auth::id());
$smsOk = Sms::isConfigured();

ob_start(); ?>
<div class="page-head"><h1>Mein Profil</h1></div>
<div class="grid cols-2">
  <div class="card"><div class="card__body">
    <div style="display:flex;align-items:center;gap:16px;margin-bottom:8px">
      <?php if (!empty($u['photo_path'])): ?>
        <img class="avatar avatar--lg" src="<?= asset($u['photo_path']) ?>" alt="">
      <?php else: ?>
        <span class="avatar avatar--lg avatar--ph" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $u['name'], 0, 1))) ?></span>
      <?php endif; ?>
      <div>
        <strong style="font-size:18px"><?= e($u['name']) ?></strong><br>
        <span class="pill blue"><?= e($roleLabel) ?></span>
      </div>
    </div>
    <?php if (!$readonly): ?>
      <form method="post" action="<?= url('profile') ?>" enctype="multipart/form-data" style="margin-top:8px">
        <?= Csrf::field() ?><input type="hidden" name="action" value="save_photo">
        <?= image_field('photo', $u['photo_path'] ?? null, [
            'label' => 'Porträtfoto', 'aspect' => 1, 'shape' => 'round', 'format' => 'jpeg',
            'hint' => 'Foto hierher ziehen oder klicken – quadratisch zuschneiden, zoomen, drehen.',
        ]) ?>
        <button class="btn btn--primary">Foto speichern</button>
      </form>
    <?php endif; ?>
  </div></div>

  <div class="card"><div class="card__body">
    <h3 style="margin-top:0">Anmeldedaten</h3>
    <p class="muted" style="font-size:13px">Die Anmeldung ist passwortlos – per Login-Link an deine
      E-Mail oder per SMS-Code an deine Handynummer. Änderungen werden immer erst nach Bestätigung übernommen.</p>

    <div class="field" style="margin-top:14px"><label>E-Mail-Adresse</label><div><?= e($u['email']) ?></div></div>
    <?php if (!$readonly): ?>
      <form method="post" action="<?= url('profile') ?>" style="margin-bottom:20px">
        <?= Csrf::field() ?><input type="hidden" name="action" value="change_email">
        <label for="new_email">Neue E-Mail-Adresse</label>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
          <input type="email" id="new_email" name="new_email" placeholder="name@schule.de" style="flex:1;min-width:180px" required>
          <button class="btn btn--teal">Ändern</button>
        </div>
        <div class="help">Ein Bestätigungslink geht an die neue Adresse; erst nach dem Klick greift die Änderung.</div>
      </form>
    <?php endif; ?>

    <div class="field"><label>Handynummer</label><div><?= $u['phone'] ? e($u['phone']) : '<span class="muted">— keine hinterlegt —</span>' ?></div></div>
    <?php if (!$readonly): ?>
      <?php if ($pendingPhone !== null): ?>
        <div class="flash info" style="margin-bottom:10px">Wir haben einen Code an <strong><?= e($pendingPhone) ?></strong> geschickt. Bitte hier bestätigen:</div>
        <form method="post" action="<?= url('profile') ?>">
          <?= Csrf::field() ?><input type="hidden" name="action" value="verify_phone">
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <input type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" placeholder="6-stelliger Code" style="flex:1;min-width:160px" required>
            <button class="btn btn--primary">Bestätigen</button>
          </div>
        </form>
        <form method="post" action="<?= url('profile') ?>" style="margin-top:8px">
          <?= Csrf::field() ?><input type="hidden" name="action" value="change_phone">
          <input type="hidden" name="new_phone" value="<?= e($pendingPhone) ?>">
          <button class="btn btn--ghost btn--sm">Neuen Code anfordern</button>
        </form>
      <?php elseif ($smsOk): ?>
        <form method="post" action="<?= url('profile') ?>">
          <?= Csrf::field() ?><input type="hidden" name="action" value="change_phone">
          <label for="new_phone">Neue Handynummer</label>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
            <input type="text" id="new_phone" name="new_phone" placeholder="z. B. 0170 9009124" style="flex:1;min-width:180px" required>
            <button class="btn btn--teal">Ändern</button>
          </div>
          <div class="help">Ein Bestätigungscode geht per SMS an die neue Nummer; wird international gespeichert (+49…).</div>
        </form>
      <?php else: ?>
        <p class="muted" style="font-size:13px">Nummernänderung per SMS ist derzeit nicht verfügbar – wende dich an die Projektleitung.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div></div>
</div>
<?php
$content = ob_get_clean();
$title = 'Mein Profil';
require APP_PATH . '/pages/_layout.php';
