<?php
/**
 * Перехід на платіжну сторінку банку.
 *
 * Сторінка без шапки, підвалу й меню навмисно: усе, що на ній можна натиснути,
 * крім «Оплатити», веде геть від оплати, за яку покупець уже взявся. Живе вона
 * долі секунди — форма відправляється сама.
 *
 * Кнопка під написом не запасна, а обов'язкова: без JavaScript автоматична
 * відправка не спрацює, і без неї покупець просто застряг би тут назавжди.
 * З тієї ж причини це окрема сторінка, а не редірект: параметри йдуть саме
 * методом POST, як вимагає шлюз.
 */
?><!DOCTYPE html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($page_title ?? 'Переходимо до оплати') ?></title>
  <style>
    body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
         background:#141110;color:#f6ecd9;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;text-align:center}
    .box{padding:24px;max-width:420px}
    h1{font-size:20px;font-weight:600;margin:0 0 10px}
    p{color:#9a8a6b;font-size:14px;line-height:1.5;margin:0 0 20px}
    .btn{display:inline-block;border:0;cursor:pointer;background:#f0b429;color:#241d15;
         font:600 15px/1 system-ui,sans-serif;padding:14px 26px;border-radius:8px}
    .spin{width:34px;height:34px;margin:0 auto 18px;border:3px solid #3a2f22;border-top-color:#f0b429;
          border-radius:50%;animation:s 1s linear infinite}
    @keyframes s{to{transform:rotate(360deg)}}
  </style>
</head>
<body>
  <div class="box">
    <div class="spin"></div>
    <h1>Переходимо до захищеної сторінки оплати</h1>
    <p>Дані картки ви вводите на сторінці банку — вони не проходять через наш сайт.
       Якщо нічого не сталося за кілька секунд, натисніть кнопку.</p>
    <form id="payForm" method="post" action="<?= e($action) ?>" accept-charset="UTF-8">
      <?php foreach ($fields as $name => $value): ?>
        <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
      <?php endforeach; ?>
      <button class="btn" type="submit">Перейти до оплати</button>
    </form>
  </div>
  <script>document.getElementById('payForm').submit();</script>
</body>
</html>
