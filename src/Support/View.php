<?php

declare(strict_types=1);

namespace Billas\Support;

use RuntimeException;

/**
 * Native PHP view renderer with a whitelist to prevent path traversal.
 */
final class View
{
    public function __construct(private readonly string $viewPath)
    {
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = $this->renderFile($template, $data);
        if ($layout === null) {
            return $content;
        }

        return $this->renderFile($layout, $data + [
            'content' => $content,
            'flashes' => billas_flashes(),
        ]);
    }

    /** @param array<string,mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        return $this->renderFile($template, $data);
    }

    /** @param array<string,mixed> $data */
    private function renderFile(string $template, array $data): string
    {
        // Layouts are commonly passed as "layout" / "wifi/layout" while pages
        // include the extension. Accept both spellings.
        $template = str_replace('\\', '/', trim($template));
        if (!str_ends_with(strtolower($template), '.php')) {
            $template .= '.php';
        }
        if (!preg_match('#^[A-Za-z0-9_\-/]+\.php$#', $template) || str_contains($template, '..')) {
            throw new RuntimeException('Nama view tidak valid: ' . $template);
        }
        $file = $this->viewPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $template);
        if (!is_file($file)) {
            throw new RuntimeException('View tidak ditemukan: ' . $template);
        }

        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
