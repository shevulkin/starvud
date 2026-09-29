<div class="modal-back" id="mediaPicker" style="z-index:120">
  <div class="modal" style="max-width:760px;max-height:84vh;overflow:auto">
    <h3>Вибрати фото</h3>
    <?php /* Той самий компонент, що й у медіа-бібліотеці: завантаження туди ж,
             у ту саму бібліотеку, тож і поводитись має однаково. Компактний
             вигляд — бо під зоною одразу йде сітка вибору. */ ?>
    <div class="dropzone is-slim" id="mpDrop" style="margin:14px 0">
      <input type="file" accept="image/*" multiple>
      <span class="dropzone-title">Перетягніть фото сюди</span>
      <span class="dropzone-hint">або клацніть, щоб обрати на компʼютері</span>
      <span class="dropzone-note"></span>
    </div>
    <div class="img-grid" id="mpGrid" style="min-height:120px"></div>
    <div class="stack"><button class="btn btn-line btn-sm" id="mpClose" type="button">Закрити</button></div>
  </div>
</div>
<script>
window.MediaPicker = (function(){
  var base = '<?= e(url('/')) ?>'.replace(/\/$/, '');
  var csrf = '<?= e(Csrf::token()) ?>';
  var modal = document.getElementById('mediaPicker');
  var grid = document.getElementById('mpGrid');
  var onPick = null;

  function load(){
    fetch(base + '/admin/media?format=json').then(r=>r.json()).then(function(d){
      grid.innerHTML = '';
      d.items.forEach(function(it){
        var cell = document.createElement('div');
        cell.className = 'img-cell';
        cell.style.position = 'relative';
        cell.innerHTML = '<img src="' + base + '/assets/' + it.thumb + '" style="cursor:pointer" title="Обрати">' +
          '<span class="dim">' + it.width + '×' + it.height + ' · ' + Math.round(it.bytes/1024) + ' КБ</span>' +
          (it.builtin ? '' : '<button type="button" class="btn btn-danger btn-xs" style="position:absolute;top:4px;right:4px;padding:3px 8px" title="Видалити з сайту">✕</button>');
        cell.querySelector('img').addEventListener('click', function(){
          if (onPick) onPick(it.path);
          close();
        });
        var del = cell.querySelector('button');
        if (del) del.addEventListener('click', function(){
          if (!confirm('Видалити фото з сайту? Воно зникне з товарів і банерів.')) return;
          var fd = new FormData();
          fd.append('_csrf', csrf); fd.append('_action', 'delete'); fd.append('path', it.path); fd.append('format', 'json');
          fetch(base + '/admin/media', {method:'POST', body:fd}).then(r=>r.json()).then(load);
        });
        grid.appendChild(cell);
      });
    });
  }
  function open(cb){ onPick = cb; modal.classList.add('open'); load(); }
  function close(){ modal.classList.remove('open'); }
  document.getElementById('mpClose').addEventListener('click', close);
  modal.addEventListener('click', function(e){ if (e.target === modal) close(); });
  /*
   * Завантаження — спільним компонентом (BofuDrop), а не власним обробником.
   *
   * Раніше тут лежала своя копія: системне поле, кнопка поруч і свій fetch.
   * Копія розходиться — у бібліотеці завантаження вже вміло кілька файлів і
   * перетягування, а тут лишалось по одному й тільки кліком.
   *
   * Сітку перечитуємо з сервера ОДИН раз, коли все завантажилось, а не після
   * кожного файлу: інакше десять фото дали б десять перемальовувань, і
   * обране під курсором стрибало б із-під пальця.
   */
  if (window.BofuDrop) {
    BofuDrop.attach(document.getElementById('mpDrop'), {
      url: base + '/admin/media',
      csrf: csrf,
      field: 'image',
      onAll: function (done) { if (done) load(); }
    });
  }
  return { open: open };
})();
</script>
