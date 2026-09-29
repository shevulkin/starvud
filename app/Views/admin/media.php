<div class="admin-head">
  <h1 class="h-serif">Фото й файли</h1>
</div>
<?php /*
  Зона перетягування замість пари «системне поле + кнопка».
  Чому саме так — коментар до .dropzone у app.css і до BofuDrop в admin.js.
  Коротко: системне поле неможливо застилізувати, а «обрати» й «надіслати» —
  це одна дія, яку немає підстав ділити на дві.
*/ ?>
<div class="dropzone" id="mediaDrop" data-help-title="Завантаження фото"
     data-help="Перетягніть сюди фото з теки або клацніть, щоб обрати їх на компʼютері. Можна кілька одразу — вони підуть по черзі.

Завантажене фото потрапляє у спільну бібліотеку: звідти його можна ставити товарам, банерам і в галерею сайту.

Саме по собі завантаження нічого на сайті не змінює — фото просто лягає в загальний список. Щоб воно зʼявилось у покупця, призначте його товару в картці або оберіть у налаштуваннях банера чи галереї.

Беріть якісний оригінал: сайт сам зробить зменшені копії для каталогу, а от із маленького файлу різкої великої картинки не вийде.">
  <input type="file" accept="image/*" multiple>
  <span class="dropzone-title">Перетягніть фото сюди</span>
  <span class="dropzone-hint">або клацніть, щоб обрати на компʼютері · можна кілька одразу</span>
  <span class="dropzone-note"></span>
</div>
<?php /*
  Запасний шлях без JavaScript.
  Зона перетягування — це скрипт, і там, де його немає, завантаження зникло б
  зовсім. Стара форма коштує шести рядків і лишає спосіб покласти фото; в
  <noscript> вона не заважає нікому, бо з робочим скриптом браузер її не малює.
*/ ?>
<noscript>
  <form method="post" action="<?= e(url('/admin/media')) ?>" enctype="multipart/form-data"
        style="display:flex;gap:10px;align-items:center;margin-top:12px">
    <?= Csrf::field() ?><input type="hidden" name="_action" value="upload">
    <input type="file" name="image" accept="image/*" required>
    <button class="btn btn-gold btn-sm" type="submit">Завантажити</button>
  </form>
</noscript>
<p class="dim" style="margin:14px 0 18px">Усі фото сайту. Хрестик видаляє фото звідусіль. Фото, привʼязані до товарів, банерів чи галереї, видалити не можна — спершу приберіть/замініть їх там (посилання під фото). Вбудовані фото дизайну видалити не можна.</p>
<div class="img-grid media-lib">
  <?php foreach ($items as $it): $uses = $it['usage'] ?? []; ?>
    <div class="img-cell">
      <img src="<?= e(asset($it['thumb'])) ?>" alt="" loading="lazy">
      <?php if (!$it['builtin']): ?>
        <?php if ($uses): ?>
          <span class="badge cell-badge" title="Фото використовується — видалення заблоковано"
                data-help-title="Замок на фото"
                data-help="Фото десь використовується, тому видалити його не можна. Число — у скількох місцях.

Це захист від порожніх картинок на сайті: інакше видалене фото зникло б із картки товару чи банера, і покупець побачив би дірку.

Посилання під фото ведуть саме туди, де воно стоїть. Щоб фото можна було видалити, спершу приберіть або замініть його в кожному з цих місць — тоді замок зникне й зʼявиться хрестик.">🔒 <?= count($uses) ?></span>
        <?php else: ?>
          <form method="post" action="<?= e(url('/admin/media')) ?>" class="cell-action">
            <?= Csrf::field() ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="path" value="<?= e($it['path']) ?>">
            <button class="btn btn-danger btn-xs" style="padding:3px 8px" onclick="return confirm('Видалити фото з сайту?')"
                    data-help-title="Видалити фото"
                    data-help="Стирає файл із сайту назавжди — відновити його не можна, доведеться завантажувати наново.

Хрестик є лише в тих фото, які ніде не використовуються: якщо стоїть замок, спершу приберіть фото з товарів і банерів.

Вбудовані зображення дизайну сайту не видаляються взагалі — у них немає ні хрестика, ні замка.">✕</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
      <span class="dim"><?= (int)$it['width'] ?>×<?= (int)$it['height'] ?> · <?= round($it['bytes']/1024) ?> КБ</span>
      <?php if ($uses): ?>
        <div class="cell-uses">
          <?php foreach ($uses as $u): ?>
            <div><a href="<?= e($u['url']) ?>">→ <?= e($u['label']) ?></a></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<script>
(function () {
  var zone = document.getElementById('mediaDrop');
  var grid = document.querySelector('.media-lib');
  if (!zone || !grid || !window.BofuDrop) return;

  var base = '<?= e(url('/')) ?>'.replace(/\/$/, '');
  var csrf = '<?= e(Csrf::token()) ?>';

  /*
   * Комірку показуємо ОДРАЗУ, ще до відповіді сервера.
   *
   * Превʼю беремо з самого файлу (URL.createObjectURL) — воно вже є на
   * компʼютері, чекати нема чого. Поки фото летить, комірка приглушена й
   * знебарвлена: видно, що вона ще не готова, і водночас видно, що процес
   * пішов. Порожній екран на цій секунді читається як «кнопка не працює».
   */
  function placeholder(file) {
    var cell = document.createElement('div');
    cell.className = 'img-cell is-uploading';
    var img = document.createElement('img');
    img.src = BofuDrop.preview(file);
    img.alt = '';
    var cap = document.createElement('span');
    cap.className = 'dim';
    cap.textContent = 'завантажую…';
    cell.appendChild(img);
    cell.appendChild(cap);
    grid.insertBefore(cell, grid.firstChild);   // найновіше зверху, як і в списку з сервера
    return cell;
  }

  function ready(d, cell) {
    if (!cell) return;
    cell.classList.remove('is-uploading');
    var img = cell.querySelector('img');
    if (img) {
      URL.revokeObjectURL(img.src);             // тимчасове посилання більше не потрібне
      img.src = base + '/assets/' + BofuDrop.thumbOf(d.path);
      img.loading = 'lazy';
    }
    var cap = cell.querySelector('.dim');
    if (cap) cap.textContent = d.width + '×' + d.height + ' · ' + Math.round(d.bytes / 1024) + ' КБ';

    // Свіже фото ніде не використовується, тож у нього завжди хрестик, а не
    // замок. Форма та сама, що й у решти комірок: видалення лишається
    // звичайним POST із підтвердженням.
    var form = document.createElement('form');
    form.method = 'post';
    form.action = base + '/admin/media';
    form.className = 'cell-action';
    form.innerHTML = '<input type="hidden" name="_csrf" value="' + csrf + '">' +
      '<input type="hidden" name="_action" value="delete">' +
      '<input type="hidden" name="path">' +
      '<button class="btn btn-danger btn-xs" style="padding:3px 8px" type="submit">✕</button>';
    form.querySelector('[name=path]').value = d.path;
    form.addEventListener('submit', function (e) {
      if (!confirm('Видалити фото з сайту?')) e.preventDefault();
    });
    cell.insertBefore(form, cell.firstChild.nextSibling);
  }

  function failed(cell) {
    if (!cell) return;
    var img = cell.querySelector('img');
    if (img) URL.revokeObjectURL(img.src);
    cell.remove();
  }

  BofuDrop.attach(zone, {
    url: base + '/admin/media',
    csrf: csrf,
    field: 'image',
    onStart: placeholder,
    onDone: ready,
    onFail: failed
  });
})();
</script>
