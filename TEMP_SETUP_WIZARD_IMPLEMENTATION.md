# TEMP plán: Admin Setup Wizard v1

## Cíl a cesta administrátora

Wizard provede administrátora celým kanonickým Settings registry. Každý krok zobrazí stabilní název, lokalizovaný popis, příklad použití, aktuální/default/inherited stav, vlastníka a bezpečný odkaz na specializovanou stránku. Administrátor může krok přeskočit; přeskočení zachová původní hodnotu. Na konci wizard zobrazí souhrn změn, přeskočených položek, blokací a konfliktů. Persistence smí začít až po explicitním potvrzení tohoto souhrnu.

Appearance kroky musí znovu použít existující Theme preview hooks/state pro všechny relevantní položky. Preview je pouze dočasná klientská nebo serverová reprezentace a nesmí zapisovat nastavení. Tajné hodnoty se nikdy nevracejí do draftu, HTML, logu ani příkladů.

## Exhaustive registry coverage

Zdroj seznamu je výhradně `admin_settings_registry()`, v pořadí registry a stabilních sekcí. Wizard nesmí vytvářet druhý katalog. Zobrazí i summary-only/specialized položky, ale ty označí jako „otevřít specializovanou stránku“ a bezpečně odloží. Editovatelné položky mohou získat typovaný návrh pouze přes `admin_setup_wizard` API a kanonické ukládací funkce vlastníka nastavení.

Každá položka musí mít překladové klíče pro všechny čtyři podporované katalogy (`en`, `cs`, `de`, `sv`) s popisem a konkrétním příkladem; chybějící překlad použije bezpečný fallback a test jej nahlásí. Machine IDs zůstávají interní. Příklady musí být konkrétní pro dané nastavení (URL pro `base_url`, text názvu pro `site_name`, jazyky pro selector, checkbox příklady pro přepínače, renderer hodnoty pro thumbnail mode).

V1 skutečně editable registry pole jsou: `base_url`, `site_name`, `public_language`, `public_language_selector_enabled`, `public_language_selector_languages`, `public_language_selector_design`, `url_rewrite_enabled`, `public_home_search_enabled`, `public_thumbnail_rendering_mode`, `exif_gps_maps_default_enabled`, `dev_mode_enabled` a `remote_favicon_discovery_enabled`. Ostatní položky z aktuálních 177 registry ID jsou v této verzi summary/deferred podle svého canonical ownera.

## Backend a MVC kontrakt

Service `app/services/admin_setup_wizard.php` vlastní sestavení kroků, normalizaci, životní cyklus návrhu, kontrolu konfliktů a finální ukládání. Controller vlastní přihlášení, CSRF, čtení požadavku, HTTP odpověď a přípravu dat pro view. Model vlastní databázový přístup a transakci. View pouze vykresluje připravená data.

V1 backend API je `app/services/admin_setup_wizard.php`: `admin_setup_wizard_steps()`, `admin_setup_wizard_normalize_change()` a `admin_setup_wizard_apply($changes, $original)`; session draft používá `owner`, `revision`, `step`, `changes`, `skips`, `original`. Draft obsahuje pouze normalizované změny, nikdy kompletní kopii secretů. Normalizace odmítne neznámé ID, nepovolený typ, specializovanou položku a neplatnou hodnotu. Hodnoty rovné normalized current/default stavu se do draftu nezařadí.

Final apply znovu ověří admina, CSRF, capabilities/schema state a revision každé dotčené položky. Model použije transakci pro DB změny; `base_url` změna vyžaduje explicitně popsanou kompenzaci/rollback URL a nesmí rozbít návrat do summary. Konflikt z jiné karty znamená odmítnutí apply a nové načtení snapshotu, nikoli tiché přepsání. Heterogenní specializované akce a destruktivní operace jsou ve v1 pouze deferred s canonical deep-linkem.

Schema a capability rozhodování používá třístavový model `available/missing/unknown`. `unknown` blokuje apply; `missing` smí pokračovat pouze při dokumentované compatibility path. Feature effective state je rozhodující pro dostupnost, configured state se nesmí zaměnit.

## Implementační vlny a delegace

**Povinné pravidlo: každou dílčí implementační úlohu delegovat subagentovi. Výchozí volba je `gpt-5.6-luna` s reasoning `low`. Toto pravidlo se nesmí tiše obejít tím, že hlavní agent začne psát všechny části sám.** Zadání musí mít konkrétní vlastnictví souborů, skutečné rozhraní a ověřitelnou podmínku dokončení. Hlavní agent odpovídá za integraci a kontrolu výsledku.

Na silnější model řady `5.6` nebo vyšší reasoning přejít podle skutečné složitosti: transakce přes databázi a konfiguraci, konflikty, bezpečnostní hranice, opětovné využití složených editorů a důkladná kontrola překladů. Jednoduché izolované změny dál zadávat `gpt-5.6-luna/low`. **Silné subagenty GPT-6 pro tento úkol nepoužívat.** Povinnost delegace není důvod přijmout nedokončený výsledek; krátký report musí odpovídat skutečné implementaci a požadované kvalitě.

Aktuální limit je čtyři sloty včetně rootu: nejvýše tři paralelní děti v jedné vlně. Celkový počet úkolů může být větší, ale musí běžet v po sobě jdoucích vlnách; plán nesmí předstírat neomezenou paralelitu.

1. Backend: service/model API, návrh, normalizace, revize a transakce s kompenzací.
2. `wizard_ui`: controller/view/browser flow, summary approval, preview reuse, cache-busting.
3. kontrakty a překlady: registry coverage, four-language examples, focused deterministic tests.
4. root integruje změny, route registration, audit registration a řeší konflikty.

Žádné commity, PDF, AI obrázky, release packaging ani duplicitní audity. Root spouští centrální audit podle AGENTS.md.

## Akceptační testy v1

`tests/admin_setup_wizard_test.php` musí být plain PHP, deterministický a bez live DB. Ověří:

- exhaustive registry coverage a stabilní sections;
- skip každého pole zachová originál;
- neznámé ID a chybný typ jsou odmítnuty;
- draft obsahuje jen skutečně změněné normalized hodnoty;
- bez explicitního approval nedojde k persistence;
- final approval guard vyžaduje auth/CSRF/revision;
- optimistic conflict a multi-tab revision jsou odmítnuty;
- secret values nejsou v draftu ani summary;
- překladové klíče, popisy a příklady existují v `en`, `cs`, `de`, `sv`;
- appearance kroky používají existující preview hooks;
- specialized/destructive položky jsou bezpečně deferred s canonical route.

Test musí používat skutečné API `admin_setup_wizard`; pokud agent API upraví, test a kontrakt se aktualizují společně. Nový test lze během vývoje spustit samostatně. PHP testy přebírá centrální audit automaticky; hlavní agent po integraci použije centrální audit místo ručního přehrávání testů.

## Verze a známé mezery v1

V1 dodává registry průchod, bezpečné drafty, unchanged skip, finální explicitní approval, konflikty, lokalizované popisy/příklady a reuse existujícího appearance preview. `base_url` znamená pouze URL instalace; `galleries_root` je filesystem root a ve V1 je read-only, bez tiché migrace. Budoucí fáze musí dodat bezpečnou validaci, preview, potvrzení a recoverable migration workflow pro změnu galleries root. Specializované Theme uploady, raw CSS, language import/export, API keys, credentials, telemetry maintenance, migrations, database repair, updater activation a destruktivní maintenance zůstávají deferred; wizard je pouze vysvětlí a odkáže na canonical owner route. Další fáze mají dodat deferred adapters/export/import podle ownerů, nikoli druhý persistence mechanismus.

Před handoffem root ověří MVC hranice, admin mutation contracts podle dopadu, poté po poslední editaci spustí `php scripts/generate_manifest.php` a `php scripts/generate_manifest.php --check`; před handoffem spustí `php scripts/audit.php --profile=full` právě jednou. Test je plain PHP a audit runner jej objeví automaticky; není potřeba vlastní registrace. Nové UI/JS soubory musí mít standardní hlavičku, cache-busting import a zůstat bez frameworku. Tento TEMP plán je implementační podklad a před release musí být nahrazen aktualizovanou permanentní dokumentací nebo odstraněn podle release pravidel.

## Cíle a kontrolní body

| Cíl | Účel a implementace | Akceptace V1 | Další fáze |
|---|---|---|---|
| G01 Registry | Osm sekcí vzniká pouze z `admin_settings_registry()` a `admin_settings_sections()`. | Wizard pokrývá registry snapshot a každou položku označí jako editable, readonly, deferred nebo unavailable; úplná coverage je ověřována navazujícími registry/rendering kontrakty. | Všechny bezpečné owner adaptéry postupně přidají skutečnou editaci bez generického setteru. |
| G02 Draft | `admin_setup_wizard_begin_draft()`, `draft_valid()` a `stage_step()` drží owner, revision, step, changes, skips a normalized original. | Žádný saver se nespustí při průchodu, skipu ani preview; chybný krok je atomicky odmítnut. | Resume mimo běžný request pouze přes serverovou session/store s TTL a invalidací. |
| G03 Apply | `admin_setup_wizard_apply()` provede finální preflight, konfliktovou kontrolu a modelovou transakci. | Apply je možné pouze ze summary s approval `1`, platnou revizí, auth a CSRF. | Rozšířit reversible apply na další owner adaptéry a exportovat auditovatelný diff. |
| G04 Vlastníci | Controller `app/controllers/admin_setup_wizard.php`, service parts `catalog.php`, `draft.php`, `apply.php`, model `app/models/admin_setup_wizard.php`; každý adapter volá konkrétní canonical owner. | Žádný SQL ani arbitrary key setter ve view/controlleru; MVC audit bez nové baseline debt. | Přidat explicitní řádky owner adapterů pro Theme, upload, telemetry, account a maintenance. |
| G05 Lokalizace | Každý krok má beginner help a konkrétní příklad přes `en`, `cs`, `de`, `sv` katalogové klíče. | Překladové katalogy jsou pro wizard doplněny; test ověřuje skutečné klíče a fallback; ID se uživateli nezobrazují jako instrukce. | Rozšířit help/deferred vysvětlení na všech 177 položek a owner deep-linky. |
| G06 Appearance preview | Wizard znovu používá `view_render_admin_theme_live_preview` a export `setupThemeLivePreview` se stejným cached query revision `20260929` a `previewv1`; wizard vlastní revizi `v2`. | Staged změna mění pouze živý preview model, nikoli persistence; preview je dostupné pro všech 11 V1 Theme polí včetně language selectoru. | Rozšířit reuse na layout, cards, grids a media; zachovat cache-busting. |
| G07 Cesty | `base_url` je URL instalace; `galleries_root` je filesystem root pouze pro čtení. | Wizard zobrazí začátečnický příklad, validuje URL a nikdy tiše nepřesune soubory. | Recoverable migration workflow: validace, temp quarantine, checksum, TTL, explicitní potvrzení a rollback. |
| G08 Bezpečnost | Secrets jsou readonly statusy; žádné tokeny/passwordy do draftu, HTML, logu ani localStorage. Destruktivní volby pouze vysvětlují canonical route. | Secret payload je odmítnut před saverem; capability/schema `unknown` fail-closed. | Server-only ephemeral secret workflows s asset quarantine/checksum/TTL; žádná automatická destruktivní akce. |
| G09 Přístupnost | Keyboard/mobile/no-JS fallback, jasná validace, jednotlivý skip i skip celé sekce, žádné implicitní potvrzení. | Každý krok lze projít bez JS; summary rozlišuje changed, unchanged, skipped, deferred a blocked. | Browser matrix pro screen readers, touch a offline/reload resume. |
| G10 Konkurence | Revision je digit string z requestu; owner binding a optimistic conflict se kontrolují před prvním saverem. | Druhá karta dostane stale/conflict chybu a žádná změna se nezapíše; failure transaction spouští kompenzaci URL. | Per-setting merge pouze tam, kde owner výslovně definuje bezpečnou politiku. |
| G11 Regrese | Plain PHP behavior tests pokryjí model transaction stub, controller `process_post`, catalog, draft, apply a renderer hooks. | Test je deterministic, bez live DB; audit runner jej objeví automaticky. | Přidat browser fixture až když změna skutečně vyžaduje interakční coverage. |
| G12 Handoff | Root řídí waves, audit a release-manifest pravidla podle `AGENTS.md`, `ARCHITECTURE.md`, `TESTING.md`, `RELEASE.md` a `docs/ADMIN_SETTINGS_INVENTORY.md`. | Bez commitů, ZIPů, release metadata, PDF nebo tvrzení o passu bez skutečného auditu; před handoffem přesně jeden full audit. | Permanentní dokumentace nahradí TEMP plán před release; žádná nová baseline položka. |

## Přesný V1 průchod a API

Katalog `app/services/admin_setup_wizard/catalog.php` obaluje 12 centrálních položek z registry a 11 bezpečných Theme basic appearance položek: `theme_accent`, `theme_accent_dark`, `theme_paper`, `theme_panel`, `theme_gallery_panel`, `theme_header_text`, `theme_hero_text`, `theme_radius`, `theme_font`, `theme_page_width`, `theme_page_width_custom`. Dostupnost `public_home_search_enabled`, `exif_gps_maps_default_enabled` a dalších capability/schema řízených hodnot je podmíněná effective policy; `missing` může pokračovat jen dokumentovanou compatibility cestou, `unknown` apply blokuje.

Controller přijímá jen `settings[id]`, `include[id]`, `wizard_step`, digit-string `revision`, `wizard_action` z whitelistu `next/back/skip/goto/cancel/restart/apply`, `target_step` a při apply přesně `approval=1` ze summary. `admin_setup_wizard_process_post()` provádí staging před navigation změnou, zatímco `admin_setup_wizard_process_apply()` odmítne non-summary nebo chybějící approval. Stavy cancel/restart vyčistí serverový draft; nikdy nepoužívají browser localStorage pro secrets ani hodnoty credentialů.

Model `app/models/admin_setup_wizard.php` s PDO transakcí přes `admin_setup_wizard_model_transaction(settingKeys, operation, compensate)` je jediná hranice transakce. Operation musí nejprve provést čerstvý snapshot/preflight, potom canonical saver calls, a při výjimce zavolat kompenzaci. `base_url` používá reversible URL save a redirect rebase; databázové hodnoty používají explicitní owner savery. Přímé SQL, obecný setter podle uživatelského klíče a přesun souborů v apply jsou zakázány.

## Fázovaný rozsah po V1

V1 transparentně vede specializované akce na jejich canonical owner routes. Fáze 2 přidá safe adapters pro základní Theme layout/card/grid/media s preview; language selector je již součástí V1. Fáze 3 přidá upload/telemetry preference přes jejich services po schema preflight. Fáze 4 řeší credentials/API keys pouze přes serverové ephemeral flows a nikdy přes draft HTML. Fáze 5 řeší galleries-root migration jako recoverable job s karanténou, checksumem, TTL, explicitním potvrzením a rollbackem. Fáze 6 může přidat explicitní maintenance/update/database adapters; ani tehdy wizard nesmí automaticky spouštět destruktivní intent bez samostatného potvrzení vlastníka operace.

Za kompletní wizard se bude považovat až stav, kdy je každý registry item buď bezpečně editovatelný vlastním adapterem, nebo má ověřený readonly/deferred důvod, každý owner má rollback/conflict/schema kontrakt, všechny čtyři katalogy mají help+example, appearance preview pokrývá relevantní surface, no-JS cesta funguje a `docs/ADMIN_SETTINGS_INVENTORY.md` odpovídá skutečnému registry snapshotu. V1 tohoto cíle záměrně nedosahuje; jeho hranice jsou součástí acceptance review.
