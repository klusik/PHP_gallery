# TEMP plán: Admin Setup Wizard, guided UX a owner-safe apply

## Cíl a uživatelský model

Setup Wizard má být rychlá cesta k rozumnému základnímu nastavení celé instalace. Nemá kopírovat každou specializovanou administrační obrazovku ani uživatele během rozpracovaného draftu posílat jinam. Zdroj katalogu zůstává výhradně `admin_settings_registry()` a `admin_settings_sections()`, takže wizard nevytváří paralelní seznam nastavení. Každá registry položka je ve wizardu reprezentovaná, ale její prezentace závisí na tom, zda je bezpečně editovatelná v jedné staged transakci.

Wizard drží změny pouze v serverovém draftu. Přechod mezi hlavními kroky, podsekcemi, rozbalení Advanced/Expert a Theme preview nesmí nic persistovat. Skutečné ukládání začne až po explicitním potvrzení final review. Secret hodnoty, credentials a tokeny se nikdy nevracejí do draftu, HTML, logu ani příkladů.

## Osm hlavních kroků a podsekce

Serverový kontrakt zůstává na osmi stabilních sekcích: General, Site, Appearance, Content, Media, Uploads, Privacy a Advanced. Dlouhé sekce se nerozmnožují na desítky serverových kroků. Místo toho se uvnitř kroku dělí na krátké podsekce, například Privacy na Analytics, Trash, Maintenance a Security. Přepnutí podsekce je čistě klientské a nemění draft ani URL.

Bez JavaScriptu zůstávají všechny podsekce čitelné za sebou. Po JS enhancementu je aktivní vždy jeden panel a ostatní jsou skryté s korektním ARIA stavem. Navigační tlačítka mají `type=button`, takže přepnutí podsekce nikdy omylem neodešle formulář.

## Progressive disclosure

Každá registry položka dostane prezentační tier nezávislý na svém canonical owneru:

- `essential`: běžná rozhodnutí, která mají být viditelná bez další interakce;
- `advanced`: méně časté bezpečné volby, zavřené ve společném Advanced bloku;
- `expert`: provozní nebo citlivější konfigurace, zavřená v Expert bloku;
- `status`: specialistické operace, secrets, file-backed workflow a jiné položky, které se v staged wizardu bezpečně nemění.

Status položky nejsou falešně označované jako editovatelné a nemají checkbox `reviewed`. Wizard u nich pouze ukáže bezpečný stav, owner kontext a vysvětlení. Neobsahuje tlačítko ani odkaz typu „Open dedicated settings“, protože takový odchod by mohl okamžitě commitnout jinou konfiguraci mimo rozpracovaný draft. Uživatel tak může celý wizard dokončit bez přechodu na jiné Settings stránky.

## Final review

Final review je primárně diff, ne opakování celého registru. Změněné položky jsou viditelné rovnou. Nezměněné, přeskočené a informační/status položky jsou ve výchozím stavu sbalené. Uživatel může zapnout „Show unchanged and informational settings“, čímž se všechny příslušné bloky otevřou. Bez JavaScriptu zůstávají dostupné přes nativní `<details>`.

Language selector summary se zobrazuje detailně pouze tehdy, když se skutečně změnila některá z jeho tří hodnot. Sekce bez změn explicitně řekne, že v ní nebude nic změněno.

## Editace a canonical ownery

Wizard smí editovat pouze položky s explicitním adapterem a canonical saverem. Neexistuje generický „zapiš libovolný registry key“ mechanismus. Současný rozsah zahrnuje:

- centrální scalar settings přes existující Admin Settings normalizaci a saver;
- Theme basic appearance a bezpečné layout/card/grid/media hodnoty přes Theme services;
- Theme hero-tag scalar preference přes `theme_layout_settings.php`, včetně bumpu `theme_public_content_revision`;
- bounded upload preference přes upload-owned services;
- telemetry preference pouze při pozitivně dostupném `telemetry_settings` schema;
- bezpečné provozní preference přes `app/services/admin_setup_wizard/preferences.php`: thumbnail background warm-up, SEO request guard, Gallery Trash preference, application auto-update a scheduled maintenance preference.

Destruktivní akce zůstávají status-only. Wizard například může staged změnit Trash retention nebo auto-purge preference, ale nesmí během průchodu spustit Empty Trash/Purge. Stejně tak scheduled maintenance preference nejsou totéž jako Run now, database optimize/repair nebo updater activation.

## Coupled owner semantics

Některé preference mají společný canonical setter a side effects. Proto se nesmějí ukládat po jednotlivých řádcích.

Gallery Trash (`gallery_trash_*`) se při apply seskupí a uloží jedním `set_gallery_trash_settings(...)`. Scheduled Maintenance (`site_maintenance_*`) se seskupí a uloží jedním `set_site_maintenance_settings(...)`. Tím nevzniká mezistav, ve kterém by například zapnutí auto-purge přepočítalo deadline podle staré retention hodnoty.

Transakční model zároveň zamyká všechny app-settings siblings dané coupled skupiny, pokud se mění alespoň jeden člen. Optimistic comparison tak proběhne nad konzistentním owner snapshotem. Nezávislé provozní položky používají své canonical savery (`set_thumbnail_warmup_enabled`, SEO guard setters, `set_application_autoupdate_enabled`).

## Backend a MVC kontrakt

`app/services/admin_setup_wizard.php` načítá jednotlivé service parts. `catalog.php` sestavuje registry-backed katalog, owner adapter metadata, vstupní kontrakty, podsekce a tier. `draft.php` vlastní staged změny, skip a summary model. `apply.php` vlastní preflight, optimistic conflict kontrolu, storage dependencies, grouping owner změn a canonical saver dispatch. `preferences.php` je pouze adapter bezpečných provozních preferencí.

Controller vlastní auth, CSRF, request parsing a HTTP flow. Model vlastní PDO transakci a row locking. View pouze vykresluje připravená data a nesmí zapisovat nastavení. Browser modul vlastní pouze progressive enhancement, in-step subsection navigation, summary disclosure a Theme preview wiring.

Session draft používá `owner`, `revision`, `step`, `changes`, `skips` a `original`. Draft obsahuje pouze normalizované hodnoty položek, které wizard skutečně smí měnit. Neznámé ID, nepovolený adapter, neplatná hodnota nebo unavailable owner se odmítne před prvním saverem.

## Dostupnost, konflikty a bezpečnost

Schema/capability rozhodování používá fail-closed přístup. `unknown` blokuje apply. Telemetry adapter smí pracovat jen při pozitivně dostupném schema. Feature-effective policy zůstává autoritativní tam, kde registry položka závisí na capability.

Apply znovu ověří současné hodnoty proti `original`. Konflikt z jiné karty se nesmí tiše přepsat. `base_url` zachovává existující reversible save/compensation mechanismus. Secrets, API keys, passwords, raw credentials a file-backed asset payloady zůstávají mimo staged draft.

## UX pravidla použita v implementaci

Wizard používá progressive disclosure místo jedné dlouhé stěny formulářů. Často používané volby jsou viditelné, méně časté jsou pod Advanced a provozní/specialistické pod Expert. Podsekce jsou organizované podle konkrétního úkolu uživatele, ne podle interního názvu service souboru. Repetitivní nápověda typu „pokud si nejsi jistý, přeskoč“ je na úrovni kroku, ne pod každým polem.

Cílem není maximalizovat počet polí na obrazovce. Cílem je zachovat exhaustive registry coverage, ale běžnému administrátorovi ukázat malý počet relevantních rozhodnutí a zbytek zpřístupnit bez opuštění wizardu.

## Akceptační kontrakt

Focused a centrální testy mají ověřit zejména:

- všech osm hlavních registry sekcí a exhaustive representation registry položek;
- status/specialist položky nemají staged input ani outbound settings link;
- podsekce mění pouze prezentaci, ne URL, draft ani persistence;
- Advanced/Expert bloky jsou defaultně sbalené a no-JS fallback zůstává čitelný;
- final review ukazuje změny rovnou a unchanged/status bloky defaultně skrývá;
- normalizace odmítá neznámé, neplatné a truthy-string boolean hodnoty;
- Theme preview nepersistuje;
- telemetry schema preflight a oddělené row locks zůstávají zachované;
- Trash a Scheduled Maintenance jsou při apply seskupené do jednoho canonical owner callu;
- secret hodnoty nejsou v draftu ani summary;
- konfliktní stale draft se odmítne před saverem.

## Handoff

Po poslední source/documentation editaci se musí spustit `php scripts/generate_manifest.php` a následně `php scripts/generate_manifest.php --check`. Před vytvořením affected-files ZIPu se podle `AGENTS.md` spustí právě jeden `php scripts/audit.php --profile=full`. Pokud ZIP obsahuje updater-managed soubor, musí obsahovat i čerstvý `app/core-manifest.json`.

Tento TEMP dokument je pracovní implementační specifikace. Před release se má buď převést do permanentní dokumentace, nebo odstranit podle release pravidel.
