<?php

it('renders the stored theme into the html tag', function () {
    $html = $this->withUnencryptedCookie('fh_theme', 'dark')->get('/login')->getContent();

    expect($html)->toContain('data-theme="dark"')->toContain('data-theme-pref="dark"');
});

it('leaves system unresolved server-side', function () {
    $this->get('/login')->assertSee('data-theme-pref="system"', false)->assertDontSee('data-theme="', false);
    $this->withUnencryptedCookie('fh_theme', 'system')->get('/login')
        ->assertSee('data-theme-pref="system"', false)->assertDontSee('data-theme="', false);
});

it('treats a tampered value as system and never echoes it', function () {
    $this->withUnencryptedCookie('fh_theme', '"><b>')->get('/login')
        ->assertSee('data-theme-pref="system"', false)
        ->assertDontSee('"><b>', false);
});

it('emits no inline script in the root view', function () {
    expect(file_get_contents(resource_path('views/app.blade.php')))->not->toContain('<script');
});
