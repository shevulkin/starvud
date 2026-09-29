<?php
declare(strict_types=1);

namespace Controllers;

use View, Newsletter;

class NewsletterController
{
    /** Відписка одним кліком із листа — без входу на сайт */
    public static function unsubscribe(string $token): never
    {
        $email = Newsletter::unsubscribeByToken($token);
        View::show('account/unsubscribe', [
            'email' => $email,
            'page_title' => 'Відписка від розсилки — ' . cfg('app_name'),
        ]);
    }
}
