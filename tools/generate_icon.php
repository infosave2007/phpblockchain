<?php
// Simple CLI utility to generate a PNG network icon approximating the existing SVG
// Usage: php tools/generate_icon.php
// Writes: public/assets/network-icon.png

declare(strict_types=1);

// Ensure running via CLI
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Run from CLI: php tools/generate_icon.php\n");
    exit(1);
}

if (!function_exists('imagecreatetruecolor')) {
    fwrite(STDERR, "GD extension is required.\n");
    exit(1);
}

$size = 128; // output size
$radius = ($size / 2) - 4; // circle radius with padding
$cx = $cy = $size / 2;

$img = imagecreatetruecolor($size, $size);
imagesavealpha($img, true);
$transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
imagefill($img, 0, 0, $transparent);

// Colors (from SVG gradient #4a90e2 -> #2ecc71)
$start = [0x4a, 0x90, 0xe2];
$end   = [0x2e, 0xcc, 0x71];

// Radial gradient approximation: draw many concentric circles
$steps = (int)$radius;
for ($i = $steps; $i >= 0; $i--) {
    $t = ($steps - $i) / max(1, $steps);
    $r = (int)round($start[0] + ($end[0] - $start[0]) * $t);
    $g = (int)round($start[1] + ($end[1] - $start[1]) * $t);
    $b = (int)round($start[2] + ($end[2] - $start[2]) * $t);
    $col = imagecolorallocate($img, $r, $g, $b);
    imagefilledellipse($img, (int)$cx, (int)$cy, $i * 2, $i * 2, $col);
}

// Draw network nodes and edges (scaled from 64px SVG to 128px)
$white = imagecolorallocate($img, 255, 255, 255);
imagesetthickness($img, 6);

// Lines: (64,40)->(40,64), (64,40)->(88,64), (40,80)->(88,80)
imageline($img, 64, 40, 40, 64, $white);
imageline($img, 64, 40, 88, 64, $white);
imageline($img, 40, 80, 88, 80, $white);

// Nodes: centers at (64,32), (32,72), (96,72) radius ~8
imagefilledellipse($img, 64, 32, 16, 16, $white);
imagefilledellipse($img, 32, 72, 16, 16, $white);
imagefilledellipse($img, 96, 72, 16, 16, $white);

$outPath = __DIR__ . '/../public/assets/network-icon.png';
if (!is_dir(dirname($outPath))) {
    mkdir(dirname($outPath), 0775, true);
}

if (!imagepng($img, $outPath)) {
    fwrite(STDERR, "Failed to write PNG: $outPath\n");
    imagedestroy($img);
    exit(1);
}

imagedestroy($img);
fwrite(STDOUT, "Wrote: $outPath\n");
exit(0);
