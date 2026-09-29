<div class="admin-head"><h1 class="h-serif">Власники точок</h1></div>

<p class="card-lead" style="margin:-14px 0 22px">
  Мережа буває однією лише на вигляд: два магазини можуть належати одному ФОПу, а третій —
  іншому. Для покупця це один сайт, для податкової — <b>два окремі платники</b>: свої ПРРО,
  свої ключі, свої декларації і свої ліміти доходу, які перевищуються кожен окремо.
  Тут видно, хто чий, і тут же задається ставка, з якою пробиваються чеки його точок.
</p>

<?php if ($orphans): ?>
  <div class="admin-card" style="border-color:var(--warn,#f0b429);margin-bottom:18px">
    <b>Точки без власника:</b>
    <?= e(implode(', ', array_map(fn($s) => (string)$s['name'], $orphans))) ?>.
    Поки власника немає, ставка й підпис беруться з налаштувань самої точки — це працює,
    але не каже, чий це виторг.
  </div>
<?php endif; ?>

<form class="admin-card" method="post" action="<?= e(url('/admin/owners')) ?>"
      style="display:flex;gap:14px;align-items:end;flex-wrap:wrap">
  <?= Csrf::field() ?><input type="hidden" name="_action" value="add">
  <div style="flex:2;min-width:260px">
    <label class="dim" style="font-size:12px">Назва як у документах</label>
    <input type="text" name="name" placeholder="ФОП Прізвище Імʼя Батькович" required>
  </div>
  <div style="flex:1;min-width:160px">
    <label class="dim" style="font-size:12px">ІПН або ЄДРПОУ</label>
    <input type="text" name="tax_id" maxlength="20" placeholder="1234567890">
  </div>
  <button class="btn btn-gold" type="submit">Додати власника</button>
</form>

<?php if (!$owners): ?>
  <div class="admin-card dim" style="margin-top:18px">
    Поки нікого немає. Якщо всі точки належать одному ФОПу — заводити нікого й не треба,
    усе працюватиме як раніше. Це потрібно тоді, коли платників податків більше ніж один.
  </div>
<?php else: ?>

<form method="post" action="<?= e(url('/admin/owners')) ?>">
  <?= Csrf::field() ?><input type="hidden" name="_action" value="save">

  <?php foreach ($owners as $o): $id = (int)$o['id']; ?>
    <div class="admin-card" style="margin-top:18px<?= $o['active'] ? '' : ';opacity:.6' ?>">
      <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap">
        <h2 class="h-serif" style="margin:0"><?= e($o['name']) ?></h2>
        <label class="checkbox" style="margin:0">
          <input type="checkbox" name="owner[<?= $id ?>][active]" <?= $o['active'] ? 'checked' : '' ?>> Працює
        </label>
      </div>

      <div class="form-grid" style="margin-top:12px">
        <div class="field">
          <label>Назва</label>
          <input type="text" name="owner[<?= $id ?>][name]" value="<?= e($o['name']) ?>" maxlength="200">
        </div>
        <div class="field">
          <label>ІПН / ЄДРПОУ</label>
          <input type="text" name="owner[<?= $id ?>][tax_id]" value="<?= e($o['tax_id'] ?? '') ?>" maxlength="20">
        </div>
        <div class="field" data-help-title="Група єдиного податку"
             data-help="Та сама, що у вас у свідоцтві платника єдиного податку.

Вона НЕ потрапляє в чек і не має нічого спільного з «податковою групою чека» поруч. Тут вона потрібна для двох речей: щоб було видно, у кого який ліміт доходу, і щоб система помітила неможливе поєднання — перша й друга групи платниками ПДВ бути не можуть.">
          <label>Група єдиного податку</label>
          <select name="owner[<?= $id ?>][ep_group]">
            <option value="0">загальна система</option>
            <?php foreach ($ep_groups as $g => $label): ?>
              <option value="<?= (int)$g ?>"<?= (int)($o['ep_group'] ?? 0) === (int)$g ? ' selected' : '' ?>>
                <?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Платник ПДВ</label>
          <label class="checkbox" style="margin-top:8px">
            <input type="checkbox" name="owner[<?= $id ?>][vat]" <?= !empty($o['vat']) ? 'checked' : '' ?>>
            Так, зареєстрований платником ПДВ
          </label>
        </div>
        <div class="field" data-help-title="Податкова група чека"
             data-help="Ставка, з якою товар потрапляє у фіскальний чек і в ДПС. Коди тут — від ДПС, і з групою єдиного податку вони НЕ збігаються.

Найчастіша й найдорожча помилка: людина на 3-й групі ЄП ставить сюди «3», а це означає «ПДВ 20% + акциз 5%». Чеки пробиваються, все виглядає добре, а в податкову їде вигаданий податок.

Не платник ПДВ — майже завжди «2 (Без ПДВ)».

Ця ставка діє на всі точки власника. У товару може бути своя (підакцизне, пільгове), і вона старша.">
          <label>Податкова група чека</label>
          <select name="owner[<?= $id ?>][taxgrp]">
            <option value="0">як у налаштуваннях</option>
            <?php foreach ($tax_groups as $code => $label): ?>
              <option value="<?= (int)$code ?>"<?= (int)($o['taxgrp'] ?? 0) === (int)$code ? ' selected' : '' ?>>
                <?= (int)$code ?> — <?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Підпис під нічним Z-звітом</label>
          <input type="text" name="owner[<?= $id ?>][cashier]" value="<?= e($o['cashier'] ?? '') ?>" maxlength="100"
                 placeholder="як у налаштуваннях">
          <p class="field-hint">Чеки продажу підписані іменем продавця — це поле їх не стосується.</p>
        </div>
      </div>

      <?php /* Реквізити для рахунків. Окремим блоком, бо заповнюють їх раз і
               назавжди, а дивляться на них лише тоді, коли клієнт просить
               рахунок. */ ?>
      <details<?= trim((string)($o['iban'] ?? '')) !== '' ? ' open' : '' ?> style="margin-top:6px">
        <summary class="dim" style="cursor:pointer;font-size:13px">
          🧾 Реквізити для рахунків
          <?= trim((string)($o['iban'] ?? '')) !== '' ? '— заповнені' : '— не заповнені, рахунок не виставити' ?>
        </summary>
        <div class="form-grid" style="margin-top:10px">
          <div class="field" style="grid-column:1/-1">
            <label>Повна назва як у документах</label>
            <input type="text" name="owner[<?= $id ?>][full_name]" value="<?= e($o['full_name'] ?? '') ?>"
                   maxlength="200" placeholder="Фізична особа-підприємець Прізвище Імʼя Батькович">
            <p class="field-hint">У списках зручне коротке імʼя, а в рахунку має стояти повне.</p>
          </div>
          <div class="field" style="grid-column:1/-1">
            <label>IBAN</label>
            <input type="text" name="owner[<?= $id ?>][iban]" value="<?= e($o['iban'] ?? '') ?>"
                   maxlength="40" placeholder="UA00 0000 0000 0000 0000 0000 0000" spellcheck="false">
          </div>
          <div class="field">
            <label>Банк</label>
            <input type="text" name="owner[<?= $id ?>][bank]" value="<?= e($o['bank'] ?? '') ?>" maxlength="150">
          </div>
          <div class="field">
            <label>Хто підписує документи</label>
            <input type="text" name="owner[<?= $id ?>][signer]" value="<?= e($o['signer'] ?? '') ?>"
                   maxlength="150" placeholder="Прізвище І. Б.">
          </div>
          <div class="field" style="grid-column:1/-1">
            <label>Адреса</label>
            <input type="text" name="owner[<?= $id ?>][address]" value="<?= e($o['address'] ?? '') ?>" maxlength="250">
          </div>
        </div>
      </details>

      <div class="form-grid" style="margin-top:10px">
        <div class="field" style="grid-column:1/-1">
          <label>Нотатка</label>
          <input type="text" name="owner[<?= $id ?>][note]" value="<?= e($o['note'] ?? '') ?>" maxlength="500"
                 placeholder="контакти бухгалтера, номер договору — що знадобиться">
        </div>
      </div>

      <?php if ($o['problems']): ?>
        <div style="margin-top:12px;padding:10px 14px;border:1px solid var(--warn,#f0b429);border-radius:8px">
          <?php foreach ($o['problems'] as $p): ?>
            <div class="dim" style="font-size:12.5px">⚠️ <?= e($p) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:14px">
        <div data-help-title="Виторг за даними сайту"
             data-help="Сума підзамовлень цього власника за рік, без скасованих.

Це УПРАВЛІНСЬКА цифра, а не декларація: сюди не входять продажі повз сайт і не враховано нічого, що вміє бухгалтер. Але саме її бракує, щоб вчасно побачити наближення до ліміту єдиного податку — а ліміт у кожного платника свій.">
          <div class="dim" style="font-size:12.5px">Виторг за <?= (int)$year ?> (за даними сайту)</div>
          <b style="font-size:17px"><?= e(price_fmt($o['income'])) ?></b>
        </div>
        <?php if ($o['income_prev'] > 0): ?>
          <div>
            <div class="dim" style="font-size:12.5px">За <?= (int)$year - 1 ?></div>
            <b><?= e(price_fmt($o['income_prev'])) ?></b>
          </div>
        <?php endif; ?>
      </div>

      <?php /* Каси власника: ПРРО реєструють на торгову точку, тож у ФОПа з
               двома магазинами кас теж дві, і в Device Manager вони звуться
               по-різному. Ключ при цьому один — його завантажують в обидві.
               Без цієї таблички зʼясовувати, чий «kasa2», доводилось би по
               картках магазинів по одній. */ ?>
      <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--bg3)">
        <div class="dim" style="font-size:12.5px;margin-bottom:8px">Точки й каси цього власника</div>
        <?php if (!$o['kassy']): ?>
          <div class="dim">Точок не призначено — оберіть їх у таблиці внизу сторінки.</div>
        <?php else: ?>
          <table class="tbl">
            <tr><th>Точка</th><th>Як доходимо до каси</th><th>Каса</th></tr>
            <?php foreach ($o['kassy'] as $storeId => $k): ?>
              <tr>
                <td><?= e($k['store']) ?></td>
                <td class="dim"><?= e($k['route']) ?></td>
                <td>
                  <?php if ($k['ready']): ?>
                    <b><?= e($k['device'] ?: '—') ?></b>
                  <?php else: ?>
                    <span class="dim">не налаштована —
                      <a href="<?= e(url('/admin/stores')) ?>" style="color:inherit;text-decoration:underline">картка точки</a></span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </table>
          <p class="dim" style="margin:8px 0 0;font-size:12px">
            Ключ у цього власника один на всі його каси — його завантажують у кожен ПРРО окремо,
            у Device Manager. Назва каси тут і назва в Device Manager мають збігатися точно.
          </p>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="admin-card" style="margin-top:18px">
    <h2 class="h-serif">Чия яка точка</h2>
    <p class="dim" style="margin:-6px 0 14px">
      Від цього залежить, чиїм ПРРО пробиватиметься чек і в чий виторг він потрапить.
      Замовлення з товарами різних власників розпадається на частини, і кожна отримує
      <b>свій чек від свого ПРРО</b> — так і має бути, кожен фіскалізує своє.
    </p>
    <table class="tbl">
      <tr><th>Точка</th><th>Власник</th></tr>
      <?php foreach (DB::all('SELECT id, name, city, owner_id FROM stores ORDER BY sort, id') as $s): ?>
        <tr>
          <td><?= e($s['name']) ?><?php if ($s['city']): ?> <span class="dim"><?= e($s['city']) ?></span><?php endif; ?></td>
          <td>
            <select name="store_owner[<?= (int)$s['id'] ?>]">
              <option value="0">— не вказано —</option>
              <?php foreach ($owners as $o): ?>
                <option value="<?= (int)$o['id'] ?>"<?= (int)($s['owner_id'] ?? 0) === (int)$o['id'] ? ' selected' : '' ?>>
                  <?= e($o['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <button class="btn btn-gold" type="submit" style="margin-top:18px">💾 Зберегти</button>
</form>
<?php endif; ?>
