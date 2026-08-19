<?php

/**
 * Recorta el escaneo de la firma de Ericka Martinez y deja solo el trazo azul
 * con fondo transparente, listo para incrustar en el PDF de cotizacion.
 *
 * Uso: scripts\laragon-php.cmd scripts\prepare-signature.php [ruta_origen]
 */

if (! extension_loaded('gd')) {
    fwrite(STDERR, "ERROR: la extension GD no esta cargada.\n");
    exit(1);
}

$source = $argv[1] ?? 'C:\\Users\\asako\\.cursor\\projects\\c-laragon-www-cotizacion\\assets\\c__Users_asako_AppData_Roaming_Cursor_User_workspaceStorage_63099131cd1bcf0fd3683855bd6001c6_images_image-07af091c-2ff6-4e7c-84a8-1d63449e48c7.png';

if (! is_file($source)) {
    fwrite(STDERR, "ERROR: no se encontro la imagen de firma: {$source}\n");
    exit(1);
}

$raw = file_get_contents($source);
$src = $raw !== false ? @imagecreatefromstring($raw) : false;
if ($src === false) {
    $info = @getimagesize($source);
    $detected = $info['mime'] ?? 'desconocido';
    fwrite(STDERR, "ERROR: no se pudo abrir la imagen (tipo detectado: {$detected}).\n");
    exit(1);
}

$w = imagesx($src);
$h = imagesy($src);

// Detecta tinta AZUL de la firma; ignora el borde rasgado (negro/gris) y fondo claro.
$isInk = static function (int $r, int $g, int $b): bool {
    return $b > 80 && ($b - $r) > 25 && ($b - $g) > 15;
};

// Paso 1: marca pixeles de tinta azul cruda.
$ink = [];
for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        $rgb = imagecolorat($src, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        if ($isInk($r, $g, $b)) {
            $ink[$y * $w + $x] = true;
        }
    }
}

if ($ink === []) {
    fwrite(STDERR, "ERROR: no se detecto trazo de firma en la imagen.\n");
    exit(1);
}

// Paso 2: componente conexo (8-conectividad) mas grande = la firma.
// El ruido de banda/manchas del escaneo queda en componentes separados.
$visited = [];
$best = [];
$bestSize = 0;

foreach (array_keys($ink) as $startKey) {
    if (isset($visited[$startKey])) {
        continue;
    }

    $stack = [$startKey];
    $visited[$startKey] = true;
    $component = [];

    while ($stack !== []) {
        $key = array_pop($stack);
        $component[] = $key;
        $cy = intdiv($key, $w);
        $cx = $key % $w;

        for ($dy = -1; $dy <= 1; $dy++) {
            for ($dx = -1; $dx <= 1; $dx++) {
                if ($dx === 0 && $dy === 0) {
                    continue;
                }
                $nx = $cx + $dx;
                $ny = $cy + $dy;
                if ($nx < 0 || $nx >= $w || $ny < 0 || $ny >= $h) {
                    continue;
                }
                $nkey = $ny * $w + $nx;
                if (isset($ink[$nkey]) && ! isset($visited[$nkey])) {
                    $visited[$nkey] = true;
                    $stack[] = $nkey;
                }
            }
        }
    }

    if (count($component) > $bestSize) {
        $bestSize = count($component);
        $best = $component;
    }
}

$strong = [];
$minX = $w;
$minY = $h;
$maxX = -1;
$maxY = -1;

foreach ($best as $key) {
    $y = intdiv($key, $w);
    $x = $key % $w;
    $strong[$key] = true;
    if ($x < $minX) $minX = $x;
    if ($x > $maxX) $maxX = $x;
    if ($y < $minY) $minY = $y;
    if ($y > $maxY) $maxY = $y;
}

if ($maxX < 0) {
    fwrite(STDERR, "ERROR: no se detecto trazo continuo de firma en la imagen.\n");
    exit(1);
}

// Margen pequeno alrededor del trazo.
$pad = 12;
$minX = max(0, $minX - $pad);
$minY = max(0, $minY - $pad);
$maxX = min($w - 1, $maxX + $pad);
$maxY = min($h - 1, $maxY + $pad);

$cropW = $maxX - $minX + 1;
$cropH = $maxY - $minY + 1;

$out = imagecreatetruecolor($cropW, $cropH);
imagealphablending($out, false);
imagesavealpha($out, true);
$transparent = imagecolorallocatealpha($out, 255, 255, 255, 127);
imagefilledrectangle($out, 0, 0, $cropW, $cropH, $transparent);

for ($y = 0; $y < $cropH; $y++) {
    for ($x = 0; $x < $cropW; $x++) {
        $sy = $minY + $y;
        $sx = $minX + $x;

        if (isset($strong[$sy * $w + $sx])) {
            // Conserva el trazo reforzando el tono azul de la firma.
            $color = imagecolorallocate($out, 26, 60, 173);
            imagesetpixel($out, $x, $y, $color);
        }
        // Lo demas queda transparente.
    }
}

$destDir = __DIR__.'/../storage/app/public/company';
if (! is_dir($destDir)) {
    mkdir($destDir, 0775, true);
}

$dest = $destDir.'/firma.png';
imagepng($out, $dest);
imagedestroy($out);
imagedestroy($src);

echo 'OK: firma generada en '.$dest.' ('.$cropW.'x'.$cropH.', '.filesize($dest)." bytes)\n";
