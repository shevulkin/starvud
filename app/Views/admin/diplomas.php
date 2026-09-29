<div class="admin-head"><h1 class="h-serif">Дипломи випускників</h1></div>
<form class="admin-card" method="post" action="<?= e(url('/admin/diplomas')) ?>" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">
  <?= Csrf::field() ?><input type="hidden" name="_action" value="add">
  <div data-help-title="Номер диплома"
       data-help="Унікальний номер, який надрукований на самому дипломі.

Саме за ним будь-хто зможе перевірити диплом на сайті: роботодавець вводить номер і бачить, чи він дійсний.

Тому номер має збігатися з паперовим документом символ у символ — інакше перевірка не знайде запис.">
    <label>Номер</label><input type="text" name="number" required placeholder="BOFU-2026-001"></div>
  <div style="flex:1;min-width:180px" data-help-title="Випускник"
       data-help="ПІБ людини, яка отримала диплом, — так само, як у документі.

Це імʼя показується при перевірці: той, хто вводить номер, бачить, кому диплом виданий. Тому воно має точно збігатися з паперовим.">
    <label>Випускник</label><input type="text" name="student" required></div>
  <div data-help-title="Курс"
       data-help="Назва програми чи курсу, який закінчила людина.

Необовʼязкове поле, але корисне: при перевірці одразу видно, чого саме стосується диплом.

Текст лишається на бланку назавжди — навіть якщо курс у каталозі згодом перейменують.">
    <label>Курс</label><input type="text" name="course" placeholder="Назва курсу"></div>
  <?php if ($courses): ?>
    <div data-help-title="Курс із каталогу"
         data-help="Звʼязок диплома з курсом, який продається на сайті.

Потрібен для звітності: скільки дипломів видано за кожним курсом. На сам бланк не впливає — там друкується текст із поля «Курс» ліворуч.

Необовʼязково: дипломи за програмами, яких немає в каталозі, лишаються без звʼязку.">
      <label>Курс із каталогу</label>
      <select name="product_id">
        <option value="">— не вказано —</option>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  <?php endif; ?>
  <div style="flex:1;min-width:200px" data-help-title="Акаунт випускника"
       data-help="Номер телефону або пошта, з якими випускник заходить на сайт.

Саме цей звʼязок робить диплом видимим у розділі «Мої сертифікати» в його кабінеті. Без нього диплом лишається лише в реєстрі: перевірити за номером зможе будь-хто, а от сам випускник у себе його не побачить.

Імені для цього недостатньо — тезки існують, а прізвища міняються.

Якщо акаунта ще немає, лишіть поле порожнім: диплом додасться, а привʼязати його можна буде згодом кнопкою в таблиці.">
    <label>Акаунт випускника <span class="dim">(телефон або пошта)</span></label>
    <input type="text" name="contact" placeholder="067 123 45 67 або student@ukr.net"></div>
  <div data-help-title="Дата видачі"
       data-help="Коли диплом видано. Показується при перевірці разом з іменем і курсом.

Ставте дату з документа, а не сьогоднішню, якщо вносите старі дипломи заднім числом.">
    <label>Дата видачі</label><input type="date" name="issued_at"></div>
  <button class="btn btn-gold btn-sm" type="submit" data-help-title="Кнопка «Додати»"
          data-help="Вносить диплом у реєстр — одразу зі станом «Дійсний».

Із цієї миті його можна перевірити на сайті за номером. Перевіряти може будь-хто, вхід для цього не потрібен.">+ Додати</button>
</form>
<table class="tbl">
  <tr><th>Номер</th><th>Випускник</th><th>Курс</th>
    <th data-help-title="Колонка «Кабінет»"
        data-help="Чи бачить випускник цей диплом у себе в розділі «Мої сертифікати».

«Не привʼязано» означає, що диплом є в реєстрі й перевіряється за номером, але в жодному кабінеті не показується — система не знає, чий він.

Привʼязати можна тут же: введіть номер телефону або пошту, з якими людина заходить на сайт. Якщо акаунта ще немає, спершу випускник має увійти.">Кабінет</th>
    <th>Видано</th>
    <th data-help-title="Колонка «Стан»"
        data-help="«Дійсний» — при перевірці за номером сайт підтвердить диплом.

«Анульований» — запис лишається, але перевірка покаже, що диплом недійсний. Так роблять, коли документ відкликано або видано помилково.">Стан</th>
    <th></th></tr>
  <?php foreach ($diplomas as $d): ?>
    <tr>
      <td><b><?= e($d['number']) ?></b></td>
      <td><?= e($d['student']) ?></td>
      <td class="muted"><?= e(Diplomas::courseLabel($d)) ?: '—' ?></td>
      <td>
        <?php if (!empty($d['user_id'])): ?>
          <span class="status-pill st-processing">✓ <?= e($d['user_name'] ?: 'акаунт') ?></span>
          <div class="dim" style="font-size:11.5px;margin-top:4px"><?= e($d['user_phone'] ?: $d['user_email']) ?></div>
        <?php else: ?>
          <?php /* Не просто позначка «немає», а місце, де це виправляють:
                   інакше по кожен диплом довелось би йти в іншу форму */ ?>
          <form method="post" action="<?= e(url('/admin/diplomas')) ?>" style="display:flex;gap:6px"><?= Csrf::field() ?>
            <input type="hidden" name="_action" value="link"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <input type="text" name="contact" placeholder="телефон або пошта" style="width:150px">
            <button class="btn btn-line btn-xs" type="submit">Привʼязати</button>
          </form>
        <?php endif; ?>
      </td>
      <td class="dim"><?= e($d['issued_at'] ?: '—') ?></td>
      <td><?= $d['active'] ? '<span class="status-pill st-processing">Дійсний</span>' : '<span class="status-pill st-canceled">Анульований</span>' ?></td>
      <td style="white-space:nowrap">
        <form method="post" action="<?= e(url('/admin/diplomas')) ?>" style="display:inline"><?= Csrf::field() ?>
          <input type="hidden" name="_action" value="toggle"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
          <button class="btn btn-line btn-xs" data-help-title="Анулювати / Відновити"
                  data-help="Перемикає диплом між «Дійсний» і «Анульований».

Анулювання не стирає запис: він лишається в реєстрі, і перевірка за номером чесно покаже, що документ недійсний. Саме це й потрібно, коли диплом відкликали.

Дію можна відкотити кнопкою «Відновити»."><?= $d['active'] ? 'Анулювати' : 'Відновити' ?></button>
        </form>
        <form method="post" action="<?= e(url('/admin/diplomas')) ?>" style="display:inline"><?= Csrf::field() ?>
          <input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
          <button class="btn btn-danger btn-xs" onclick="return confirm('Видалити запис?')"
                  data-help-title="Видалити запис"
                  data-help="Стирає диплом із реєстру назавжди. Після цього перевірка за його номером не знайде нічого — так, ніби документа ніколи не існувало.

Якщо диплом відкликано, правильніше «Анулювати»: тоді видно, що документ був і його скасували. Видалення лишіть для записів, внесених помилково.">✕</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
</table>
