<?php

namespace App\Http\Controllers;

class FaviconController extends Controller
{
    /** The brand accent (DB-driven), validated to a hex color. */
    private function accent(): string
    {
        $a = (string) config('brand.accent', '#06b6d4');

        return preg_match('/^#[0-9a-fA-F]{6}$/', $a) ? $a : '#06b6d4';
    }

    /** The brand glyph as raw SVG path data (24x24 heroicon coords). */
    private function glyph(): string
    {
        $paths = [
            'shield' => 'M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z',
            'key' => 'M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H9v1.5H7.5v1.5H6v1.5H2.25v-1.5l4.108-4.108a1.5 1.5 0 0 1 .43-1.563A6 6 0 1 1 21.75 8.25Z',
            'database' => 'M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125',
            'dashboard' => 'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25A2.25 2.25 0 0 1 13.5 8.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z',
            'refresh' => 'M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99',
            'lock' => 'M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z',
            'server' => 'M21.75 17.25v-.228a4.5 4.5 0 0 0-.12-1.03l-2.268-9.64a3.375 3.375 0 0 0-3.285-2.602H7.923a3.375 3.375 0 0 0-3.285 2.602l-2.268 9.64a4.5 4.5 0 0 0-.12 1.03v.228m19.5 0a3 3 0 0 1-3 3H5.25a3 3 0 0 1-3-3m19.5 0a3 3 0 0 0-3-3H5.25a3 3 0 0 0-3 3',
            'cloud' => 'M2.25 15a4.5 4.5 0 0 0 4.5 4.5H18a3.75 3.75 0 0 0 1.332-7.257 3 3 0 0 0-3.758-3.848 5.25 5.25 0 0 0-10.233 2.33A4.502 4.502 0 0 0 2.25 15Z',
        ];
        $name = (string) config('brand.icon', 'shield');

        return $paths[$name] ?? $paths['shield'];
    }

    /** Scalable favicon: the brand glyph in the accent on dark chrome. */
    public function svg()
    {
        $accent = $this->accent();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32">'
            . '<rect width="32" height="32" rx="7" fill="#0b1220"/>'
            . '<g transform="translate(4 4)" fill="none" stroke="' . $accent . '" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="' . $this->glyph() . '"/>'
            . '</g></svg>';

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function faviconPng()
    {
        return $this->png(64);
    }

    public function appleIcon()
    {
        return $this->png(180);
    }

    /** PNG fallback rendered with the accent (dark rounded square + shield + check). */
    private function png(int $size)
    {
        [$r, $g, $b] = sscanf($this->accent(), '#%02x%02x%02x');

        $im = imagecreatetruecolor($size, $size);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);

        $bg = imagecolorallocate($im, 0x0b, 0x12, 0x20);
        $accent = imagecolorallocate($im, $r, $g, $b);

        $rad = (int) round($size * 0.22);
        imagefilledrectangle($im, $rad, 0, $size - $rad, $size, $bg);
        imagefilledrectangle($im, 0, $rad, $size, $size - $rad, $bg);
        foreach ([[$rad, $rad], [$size - $rad, $rad], [$rad, $size - $rad], [$size - $rad, $size - $rad]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, $rad * 2, $rad * 2, $bg);
        }

        $p = fn ($x, $y) => [(int) round($x * $size), (int) round($y * $size)];
        $pts = array_merge($p(0.25, 0.24), $p(0.50, 0.16), $p(0.75, 0.24), $p(0.75, 0.55), $p(0.50, 0.82), $p(0.25, 0.55));
        imagefilledpolygon($im, $pts, $accent);

        imagesetthickness($im, max(2, (int) round($size * 0.075)));
        [$ax, $ay] = $p(0.39, 0.47);
        [$bx, $by] = $p(0.47, 0.56);
        [$dx, $dy] = $p(0.63, 0.36);
        imageline($im, $ax, $ay, $bx, $by, $bg);
        imageline($im, $bx, $by, $dx, $dy, $bg);

        ob_start();
        imagepng($im);
        $data = ob_get_clean();
        imagedestroy($im);

        return response($data, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
