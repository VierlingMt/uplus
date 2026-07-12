<?php
/** Kontakt: Projektleitung mit Foto und Kontaktdaten – für alle sichtbar. */
declare(strict_types=1);

$roles = ['lead' => 'Projektleitung'];

// Ansprechpartner = Projektleitung (Rolle „lead"), nur aktive Konten.
// Das Admin/Super-Admin-Konto ist eine technische Rolle und erscheint hier nicht.
$leads = Database::all(
    'SELECT id, name, email, phone, specialty, photo_path, role
     FROM users u
     WHERE u.is_active = 1
       AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role = "lead")
     ORDER BY name'
);

ob_start(); ?>
<div class="page-head">
  <h1>Kontakt</h1>
</div>

<p class="muted" style="margin:-8px 0 20px;max-width:640px">
  Bei Fragen rund um den Wettbewerb hilft dir die Projektleitung gerne weiter.
</p>

<?php if (!$leads): ?>
  <div class="card"><div class="card__body muted">Derzeit ist keine Projektleitung hinterlegt.</div></div>
<?php else: ?>
  <div class="grid cols-3">
    <?php foreach ($leads as $l): ?>
      <div class="card">
        <div class="card__body" style="display:flex;flex-direction:column;align-items:center;text-align:center;gap:10px">
          <?php if (!empty($l['photo_path'])): ?>
            <img class="avatar avatar--lg" src="<?= asset($l['photo_path']) ?>" alt="">
          <?php else: ?>
            <span class="avatar avatar--lg avatar--ph" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $l['name'], 0, 1))) ?></span>
          <?php endif; ?>
          <div>
            <strong style="font-size:17px"><?= e($l['name']) ?></strong><br>
            <span class="pill blue" style="margin-top:6px">Projektleitung</span>
          </div>
          <?php if (!empty($l['specialty'])): ?>
            <div class="muted" style="font-size:14px"><?= e($l['specialty']) ?></div>
          <?php endif; ?>
          <div style="display:flex;flex-direction:column;gap:6px;font-size:14px;width:100%">
            <?php if (!empty($l['email'])): ?>
              <a href="mailto:<?= e($l['email']) ?>" style="color:var(--wj-blue);text-decoration:none;word-break:break-word">✉ <?= e($l['email']) ?></a>
            <?php endif; ?>
            <?php if (!empty($l['phone'])): ?>
              <a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $l['phone'])) ?>" style="color:var(--wj-blue);text-decoration:none">☎ <?= e($l['phone']) ?></a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Kontakt';
require APP_PATH . '/pages/_layout.php';
