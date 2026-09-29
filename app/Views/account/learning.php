<section class="section" style="padding-top:48px">
  <div class="container narrow" style="max-width:760px">
    <div class="kicker">Кабінет</div>
    <h2>Моє навчання</h2>
    <p style="margin-top:18px">
      <a class="btn btn-line btn-sm" href="<?= e(url('/profile')) ?>">← Профіль</a>
      <a class="btn btn-line btn-sm" href="<?= e(url('/orders')) ?>">📦 Мої замовлення</a>
    </p>

    <h3 class="h-serif" style="font-size:22px;margin-top:36px">Мої курси</h3>
    <?php if (!$courses): ?>
      <?php /* Порожньо — з дорогою назад. Глухий «нічого немає» лишає людину
               на сторінці, з якої нема куди йти. */ ?>
      <p class="dim" style="margin-top:10px">Куплених курсів поки немає.
        <a href="<?= e(url('/courses')) ?>">Подивитись, чого навчаємо →</a></p>
    <?php else: ?>
      <div style="margin-top:14px">
        <?php foreach ($courses as $c): $p = $c['product']; ?>
          <div class="admin-card" style="margin-bottom:12px">
            <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap">
              <?php $photo = Catalog::photo($p); if ($photo): ?>
                <img src="<?= e(asset(Images::displayThumb($photo))) ?>" alt=""
                     style="width:88px;height:88px;object-fit:cover;border-radius:8px;flex-shrink:0">
              <?php endif; ?>
              <div style="flex:1;min-width:200px">
                <div style="font-size:17px;font-weight:600"><?= e($p['name']) ?></div>
                <?php if (!empty($p['short_desc'])): ?>
                  <div class="dim" style="margin-top:4px"><?= e($p['short_desc']) ?></div>
                <?php endif; ?>
                <div style="margin-top:10px">
                  <?php if ($c['expired']): ?>
                    <?php /* Строк вийшов — курс лишається на місці з поясненням.
                             Зникнути з кабінету він не має: «я купив, а воно
                             пропало» читається як обман, а не як кінець строку. */ ?>
                    <span class="status-pill st-canceled">Строк доступу вийшов</span>
                    <span class="dim" style="margin-left:8px">до <?= e(date('d.m.Y', strtotime((string)$c['expires_at']))) ?></span>
                  <?php else: ?>
                    <span class="status-pill st-processing">Доступ відкрито</span>
                    <?php if ($c['expires_at'] !== null): ?>
                      <span class="dim" style="margin-left:8px">до <?= e(date('d.m.Y', strtotime((string)$c['expires_at']))) ?></span>
                    <?php else: ?>
                      <span class="dim" style="margin-left:8px">безстроково</span>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
                <?php /* Матеріалів ще немає — і про це сказано прямо. Мовчазна
                         картка без жодної кнопки виглядає як недороблений сайт,
                         а не як «знімаємо». */ ?>
                <p class="dim" style="margin:10px 0 0;font-size:13px">
                  Відео й навчальні матеріали готуються — щойно зʼявляться, вони відкриються тут.
                </p>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <h3 class="h-serif" style="font-size:22px;margin-top:40px">Мої сертифікати</h3>
    <?php if (!$diplomas): ?>
      <p class="dim" style="margin-top:10px">Сертифікатів поки немає — вони зʼявляються після закінчення курсу.</p>
    <?php else: ?>
      <div style="margin-top:14px">
        <?php foreach ($diplomas as $d): ?>
          <div class="admin-card" style="margin-bottom:12px">
            <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:center">
              <div>
                <div style="font-size:16px;font-weight:600"><?= e(Diplomas::courseLabel($d)) ?: 'Сертифікат' ?></div>
                <div class="dim" style="margin-top:4px">
                  № <?= e($d['number']) ?><?= $d['issued_at'] ? ' · виданий ' . e(date('d.m.Y', strtotime((string)$d['issued_at']))) : '' ?>
                </div>
              </div>
              <?php /* Посилання на публічну перевірку — те, що випускник
                       надсилає роботодавцю. Саме по це в кабінет і приходять. */ ?>
              <a class="btn btn-line btn-sm" href="<?= e(url('/diploma?number=' . urlencode((string)$d['number']))) ?>">
                Перевірка справжності</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
