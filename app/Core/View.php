<?php
declare(strict_types=1);

class View
{
    public static array $shared = [];

    public static function share(string $key, $value): void { self::$shared[$key] = $value; }

    public static function render(string $template, array $data = [], ?string $layout = 'layouts/main'): string
    {
        $content = self::partial($template, $data);
        if ($layout === null) return $content;
        return self::partial($layout, array_merge($data, ['content' => $content]));
    }

    public static function partial(string $template, array $data = []): string
    {
        $file = BOFU_ROOT . '/app/Views/' . $template . '.php';
        if (!is_file($file)) throw new RuntimeException("View not found: $template");
        extract(self::$shared, EXTR_SKIP);
        extract($data, EXTR_OVERWRITE);
        ob_start();
        require $file;
        return (string)ob_get_clean();
    }

    public static function show(string $template, array $data = [], ?string $layout = 'layouts/main'): never
    {
        echo self::render($template, $data, $layout);
        exit;
    }
}
