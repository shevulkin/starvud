<section class="section" style="text-align:center;padding-top:96px">
  <div class="container narrow">
    <?php if ($email): ?>
      <div style="font-size:56px;margin-bottom:18px">✉️</div>
      <h2>Ви відписані</h2>
      <p class="muted" style="margin:18px auto 34px">Більше не надсилатимемо новини та акції на <b><?= e($email) ?></b>.<br>
        Це не впливає на ваші замовлення — про них ми повідомлятимемо як завжди.</p>
    <?php else: ?>
      <div style="font-size:56px;margin-bottom:18px">🤔</div>
      <h2>Посилання не спрацювало</h2>
      <p class="muted" style="margin:18px auto 34px">Схоже, ви вже відписані або посилання пошкоджене.<br>
        Керувати підпискою можна в профілі.</p>
    <?php endif; ?>
    <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap">
      <a class="btn btn-gold" href="<?= e(url('/')) ?>">На головну</a>
      <a class="btn btn-line" href="<?= e(url('/profile')) ?>">Мій профіль</a>
    </div>
  </div>
</section>
