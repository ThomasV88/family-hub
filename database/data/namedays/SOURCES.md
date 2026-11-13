# Name-day data: provenance and licences

Both calendars are versioned static JSON (`sk.json`, `pl.json`), 366 `MM-DD` keys each (including `02-29`). They are rebuilt only from the stored sources below, never typed from memory.

## Slovak (`sk.json`)

- Source: https://github.com/jozefgrencik/slovak-name-days, file `data/names.json`
- Pinned commit: `2a9e495ed84777d3fa0dd7d35274a057bb98b047` (fetched 2026-11-13)
- Licence: MIT, copyright (c) 2023 Jozef Grencik. Full text in `LICENSE-sk.txt` (copy at `source/sk/LICENSE`).
- Calendar note: the official Slovak calendar approved June 2017 (Ministry of Culture), reviewed yearly. 1 January has no name.
- Rebuild: `php scripts/namedays/build-sk.php database/data/namedays/source/sk/names.json database/data/namedays/sk.json <version>` (version in the form `<commit>+<date>`).

## Polish (`pl.json`)

- Source: the 12 Polish Wikipedia monthly pages "Imieniny – <miesiac>", linked from https://pl.wikipedia.org/wiki/Imieniny, fetched through the MediaWiki API on 2026-11-13. Stored wikitext is in `source/pl/<MM>.wikitext`, titles and revision ids (revid) in `source/pl/revisions.json`:

| Month | Title | revid |
|---|---|---|
| 01 | Imieniny – styczeń | 78166931 |
| 02 | Imieniny – luty | 76185468 |
| 03 | Imieniny – marzec | 75814485 |
| 04 | Imieniny – kwiecień | 80828733 |
| 05 | Imieniny – maj | 75814594 |
| 06 | Imieniny – czerwiec | 75379089 |
| 07 | Imieniny – lipiec | 80150807 |
| 08 | Imieniny – sierpień | 77669893 |
| 09 | Imieniny – wrzesień | 80826946 |
| 10 | Imieniny – październik | 80443901 |
| 11 | Imieniny – listopad | 75973502 |
| 12 | Imieniny – grudzień | 76963015 |

- Licence: CC BY-SA 4.0 (https://creativecommons.org/licenses/by-sa/4.0/). Attribution: "Wikipedia contributors", Polish Wikipedia. The licence notice shipped with the data is `LICENSE-pl.txt`. The in-app attribution is the lang key `occasions.dates.pl_attribution` plus links to the source and licence and a "modified" note (`pl_attribution_source`, `pl_attribution_licence`, `pl_attribution_modified`). The compiled list is a derived work and stays under CC BY-SA 4.0.
- Parser notes: day headings are `==` or `===` with `[[D <genitive month>]]`; names are comma separated; links, markup and templates are stripped; a parenthetical starting with a capital is an alternative spelling kept as a name; a lowercase parenthetical (for example "w latach nieprzestępnych") is a qualifier and dropped; "Wszyscy, w tym:" on 1 November is not a name and is dropped.
- The list is best-effort (Wikipedia lists many regional and historical names). A member can always use the custom-occasion fallback.
- Rebuild from stored sources (no network): `php scripts/namedays/build-pl.php --build database/data/namedays/source/pl database/data/namedays/pl.json`
- Refresh: `php scripts/namedays/build-pl.php --fetch database/data/namedays/source/pl`, then `--build`, review the diff, update the table above and commit.
