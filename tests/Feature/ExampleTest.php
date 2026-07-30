<?php

test('returns a successful response', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
});

test('homepage hero uses the Hero BG asset', function () {
    expect(public_path('assets/herobg_tk.jpg'))->toBeFile()
        ->and(file_get_contents(resource_path('js/components/public-flight/public-header.tsx')))
        ->toContain('/assets/herobg_tk.jpg');
});

test('homepage hero copy is translated for guests and signed in customers without a subtitle', function () {
    $header = file_get_contents(resource_path('js/components/public-flight/public-header.tsx'));
    $translations = file_get_contents(resource_path('js/lib/i18n.ts'));

    expect($header)
        ->toContain('public.heroTitleSignedIn')
        ->not->toContain('public.heroCopy')
        ->and($translations)
        ->toContain("'public.heroTitle': 'Where would you like to explore?'")
        ->toContain("'public.heroTitleSignedIn': 'Where would you like to explore, :name?'")
        ->toContain("'public.heroTitle': 'Nereyi keşfetmek istersiniz?'")
        ->toContain("'public.heroTitleSignedIn': 'Nereyi keşfetmek istersin, :name?'")
        ->not->toContain('public.heroCopy');
});
