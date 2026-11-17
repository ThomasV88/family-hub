# Demo údaje na stagingu: mapa scenárov

Táto stránka je pre majiteľa. Hovorí, kto je kto v demo rodine, čo kto vidí a ktoré záznamy otvoriť, aby si vyskúšal jednotlivé situácie. Všetko je vymyslené (mená majú príponu "(demo)", telefónne čísla a odkazy sú nepoužiteľné, žiadni skutoční ľudia ani firmy).

**Dôležité:** demo údaje sa vytvoria nanovo pri každom nasadení stagingu (`develop`). Čo si na stagingu sám pridáš alebo zmeníš pri testovaní, pri ďalšom nasadení zmizne a nahradí ho čerstvá demo rodina. Dátumy sú vždy vzhľadom na deň nasadenia: história siaha asi 4 roky späť až po dnes a sú tam aj veci, ktoré ešte len prídu. Mená členov sa pri opakovaných nasadeniach nemenia.

Heslo: všetky účty majú rovnaké heslo zo servera (`STAGING_SEED_PASSWORD` v `app.env` na stagingu; nikdy nie v gite). Ak tam nie je nastavené (alebo je kratšie ako 16 znakov), účty majú náhodné heslá a prihlásiť sa nedá.

## Účty

| Účet | Rola | Čo vidí a smie |
|---|---|---|
| `sk_sk.1@example.test` | Rodič (slovenský) | Úlohy, priania, financie a zdravie: všetko môže meniť. Vidí všetkých 5 finančných účtov a všetkých 5 osôb v zdraví. Má aj vlastný zdravotný záznam. |
| `en_gb.5@example.test` | Rodič (anglický) | Rovnako ako rodič 1, ale nemá vlastný zdravotný záznam. Nevidí súkromný účet rodiča 1 (vidí 4 z 5 účtov). |
| `sk_sk.2@example.test` | Dieťa (15 rokov) | Úlohy a priania môže meniť. Financie len na čítanie, vidí len 2 účty (spoločný a hotovosť). Zdravie len na čítanie, vidí len 2 osoby (Miško a starý rodič). |
| `en_gb.6@example.test` | Dieťa (12 rokov) | Úlohy a priania. Financie a zdravie nevidí vôbec. Jeho zoznam prianí spravuje rodič `en_gb.5`. |
| `pl_pl.3@example.test` | Starý rodič | Úlohy a priania môže meniť. Zdravie len na čítanie (vlastný záznam, Peter Kováč a Miško). Financie nevidí. |
| `pl_pl.4@example.test` | Hosť | Úlohy a zoznamy prianí len na čítanie (nemôže rezervovať). Financie a zdravie nevidí vôbec. |
| Maťko Demo (demo) | Dieťa bez prihlásenia | Detský účet bez e-mailu a bez hesla: **nemôže sa prihlásiť**, nemá reláciu ani prístupový kľúč. Má vygenerovanú syntetickú fotku (farebné tvary) a tri prianá. Jeho zoznam prianí a profil spravuje rodič `sk_sk.1` (z `/profile` časť "People you manage"). Pri každom nasadení sa zmaže aj s fotkou a vytvorí nanovo. |
| `admin@example.test` | Správca | Len nastavenia (členovia, oprávnenia, audit). Nemá žiadne financie ani zdravie, nikomu nič nepatrí. |

Predvolené oprávnenia (Rodič, Dieťa, Starý rodič, Hosť) zostávajú. Demo ich dopĺňa troma vecami, aby sa dali skúšať obe strany súkromia: zdravie pre oboch rodičov (zmena) a pre `sk_sk.2` a `pl_pl.3` (čítanie), financie na čítanie pre `sk_sk.2` a zoznamy prianí na čítanie pre hosťa (inak by jeho zoznam nikto nevidel).

## Čo otvoriť a čo má byť vidieť

### Úlohy (`/todo`)
- **Hotové úlohy z minulosti:** asi 20 hotových úloh rozložených na 3 roky, najstaršie z doby pred asi 3 rokmi.
- **Po termíne:** 5 úloh (napríklad "Renew the car tax online", "Uporządkować stare dokumenty w szafie") a jedno opakované umývanie auta, ktorého otvorený výskyt je už po termíne.
- **Dnes a čoskoro:** 3 úlohy majú termín dnes (jedna o 9:00, jedna o 17:30), ďalšie sú zajtra, o 2, 4, 6, 9 dní a neskôr.
- **Opakované úlohy:** denná (vyvenčiť psa), týždenná v utorok a piatok (smeti), mesačná prvého v mesiaci (nájom a energie), "každý 2. týždeň v utorok" (bio odpad), posledný deň v mesiaci (elektromer) a týždenná v sobotu (auto). Každá má niekoľko dokončených výskytov a práve jeden otvorený.
- **Pripomienky:** úlohy s pripomienkou 15 minút, hodinu a deň vopred sú pri budúcich termínoch s časom.
- **Súkromné:** šesť osobných úloh je vidieť len tvorcovi (napríklad "Kúpiť prekvapenie pre manželku k výročiu" len u rodiča 1). Prihlás sa ako iný člen a over, že ich nevidí. Tri úlohy sú zdieľané len s vybranými členmi.
- **Dlhé texty:** úloha "Pripraviť podklady pre ročné vyúčtovanie energií..." a "Naplánovať veľké jarné upratovanie domu..." majú dlhý názov aj poznámky na kontrolu zalamovania.

### Zoznamy prianí (`/wishlist`)
- Každý člen má 5 až 12 prianí s cenou, prípadne s poznámkou, veľkosťou a odkazom (odkazy vedú na `example.com` a nič sa nikdy nesťahuje, obrázky tam nie sú).
- **Súkromie rezervácií:** prihlás sa ako `sk_sk.2` (dieťa) a otvor svoj zoznam. Prianie "LEGO Technic Bugatti" je rezervované rodičom 1, ale ty nesmieš vidieť kto, ani že je rezervované. Potom sa prihlás ako `sk_sk.1`, otvor zoznam dieťaťa a rezervácia je vidieť. To isté platí pre `en_gb.6` (jeho rodič správca `en_gb.5` tiež nič nevidí).
- **Spoločné kúpy:** "Bicykel horský" (dieťa `sk_sk.2`) má otvorený spoločný nákup (rodič 1, starý rodič a rodič 2 prispievajú). "Bežecké hodinky" (rodič 1) tiež. "Trampolína" (en_gb.6) a "Robot kuchenny" (starý rodič) majú uzavretý spoločný nákup.
- **Darované:** "Robot kuchenny" v zozname starého rodiča je už označený ako dostaný (pred asi 12 dňami), rezervácia sa ešte drží, kým ju neprečistí denná údržba.
- **Príležitosti:** narodeniny členov v rôznych vzdialenostiach (jedny o 20 dní, jedny pred 30 dňami), mená dní (slovenský a poľský kalendár), Vianoce a Silvester pre celú rodinu, jednorazové "Rodinný zraz" čoskoro a "Promócia" v minulosti.

### Nákupné zoznamy (`/shopping`)
Všetko vzniká cez produkčné služby (rovnaké pravidlá ako v aplikácii). Katalóg má 30 bežných vecí (Mlieko, Chlieb, Vajcia, Zemiaky, Jablká a ďalšie) s predvolenou jednotkou a vygenerovaným obrázkom, päť vymyslených obchodov ("Demo Market", "Zelený košík", "Potraviny Pri Dube", "Drogéria Lúka", "Mini Obchod Ráno") s vygenerovaným logom (farebné tvary, žiadny text ani skutočná značka) a jedna vlastná jednotka "plechovka" (en can, pl puszka). Zodpovedná osoba zoznamu je tá, ktorá ho vie zamknúť a uzavrieť.
- **"Týždenný nákup"** (rodič `sk_sk.1` je zodpovedný, otvorený, obchod Demo Market): 9 položiek z katalógu aj voľným textom, desatinné množstvá (0,75 kg jabĺk s poznámkou), prispievatelia `sk_sk.2` a `pl_pl.3`, čitateľ hosť `pl_pl.4`, pripomienka zajtra o 18:00 (podľa časovej zóny rodiny, ešte neodoslaná).
- **"Drobnosti z drogérie"** (rodič `en_gb.5`, otvorený): bez obchodu, tri položky voľným textom.
- **"Sobotný trh"** (starý rodič `pl_pl.3`, **zamknutý, napoly označený**): kúpené, nedostupné aj čakajúce položky; prispievateľ `sk_sk.1`, čitateľ `sk_sk.2`.
- **"Oslava narodenín"** (rodič `en_gb.5`, **zamknutý, všetko vyriešené**): vidno tlačidlo na ukončenie nákupu.
- **"Nákup na víkend"** (rodič `sk_sk.1`, **ukončený**, bez prenosu): vidno ho v archíve; prispievateľ `sk_sk.2`.
- **"Veľký nákup"** (rodič `en_gb.5`, **ukončený s nedostupnými položkami**) a jeho **prenesený** otvorený zoznam "Veľký nákup (nezakúpené)" (iba nedostupné položky, všetky čakajú).
- **"Nákup na víkend (kópia)"** (**kópia** ukončeného zoznamu, rodič `sk_sk.1`, ľudia zachovaní).
- Hosť `pl_pl.4` má Nákupy len na čítanie (vidí len zoznamy, kde je čitateľ). Pri ďalšom nasadení sa všetko vrátane obrázkov (`wish-images/shopping/`) zmaže a vytvorí nanovo; na produkcii sa katalóg ani obchody nevytvárajú, tam ho plní rodina.

### Financie (`/finance`)
- **Päť účtov:** Spoločný účet (bežný), Súkromný účet rodiča, Úspory, Kreditná karta, Hotovosť v peňaženke. Všetko v eurách.
- **Skrytý účet:** prihlás sa ako `sk_sk.2` a over, že vidí len Spoločný účet a Hotovosť. Súkromný účet, úspory a karta pre neho neexistujú. `sk_sk.1` vidí všetkých päť, `en_gb.5` štyri (bez súkromného účtu rodiča 1).
- **Prevod so skrytým protiúčtom:** "Vreckové" (prevod zo spoločného účtu na súkromný) a "Mesačné sporenie" (na úspory). Rodič vidí oba účty prevodu, dieťa vidí len nohu zo spoločného účtu ako obyčajný výdavok a nikde nie je protiúčet.
- **História:** asi 3,5 roka mesačne výplaty dvoch rodičov, hypotéka, energie, potraviny, palivo, krúžky, predplatné, splátky karty, letná dovolenka každý júl a vianočné darčeky každý december, plus päť veľkých jednorazových nákupov (práčka, notebook, oprava auta, pohovka, zubár) a nákup tabletu na kartu.
- **Opakované platby a očakávané vs. skutočné:** hypotéka, elektrina a plyn (kolíše podľa sezóny), internet, fitness, poistenie auta (štvrťročne) a streamovacia služba. Väčšina je zaplatená. **Fitness** má poslednú splatnosť bez platby, teda po termíne. **Poistenie auta** má jeden starší rok zaplatený inou sumou, než sa čakalo ("zaplatené inak"). Streamovanie zdražie pred asi 15 mesiacmi (očakávaná suma sa zmení).
- **Rozpočty:** pre tento a minulý mesiac (potraviny, doprava, deti, voľný čas, zdravie, ostatné). Rozpočet na voľný čas je tento mesiac prekročený.
- **Import:** jedna potvrdená dávka na spoločnom účte (výdavky z jedného mesiaca spred pol roka majú zdroj "import") a jeden rozpracovaný import na kreditnej karte s riadkami "nový", "duplicita" a "neplatný" (rozpracovaný import sa o 24 hodín samočinne zmaže).

### Zdravie (`/health`)
- **Päť osôb (plus archivovaná šiesta):** (A) člen `sk_sk.1` s vlastným záznamom, (B) Peter Kováč (demo), dospelý bez účtu, (C) Miško Horváth (demo), dieťa asi 8 rokov, (D) starý rodič `pl_pl.3` s vlastným záznamom, (E) Marek Skrytý (demo).
- **Skrytá osoba:** Marek Skrytý (demo) je vidieť len rodičom. `sk_sk.2` ho vo zozname nenájde a priamy odkaz mu dá odmietnutie. Hosť, `en_gb.6` a správca nemajú v zdraví žiadne oprávnenie.
- **Prístupy:** rodičia menia všetko. `sk_sk.2` má na čítanie len Miška a starého rodiča. `pl_pl.3` má na čítanie vlastný záznam, Petra Kováča a Miška.
- **Návštevy a lekári:** spolu asi 55 návštev za posledné roky, 15 a viac špecializácií (všeobecný lekár, zubár, kardiológ, ortopéd, dermatológ, očný, alergológ, diabetológ, neurológ a ďalšie, jedna "iná" so zadaným názvom), s diagnózami a poznámkami.
- **Lieky:** súčasné (bez dátumu ukončenia) aj ukončené.
- **Alergie:** s úrovňami ľahká, stredná, ťažká aj neznáma (napríklad penicilín je ťažká u rodiča 1).
- **Očkovanie, pripomienky:** "Tetanus (preočkovanie)" u osoby A má ďalší termín **o 14 dní** (na vyskúšanie pripomienky), "MMR (2. dávka)" u Miška je **po termíne** o 45 dní. Ďalšie termíny sú o 90 až 400 dní.
- **Núdzové kontakty a krvné skupiny:** každá osoba má 1 až 3 kontakty (vymyslené čísla) a krvnú skupinu, takže funguje aj núdzová karta.
- **Vek:** od približne 8 do 74 rokov.
- **Súhlas s AI:** zapnutý len pri osobe A a pri starom rodičovi D, vypnutý pri ostatných.
- **Archív (rozhodnutie majiteľa: po lehote len archivácia):** všetky osoby a záznamy sú vytvorené viac než 72 hodín dozadu (lehota `MEDICAL_DELETE_WINDOW_HOURS`, predvolene 72), takže zmazanie na staging záznam **archivuje**, nie zmaže. Navyše je v demo dátach archivovaná jedna návšteva osoby A ("Archivovaná návšteva (demo)"), jeden dokument osoby A ("Archivovaný starý výsledok (demo)") a jedna osoba "Archivovaná osoba (demo)" (s návštevou a alergiou). Archivované veci nie sú v zoznamoch, exportoch, núdzovej karte ani v pripomienkach. Rodičia (Edit) ich obnovia z položky "Archivované položky" v menu osoby a z časti "Archivované osoby" pod výberom osôb; `sk_sk.2` a `pl_pl.3` (len čítanie) archív nevidia. Nový záznam vytvorený na staging dnes sa dá do lehoty zmazať natrvalo.

### Dokumenty a návrhy od AI (`/health`, detail osoby)
- **Sedem malých súborov (jeden je archivovaný):** výsledky krvných testov (PDF, 2 strany) a fotka vyrážky (obrázok) u osoby A, laboratórny výsledok (obrázok) u Petra Kováča, očkovací preukaz (PDF, 2 strany) u Miška, prepúšťacia správa (PDF, 2 strany) u starého rodiča a recept (obrázok) u skrytého Mareka. K tomu archivovaný PDF dokument osoby A (v Archíve, nie v zozname). Náhľady a sťahovanie fungujú. Obsah je len nápis "SYNTHETIC DEMO DOCUMENT".
- **Návrhy na kontrolu bez Codexu:** dokument "Výsledky krvných testov" (osoba A) má hotový návrh. Návšteva je už prijatá ako "AI vyťažené, neoverené" (značka "Mark as verified" / "Označiť ako overené" funguje), alergia je prijatá a overená, liek je zamietnutý a očkovanie čaká na rozhodnutie. Dokument "Prepúšťacia správa" (starý rodič) má návrh, kde nie je nič rozhodnuté (štyri položky na prijatie alebo zamietnutie). "Fotka vyrážky" má neúspešný beh (neplatný výstup). V zozname AI volaní v nastaveniach sú tri bezobsahové záznamy.
- Žiadne volanie na žiadneho poskytovateľa AI sa pri seedovaní nevykoná.

## Poznámky pre správu
- Súbory dokumentov sa pri novom nasadení zmažú spolu s osobami, ktorým patrili, aj archivovanými (zmazanie osôb ide podľa vlastníka, bez ohľadu na archív; po úspešnom dokončení seedovania); `medical:prune-orphans` je poistka pre prípadné zvyšky.
- História volaní AI (`ai_calls`) a audit sa nikdy nemažú. Každé nasadenie pridá tri záznamy o volaniach AI so zaniknutými demo používateľmi (audit ich akceptuje, `ai:prune-calls` ich odstráni po období uchovania). Seedovanie samo nezapisuje žiadne audit záznamy.
- Syntetické dieťa (bez e-mailu) sa pozná podľa prípony "(demo)" v mene **a zároveň** podľa toho, že ho spravujú len syntetickí členovia (`@example.test`, aspoň jeden správca zoznamu prianí). Samotná prípona nestačí, lebo je to voľný text, ktorý vie niekto napísať alebo doplniť pri premenovaní. Skutočné dieťa (alebo dieťa bez správcu, alebo so skutočným rodičom medzi správcami), aj keď sa volá "Anna (demo)", sa pri novom nasadení nikdy nezmaže a `staging:audit` ho označí ako nesyntetické. Pravidlo je na jednom mieste, v `App\Support\SyntheticChild`, a používa ho seeder aj audit.
- Seedovanie sa nikdy nespúšťa na produkcii (`staging:seed` aj `staging:audit` odmietnu prostredie `production`).
