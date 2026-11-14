<?php

use Illuminate\Support\Facades\Storage;

it('configures the medical disk as private, outside public and outside the local disk', function () {
    $disk = config('filesystems.disks.medical');

    expect($disk['serve'])->toBeFalse()
        ->and($disk['throw'])->toBeFalse()
        ->and($disk['root'])->toBe(storage_path('app/medical'))
        ->and(str_starts_with($disk['root'], storage_path('app/private')))->toBeFalse()
        ->and(str_starts_with($disk['root'], public_path()))->toBeFalse();
});

it('writes medical files with mode 0600 and directories with mode 0700', function () {
    $root = sys_get_temp_dir().'/fh-medical-'.bin2hex(random_bytes(4));
    config(['filesystems.disks.medical.root' => $root]);
    Storage::forgetDisk('medical');

    try {
        Storage::disk('medical')->put('subject/doc.pdf', 'x');

        expect(substr(sprintf('%o', fileperms($root.'/subject/doc.pdf')), -4))->toBe('0600')
            ->and(substr(sprintf('%o', fileperms($root.'/subject')), -4))->toBe('0700');
    } finally {
        Storage::forgetDisk('medical');
        @unlink($root.'/subject/doc.pdf');
        @rmdir($root.'/subject');
        @rmdir($root);
    }
});
