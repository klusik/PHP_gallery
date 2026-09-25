# Úspornější editor galerie – UI/UX zadání (25. 9. 2026)

## Co současný návrh bolí

Editor funguje, ale běžné úpravy vyžadují zbytečně dlouhé posouvání. Vysvětlující odstavce, opakované popisky, prázdné karty a samostatné řádky pro drobné volby zabírají místo důležitějším polím. Informace je potřeba zpřístupnit na vyžádání, ne ji trvale vystavovat. Pravý panel má zůstat hlavní pracovní plochou; uložení, generování, kopírování a přepínání tabů musí fungovat bez opuštění panelu.

## Identity

- Hned viditelná pole: název, popis, datum nebo rozsah data, jazyk, SimBrief a štítky. Datum je běžný a důležitý údaj; neskrývat ho v Advanced.
- Nápovědu formátování přesunout k popisku nebo do rohu pole popisu. Malé tlačítko `?` otevře zavíratelnou nápovědu. Musí jít ovládat myší i klávesnicí, mít srozumitelný přístupný název a nezmenšovat textarea.
- Jazyk zobrazit v kompaktním řádku s již připravenými ikonami/vlajkami. Zaškrtávátko pro zapamatování jazyka patří hned vedle výběru. Předvyplněná hodnota má dál viditelné, ale nenápadné označení.
- `Other languages` ponechat jako existující samostatnou rozbalovací sekci.
- SimBrief stáhnout na jeden identifikátor: čistě číselná hodnota je Pilot ID, text je pilotní jméno. Zapamatování patří na stejný řádek jako pole. Detailní vysvětlení OFP a chování importu přesunout pod `?`; viditelné zůstanou jen pole, akce a stav. Zachovat import popisu, OFP, mapy trasy i stávající uložené výchozí hodnoty.
- Štítky ponechat. `AI gallery text` přesunout do zavřeného `Advanced gallery settings`, které nadále obsahuje slug, název složky, rodiče a další méně časté volby.

## API

- Na prvním pohledu ukázat endpoint v kopírovatelném řádku, pole popisku klíče a akci pro vygenerování. Nově vygenerovaný klíč má vlastní tlačítko pro kopírování; klíč nadále ukázat jen jednou a zachovat varování o tom, že jej později nepůjde přečíst.
- Seznam aktivních klíčů zobrazit pouze tehdy, když existuje alespoň jeden. Odvolání klíče a bezpečnostní hranice zůstávají stejné.
- `AI metadata regeneration` a migraci přesunout pod zavřenou pokročilou sekci. Jejich formuláře, potvrzení a průběh mají zůstat funkční i po otevření v dynamicky vloženém panelu.

## Access

- Visibility a password lock umístit vedle sebe do krátkého horního řádku. Dlouhé vysvětlení viditelnosti, hesla a NSFW dát do dostupné nápovědy.
- Pole pro nové heslo zobrazit tam, kde dává smysl pro zvolený režim; stav existujícího hesla a možnost jeho vymazání musí zůstat jasné.
- Share link expiry a akce odkazu mají zůstat snadno dosažitelné bez velkých prázdných karet.
- NSFW je malý checkbox se stručným názvem. Důležité vysvětlení ochrany a hostingu je dostupné přes nápovědu.

## Obecné zásady a ověření

- Nadpisy a pomocné texty používat jen tam, kde urychlují rozhodnutí. Opakovanou nápovědu skrýt do přístupného disclosure.
- Zachovat textové názvy akcí a fokus, nepoužívat samotné nepojmenované ikonky. Tlačítka pro kopírování oznamují výsledek a mají fallback, pokud Clipboard API není dostupné.
- Kompaktní rozložení se musí přelomit na úzké obrazovce; nic nesmí překrýt trvale dostupnou lištu Uložit.
- Zachovat stávající POST/JSON kontrakty, CSRF, kontroly přístupu a serverový fallback. Funkční tok galerie se nemění; jde o úpravu prezentace a nezbytnou normalizaci jediného SimBrief pole.
- Ověřit skutečný serverový HTML výstup, přepínání tabů, uložení z panelu, import SimBrief, kopírování endpointu/klíče a dynamické znovuvykreslení. Povinný finální test je `php scripts/audit.php --profile=full` a kontrola `app/core-manifest.json`.

## Stav implementace

- Identity: popis má nápovědu otazníkem u pole; datum zůstává mezi běžnými poli; jazyk využívá existující vlajky a zapamatování je přímo v řádku. SimBrief má jedno vstupní pole, jednu volbu zapamatování a nápovědu; číselný vstup se mapuje na Pilot ID, textový na jméno. Štítky a Other languages zůstaly dostupné. AI gallery text je ve výchozím stavu zavřený v Advanced gallery settings.
- API: endpoint i jednorázově zobrazený klíč mají kopírovací tlačítko, seznam aktivních klíčů se ukazuje jen s položkami. AI metadata, migrace a globální správce API jsou v zavřené pokročilé sekci.
- Access: základní volby jsou v krátkém rozložení; NSFW je malý checkbox a dlouhé popisky jsou pod otazníkem. Odkaz a datum platnosti jsou v jednom bloku. Nadpisy opakující názvy tabů byly odstraněny.
- Formulář a server dál používají stávající jména polí pro uložení, přístupové kontroly a JSON mutace. Jedno nové pole SimBrief se na hranici controlleru převádí na dosavadní ID/jméno kontrakt.
- Rychlý i úplný audit prošly. Úplný audit zahrnul 235 úspěšných PHP regresí, 24 Node regresí, syntaxi 881 PHP a 109 JavaScriptových souborů a 5 Chromium integrací. Samostatný prohlížečový scénář ověřil kopírování po novém vykreslení panelu, zachování URL a otevřeného panelu. Manifest byl znovu vygenerován a kontrola potvrdila soulad 727 souborů.

## Display – pokračování (25. 9. 2026)

- Display grid je první viditelná část tabu. V jednom kompaktním bloku zůstává vlastní mřížka, počet sloupců/řádků a dědění do podgalerií; vysvětlení dědění je pod otazníkem.
- Běžné přepínače hlasování a názvů souborů jsou v jednom krátkém řádku. Picture Game je až v pokročilém nastavení.
- Zavřené `Advanced display settings` obsahuje EXIF/GPS, formát karet, značku počtu obrázků, režim lightboxu, Picture Game, kvalitu responzivních náhledů a mapu letu. Dlouhá vysvětlení se otevírají otazníkem; kvalita náhledů a mapa letu mají vlastní podsekce.
- Všechna existující jména polí, hodnoty voleb a serverové chování ukládání jsou zachované. Panel má nadále trvale dostupnou lištu Uložit.
- Ověření: nový cílený regresní test kontroluje pořadí a dostupnost polí. Úplný audit prošel: 236 PHP regresí, 24 Node regresí, syntaxe 882 PHP a 109 JavaScriptových souborů a 5 Chromium integrací. Manifest je aktuální pro 727 souborů. Přímé vizuální spojení s prohlížečem nebylo v této relaci dostupné.
