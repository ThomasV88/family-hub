<?php

// Runtime contract for the image features: decoder, EXIF, upload limits, shared private store.

it('has gd with jpeg and webp support and the exif extension', function () {
    expect(extension_loaded('gd'))->toBeTrue();
    expect(extension_loaded('exif'))->toBeTrue();

    $info = gd_info();
    expect($info['JPEG Support'])->toBeTrue();
    expect($info['WebP Support'])->toBeTrue();
});

it('decodes a jpeg through gd', function () {
    $img = imagecreatetruecolor(2, 2);
    ob_start();
    imagejpeg($img);
    $bytes = ob_get_clean();

    $decoded = imagecreatefromstring($bytes);
    expect($decoded)->not->toBeFalse();
    expect(imagesx($decoded))->toBe(2);
});

it('carries the upload limits for the wish image and medical document routes', function () {
    expect(ini_get('upload_max_filesize'))->toBe('11M');
    expect(ini_get('post_max_size'))->toBe('13M');
});

it('has a writable private wish-images directory', function () {
    $dir = dirname(__DIR__, 3).'/storage/app/private/wish-images';

    expect(is_dir($dir))->toBeTrue();
    expect(is_writable($dir))->toBeTrue();
});
