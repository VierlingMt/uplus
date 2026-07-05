<?php
/** Eigenes Profil: Name/E-Mail/Rolle anzeigen, Porträtfoto pflegen. Login passwortlos. */
declare(strict_types=1);

$readonly = Auth::isImpersonating(); // „Ansehen als" ist eine Nur-Lese-Ansicht

if (is_post() && !$readonly) {
    Csrf::check();
    if ((string) input('action') === 'save_photo') {
        $photo = save_image('photo', 'usr', 'avatars');
        if ($photo) {
            Database::run('UPDATE users SET photo_path=? WHERE id=?', [$photo, Auth::id()]);
            flash('success', 'Foto gespeichert.');
        }
        redirect(url('profile'));
    }
}

$u = Auth::user();

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
        <span class="pill blue"><?= e(['admin'=>'Admin','lead'=>'Projektleitung','teacher'=>'Lehrkraft','juror'=>'Jury'][$u['role']] ?? $u['role']) ?></span>
      </div>
    </div>
    <label class="mt">E-Mail</label><p><?= e($u['email']) ?></p>
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
    <h3 style="margin-top:0">Anmeldung</h3>
    <p class="muted">Die Anmeldung erfolgt passwortlos: Du gibst auf der Login-Seite
       deine E-Mail-Adresse ein und erhältst einen einmaligen Login-Link.
       Es ist kein Passwort nötig.</p>
    <p class="muted" style="margin-top:10px">Login-Links gehen an
       <strong><?= e($u['email']) ?></strong>. Ist die Adresse nicht korrekt,
       wende dich an die Projektleitung.</p>
  </div></div>
</div>
<?php
$content = ob_get_clean();
$title = 'Mein Profil';
require APP_PATH . '/pages/_layout.php';
