<?php

use App\Medical\Documents\Text\SearchTerms;
use Illuminate\Support\Facades\DB;

const DOC_SEARCH_ALPHABET = 'áäčďéíĺľňóôŕšťúýžąćęłńśźżÁÄČĎÉÍĹĽŇÓÔŔŠŤÚÝŽĄĆĘŁŃŚŹŻ';

it('folds every Slovak and Polish letter the same in SQL and in PHP', function () {
    $diff = [];
    foreach (mb_str_split(DOC_SEARCH_ALPHABET) as $letter) {
        $sql = (string) DB::selectOne('select lower(public.fh_unaccent(?)) as f', [$letter])->f;
        if ($sql !== SearchTerms::fold($letter)) {
            $diff[] = $letter.': sql='.$sql.' php='.SearchTerms::fold($letter);
        }
    }

    expect($diff)->toBe([]);
});
