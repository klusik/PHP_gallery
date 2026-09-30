# Cooperative galleries: spřátelené galerie a spolupracující alba

Stav: pracovní návrh k diskusi, nikoli popis hotové implementace.

Tento dokument shrnuje zamýšlené chování. Konkrétní rozhraní existujícího API a možnosti opětovného použití jeho implementace je nutné před vývojem ověřit v repozitáři. Podkladem jsou současná projektová pravidla pro API a přístup, sdílecí tokeny, WebDAV, migrace, náhledy, lightbox a administrátorský panel.

## 1. Cíl a pojmy

Dva fotografové provozují nezávislé instalace galerie na serverech A a B. Každý má vlastní album z téhož výletu a spravuje vlastní fotografie. Chtějí tato konkrétní alba propojit a případně návštěvníkům nabídnout společné prohlížení.

- **Server / instalace galerie:** samostatná instalace aplikace s vlastní databází, soubory a administrací.
- **Spřátelená galerie:** druhá instalace, se kterou bylo oboustranně schváleno trvalé spojení.
- **Album:** konkrétní galerie fotografií uvnitř instalace.
- **Spolupráce:** schválené propojení konkrétních alb spřátelených instalací.

Každá instalace i každé album zůstávají nezávislé. Spřátelení samo o sobě nezpřístupňuje všechna alba, administraci ani souborový systém.

## 2. Dvě úrovně propojení

### Spřátelení instalací

Administrátoři nejprve propojí své instalace. Během tohoto procesu si instalace bezpečně vymění a uloží trvalé API klíče určené ke vzájemné komunikaci.

Tyto klíče:

- jsou univerzální pro spolupráci dané dvojice instalací a používají se opakovaně;
- nevytvářejí se znovu při každém propojení alb;
- jsou systémově spravované a nezobrazují se v běžném přehledu uživatelských API klíčů;
- patří konkrétnímu spřátelení, nikoli všem spřáteleným serverům dohromady;
- identifikují partnerskou instalaci; konkrétní povolené operace určuje autorizace na cílovém serveru.

„Univerzální“ zde znamená opakovaně použitelný klíč pro partnerskou komunikaci. Neznamená administrátorský klíč s neomezeným přístupem ke všem současným a budoucím API operacím.

### Spolupráce alb

Teprve mezi aktivně spřátelenými instalacemi lze požádat o propojení konkrétních alb. Každý vlastník schvaluje vlastní album a rozsah jeho zpřístupnění.

Klíč odpovídá na otázku „který partner volá“. Záznam spolupráce odpovídá na otázku „ke kterému albu a jakým operacím má přístup“. Přidání dalšího alba vytváří záznam oprávnění, nikoli další API klíč.

## 3. Navázání přátelství

Navržený průběh:

1. Administrátor A zvolí „Přidat spřátelenou galerii“ a zadá adresu B.
2. A ověří identitu a kompatibilitu partnerského rozhraní B. Vznikne žádost nebo jednorázová pozvánka s omezenou platností.
3. Administrátor B uvidí identitu a adresu A a žádost výslovně přijme.
4. Instalace si přes HTTPS vymění přístupové údaje a ověří funkčnost obou směrů.
5. Teprve po dokončení ověření je přátelství aktivní. Nedokončená výměna zůstává čekající a lze ji bezpečně zopakovat nebo zrušit.

Pozvánka pouze umožňuje navázat spojení; nenahrazuje trvalý API klíč. Párování musí být svázané s konkrétní žádostí a očekávanými instalacemi, chráněné před opakovaným použitím a záměnou partnera.

Pro každé přátelství se doporučují dva nezávislé klíče:

- A vydá klíč, kterým se B autentizuje vůči A.
- B vydá klíč, kterým se A autentizuje vůči B.

Vydávající instalace ukládá ověřovací hash svého klíče. Volající instalace potřebuje protější klíč v obnovitelné podobě, proto jej ukládá šifrovaně s využitím existujícího mechanismu správy tajemství, pokud je vhodný. Klíče nesmí být v URL, HTML, JavaScriptu ani logu. Skrytí z běžného přehledu API klíčů není náhradou za kontrolu oprávnění.

V administraci „Spřátelené galerie“ se zobrazují partner, stav spojení, poslední úspěšná komunikace a akce pozastavení, rotace a odpojení. Samotné hodnoty klíčů se běžně nezobrazují.

## 4. Propojení alb

1. Vlastník otevře album A a v pravém panelu vybere spolupráci.
2. Vybere aktivně spřátelenou instalaci B a odešle nabídku propojení.
3. Oprávněný uživatel na B vybere své album a schválí nabídku.
4. Obě strany schválí konkrétní rozsah sdílení a podmínky publika.
5. Alba zobrazí partnerskou kartu a podle zapnuté úrovně také společné fotografie.

Partner nesmí pouze díky přátelství získat seznam soukromých alb. Výběr cílového alba může proběhnout až lokálně při přijetí pozvánky. Pokud se později nabídne vzdálený výběr, obsahuje pouze alba výslovně nabídnutá ke spolupráci.

Oprávnění jsou směrová. A povoluje čtení svého alba B a B samostatně povoluje čtení svého alba A. Jednotná oboustranná spolupráce může tyto dva souhlasy spojovat do jednoho uživatelského workflow.

Možný rozsah zahrnuje čtení potřebných metadat, zobrazování náhledů, prohlížení fotografie a samostatně stahování originálů. První verze neumožní vzdálené úpravy ani mazání.

## 5. Podoby spolupráce

### Partnerská alba

Album zobrazí kartu partnerského alba s autorem, titulním obrázkem a odkazem. Fotografie se prohlížejí na původním serveru. Jde o první použitelnou etapu.

### Společné prohlížení

Fotografie obou alb se zobrazí v jedné mřížce a současném lightboxu. Návštěvník může filtrovat všechny fotografie nebo konkrétního autora. Každá fotografie má jasně uvedený původ.

Společný pohled může řadit fotografie podle času pořízení. Volitelná korekce času jednotlivého autora kompenzuje špatně nastavené hodiny fotoaparátu pouze pro tento pohled a nemění EXIF. Je potřeba určit pravidla pro chybějící čas a časová pásma.

### Volitelné kopírování

Pozdější samostatná funkce může umožnit import nebo replikaci. Vyžaduje výslovné povolení kopírování a vlastní pravidla synchronizace, mazání a dalšího uchování. Není součástí první verze ani automatickým důsledkem přátelství.

## 6. Vlastnictví dat

Každá fotografie má právě jeden zdrojový server, který rozhoduje o jejím obsahu, metadatech a dostupnosti. Partner drží vzdálené reference a případně povolenou dočasnou cache.

Identita vzdáleného objektu musí zahrnovat stabilní identitu instalace a identifikátor objektu. Samotné číselné ID fotografie ani samotná URL nestačí. Změna domény nebo obnova serveru vyžaduje definovaný proces zachování či změny identity.

- Změny popisků a fotografií se přebírají od vlastníka.
- Odstraněné či nově nepřístupné fotografie mizí ze společného pohledu.
- Odpojení spolupráce nemaže původní fotografie žádné strany.
- Propojení A–B a B–C nevytváří oprávnění A–C. API neposkytuje partnerem převzaté fotografie jako vlastní obsah.
- Jedno album může být později zapojeno do více spoluprací s nezávislými souhlasy.

Společné komentáře, hlasování, editace a globální uživatelské účty jsou mimo první rozsah. Jejich případné přidání potřebuje rozhodnutí o vlastnictví a identitě uživatelů.

## 7. Publikum a chráněná alba

Autentizace partnerského serveru a oprávnění koncového návštěvníka jsou dvě různé věci. Trvalý klíč nesmí automaticky obejít viditelnost, heslo, NSFW ochranu ani další pravidla zdrojového alba.

Vlastník musí vědět, zda povoluje veřejné publikování, nebo zpřístupnění konkrétnímu chráněnému publiku partnerského alba. Změna partnerského alba ze soukromého na veřejné nesmí automaticky rozšířit dříve udělený souhlas.

První ověřovací implementace má pracovat s veřejnými alby a explicitním souhlasem se společným veřejným zobrazením. Chráněná spolupráce bude následovat až po volbě modelu předávání a ověřování oprávnění návštěvníka. Samotné přátelství takovým modelem není.

Obě instalace musí v okamžiku požadavku ověřit odpovídající lokální pravidla. Při neznámém bezpečnostním stavu se obsah nezpřístupní. Je třeba sladit tento proces se současnou tříhodnotovou schema policy a centrálními přístupovými službami.

## 8. Přenos médií a aktualizace

Před implementací je třeba zvolit způsob přenosu médií:

- **Přímý přenos od vlastníka:** menší zátěž pro zobrazující server; pro chráněná média vyžaduje vhodné omezené oprávnění a řešení jeho expirace. Prohlížeč komunikuje i s partnerským serverem.
- **Přenos přes místní server:** umožňuje navázat místní autorizaci a cache, ale zvyšuje přenosovou zátěž a vyžaduje bezpečně omezené vzdálené požadavky.

Trvalý partnerský API klíč nikdy neopouští server do prohlížeče. Možné krátkodobé oprávnění pro médium není novým trvalým API klíčem pro album.

Seznamy fotografií se stránkují a ukládají do omezené cache. První verze může používat intervalovou obnovu a ruční aktualizaci, bez povinného trvale běžícího procesu. Pro spolehlivé načítání změn je třeba navrhnout revize nebo kurzory, včetně informace o odstraněných položkách; webhooky lze doplnit později.

Výpadek partnera nesmí zablokovat vlastní fotografie ani dlouze zdržovat vykreslení alba. Vzdálená část zobrazí stav dostupnosti. Cache chráněných médií má výslovně omezenou platnost a nesmí prodlužovat oprávnění bez definovaného ověření.

Odvolání přístupu ihned zastaví nové autorizované požadavky na vydávajícím serveru. Dobu platnosti již vydaných oprávnění a pravidla smazání partnerské cache je nutné stanovit. Již stažené kopie nelze technicky vzít zpět.

## 9. API a ochrana spojení

Nejdříve prověřit stávající API a rozšířit jeho vhodné autentizační, autorizační a čtecí mechanismy. Nevytvářet druhou implementaci stejného načítání fotografií.

Potřebné schopnosti rozhraní:

- identita instalace, verze protokolu a podporované možnosti;
- žádost, přijetí a dokončení spřátelení;
- ověření, rotace a odvolání partnerského oprávnění;
- nabídka, přijetí, pozastavení a zrušení spolupráce alb;
- čtení schválených metadat, stránek fotografií a změn;
- autorizovaný přístup k povoleným variantám médií.

Konkrétní názvy endpointů zatím nejsou stanovené. Protokol musí tolerovat rozdílné verze aplikace a opakované doručení požadavku bez vytváření duplicitních vazeb.

Vzdálené požadavky musí mít limity času, velikosti odpovědí a počtu položek. Ověřovat TLS, cílové adresy, DNS a přesměrování tak, aby zadání partnera neumožnilo čtení interních služeb serveru. Vzdálené texty a metadata jsou nedůvěryhodné vstupy. Omezit četnost požadavků a logovat pouze bezpečné identifikátory a stav, nikdy klíče nebo citlivé URL.

## 10. Zapojení do existující architektury

- **Modely:** persistence přátelství, vydaných oprávnění, vazeb alb a synchronizačního stavu; SQL pouze zde.
- **Služby:** párování, partnerská komunikace, autorizace spolupráce, obnovování dat a práce s cache.
- **Controllery:** HTTP, autentizace a CSRF, normalizace požadavků, odpovědi a příprava dat pro pohledy.
- **Views:** zobrazení připravených partnerských karet a fotografií bez vlastního objevování doménové politiky.

Nové tabulky a sloupce zavést timestampovanými migracemi. Definitivní datový model určit po kontrole současných vlastníků tokenů, API a galerií. Vztahy potřebují stabilní ID, stav, schválený rozsah, revizi a časové údaje; cache ani lokální číselné ID nejsou zdrojem identity partnera.

Případnou schopnost spolupráce zapojit do kanonického capability registru, jeho závislostí a vlastnictví rout. Vypnutí musí být nedestruktivní: uložená přátelství a vazby zůstanou zachované, komunikace a přístup přes tuto funkci se zastaví. Nevytvářet paralelní nastavení pro již existujícího vlastníka.

Mutace v pravém Admin panelu probíhají přes stávající AJAX cestu, zachovávají otevřený panel i URL a vracejí kanonický mutation envelope. Aktualizace stránky využívá sdílený koordinátor dokončení mutací.

Společné prohlížení zachová oba thumbnail renderery, jejich no-JavaScript chování a současný lightbox. Vzdálené fotografie dostanou připravený datový formát slučitelný s existujícím zobrazením. Zvětšení nad 100 % musí respektovat současný požadavek okamžitého přiřazení autorizovaného originálu; pokud spolupráce originál nepovoluje, je potřeba předem určit odpovídající dostupnost zoomu, nikoli ochranu obejít.

WebDAV a migrační mechanismy prověřit jako možné stavební kameny pozdějšího kopírování, nikoli jako automatickou závislost pouhého prohlížení.

## 11. Životní cyklus a odpojení

Spřátelení může být čekající, aktivní, pozastavené nebo odvolané. Spolupráce alb má vlastní stav a nezávislé souhlasy.

- Odpojení jednoho alba neovlivní ostatní spolupráce ani partnerský klíč.
- Pozastavení přátelství zastaví všechny jeho spolupráce bez smazání původních dat.
- Odvolání přátelství lokálně zneplatní vydaný klíč a zastaví použití uloženého protějšího klíče. Oznámení partnerovi je pokus s opakováním, ne podmínka lokálního zablokování.
- Rotace klíče zachová vazby alb a bezpečně dokončí výměnu bez ručního vytváření oprávnění pro každé album.
- Opětovné spřátelení nesmí bez stanoveného potvrzení automaticky oživit dříve odvolané souhlasy.

## 12. Doporučené etapy

1. **Prověření současné implementace:** API, tokeny, přístupová politika, identifikátory fotografií, bezpečný HTTP klient a cache.
2. **Spřátelené galerie:** párování, systémové klíče mimo běžný seznam, oboustranné ověření, stav, pozastavení, rotace a odvolání.
3. **Partnerská alba:** nabídka a schválení vazby mezi veřejnými alby, partnerské karty a oddělená oprávnění.
4. **Společné prohlížení:** stránkovaný seznam, autoři, společné řazení, náhledy, lightbox a chování při výpadku.
5. **Chráněná spolupráce:** explicitní model návštěvnických oprávnění, cache a odvolání přístupu.
6. **Volitelná rozšíření:** webhooky, korekce času, import a následně případné komentáře či hlasování. Více účastníků a jednomyslné rozšíření patří již do základního modelu a etapy partnerských alb.

## 13. Co ověřit při implementaci

Regrese mají pokrýt především izolaci přátelství a jednotlivých alb, zákaz implicitního přístupu k dalším albům, nepřenosnost oprávnění A–B–C, zamítnutí čekajícího nebo odvolaného spojení, opakované párování, rotaci, změny viditelnosti, expiraci médií a výpadky partnera.

UI ověření zahrne dynamicky znovu vykreslené ovládání panelu bez navigace, oba renderery a autorizované chování lightboxu. Vývoj a závěrečné ověření se řídí centrálním `scripts/audit.php` podle AGENTS.md; před předáním změn spravovaných aplikací se obnoví a ověří release manifest.

## 14. Otevřená rozhodnutí

- Kdo smí schvalovat přátelství instalací a kdo spolupráci konkrétního alba?
- Jaké současné API lze rozšířit a jak odlišit systémové partnerské klíče od uživatelských?
- Budou média přenášena přímo, přes místní server, nebo podle schváleného režimu?
- Jaké publikum lze schválit pro chráněnou spolupráci a jak je zdrojový server ověří?
- Jak dlouho mohou platit cache a krátkodobá mediální oprávnění?
- Jak se zachová identita instalace při změně domény, klonování nebo obnově zálohy?
- Bude společný pohled součástí obou původních alb, nebo volitelně samostatnou stránkou výletu?

Základní dohoda návrhu: nejprve oboustranné spřátelení instalací s opakovaně používanými systémovými API klíči; teprve potom výslovně schválená propojení jednotlivých alb. Pro každé nové propojení se nové trvalé API klíče nevyrábějí.

## 15. Skupinová spolupráce: A, B, C a další účastníci

Tato část upřesňuje předchozí návrh: spolupráce je od začátku skupina účastníků, nikoli pouze dvojice alb. V první verzi každá instalace připojuje do jednoho výletu jedno vlastní album. Všichni aktivní účastníci musí být navzájem aktivně spřátelení a schválit stejné složení skupiny. Nejde o tranzitivní předávání klíčů nebo oprávnění. Pozvánka nabídne doplnění chybějících přímých přátelství.

### Příklad přidání C do spolupráce A+B

1. B pozve C do konkrétního výletu. Tím B souhlasí s tímto návrhem rozšíření.
2. C přijme pozvánku, vybere vlastní album a schválí spolupráci s A i B v uvedeném rozsahu.
3. A schválí přidání C a zároveň přátelství A–C, pokud neexistuje.
4. C rovněž schválí chybějící přátelství s A. Systém dokončí bezpečné párování a ověří oba směry spojení.
5. Až po všech souhlasech a dokončeném spřátelení se aktivuje složení A+B+C.

Uživatel může oba souhlasy spojit do jednoho potvrzení: „Přijmout C mezi přátele a přidat jeho album do výletu.“ Backend eviduje přátelství a souhlas se spoluprací odděleně. Pokud vlastník alba nesmí schvalovat přátelství instalace, tuto část musí potvrdit administrátor. Pozvánka zatím čeká.

Při pozvání D souhlasí A, B, C i D. Existující přátelství a jejich klíče se znovu použijí; párují se jen chybějící vztahy. Souhlas s přátelstvím nikdy sám o sobě nenahrazuje souhlas s konkrétním výletem. Účastník neuděluje přístup svým dalším přátelům.

### Čekající návrh a přesný rozsah souhlasu

Původní A+B fungují během schvalování dál. C má stav „Čeká na schválení“ a získá jen nezbytné informace k přijetí pozvánky. Přes pozvánku nezískává fotografie A ani B a jeho fotografie se zatím nezobrazují ve společném pohledu. Odmítnutí, zrušení nebo expirace pozvánky nemění původní spolupráci.

Souhlas se váže na neměnný návrh obsahující identitu výletu, výchozí revizi členství, přesné identity instalací a alb, rozsah sdílení a pravidla publika. Nelze po schválení pod stejný návrh přidat dalšího člena, jiné album nebo širší oprávnění. Taková změna vyžaduje nový návrh a nové odpovídající souhlasy.

První verze dovolí nejvýše jeden otevřený návrh rozšíření na výlet. Další pozvánka počká na dokončení nebo zrušení předchozí. Souhlas zvoucího platí pouze pro oprávnění, která smí sám udělit. Úspěšně schválené přátelství zůstane samostatným vztahem i při pozdějším zrušení pozvánky; UI to vysvětlí a umožní jeho běžné odpojení.

### Koordinace bez přenosu cizích oprávnění

Zakládající instalace koordinuje návrhy a shromažďuje potvrzení. Nemůže souhlasit za ostatní ani udělit přístup k jejich fotografiím. Každé potvrzení musí být ověřitelné jako rozhodnutí příslušné instalace. Konkrétní způsob přímého ověření nebo podpisů se určí při návrhu protokolu; samotné tvrzení koordinátora nestačí.

Každý server uchová poslední ověřenou revizi spolupráce. Novou revizi přijme až po ověření všech souhlasů a připravenosti potřebných přátelství. Zpracování potvrzení i aktivace musí být idempotentní.

Aktivace nemůže být na nezávislých serverech dokonale současná. Při částečném doručení některý server ještě zobrazuje starý stav. Požadavky proto identifikují revizi spolupráce; server bez ověřené nové revize odmítne nový přístup do dokončení synchronizace. Nesoulad nesmí vést k širšímu oprávnění. Nový účastník nesmí získat přístup před získáním celého souboru souhlasů.

Při výpadku koordinátora pokračují již schválené přímé přístupy podle platnosti oprávnění, ale změny členství čekají. Fotografie se nepřenášejí přes koordinátora jen proto, že koordinuje členství. Převod role koordinátora zůstává samostatnou otázkou pro pozdější návrh.

### Odchod a přerušení přátelství

- Účastník může jednostranně odejít nebo odvolat sdílení svého alba. Zastavení jeho vlastního sdílení nečeká na hlasování ostatních.
- Odchod nemaže fotografie a neruší přátelství. Zbývající účastníci mohou pokračovat; při jediném zbývajícím účastníkovi zůstává pouze místní album.
- Zrušení nebo pozastavení přátelství A–C lokálně ihned zablokuje odpovídající přístup. Po zjištění se společné sdílení této skupiny pozastaví, protože podmínka vzájemného přátelství již neplatí. Lokální alba i jiné nedotčené spolupráce pokračují.
- B nesmí zákaz obejít poskytováním převzatých fotografií A serveru C ani opačně. API exportuje pouze vlastní obsah v povoleném rozsahu.
- Obnovení sdílení vyžaduje obnovené přátelství a potvrzení dotčených souhlasů, nebo potvrzené zúžení skupiny. Vyloučení jiného účastníka vyžaduje souhlas všech zbývajících účastníků; každý může mezitím odvolat vlastní sdílení.
- Změny se šíří opakovatelnými oznámeními a ověřováním stavu. Odpojený server se o odvolání nemusí dozvědět okamžitě. Maximální prodlevu musí omezit platnost oprávnění a cache; již stažené soubory nelze odvolat.

### Datový model a ověření

Vedle párových přátelství a směrových oprávnění potřebujeme záznam skupiny, účastníky s připojenými alby, revize členství, neměnné návrhy změn a jednotlivé souhlasy. Přesná schémata se navrhnou po prověření stávajících modelů. Koordinátor není vlastníkem cizích alb ani zdrojem jejich přístupové politiky.

Regrese pokryjí přidání C do A+B, chybějící souhlas A nebo C, chybějící přátelství A–C, odmítnutí bez narušení A+B, expiraci, změnu návrhu po schválení, duplicitní a opožděné potvrzení, nesoulad revizí, částečnou aktivaci, výpadek koordinátora, odchod člena a zrušení jedné hrany přátelství. Kontroly se zapojí do centrálního auditu podle pravidel projektu.

Zvolený princip: všichni účastníci jsou navzájem přátelé; rozšíření potvrzuje nový účastník i všichni dosavadní. Systém automatizuje technické párování, nikoli souhlas lidí.

## 16. Implementovaný fundament

Vznikl samostatný doménový modul a migrace základních úložišť. Rozhraní, bezpečnostní
hranice a zbývající integrační práce popisuje [docs/COOPERATIVE_GALLERIES.md](docs/COOPERATIVE_GALLERIES.md).
Na původní modely a služby navazuje backend spřátelení popsaný níže; UI a síťová
koordinace spolupráce konkrétních alb zatím nejsou zapojené.
Souhlasy se vážou také na generace přátelství. Obnovení vztahu proto samo neoživí staré
oprávnění. Pro nové odsouhlasení lze navrhnout stejné složení skupiny znovu.
Pokud odejde koordinátor, fundament skupinu pozastaví; bezpečný převod koordinace
je samostatný integrační krok, který zatím není implementován.

## 17. Backend spřátelení připravený pro UI

Je připravené vytvoření cílené jednodenní pozvánky, její import bez souhlasu, výslovné
přijetí, obousměrné ověření a výměna dvou systémových klíčů mezi servery. Uživatel
přenáší pouze pozvánkový kód. Klíče se nezobrazují v běžné správě API ani ve stavových
odpovědích pro UI. Spřátelení v této etapě schvalují administrátoři instalací.

Nová capability `cooperative_galleries` je výchozím stavem vypnutá. Backend obsahuje
migrační úložiště, bezpečný HTTPS transport, peer endpoint a Admin endpointy pro stav
a akce s CSRF a standardní mutation obálkou. Vyžaduje explicitní kanonickou HTTPS
adresu instalace, veřejné IPv4, cURL a dostatek PHP workerů pro zpětné ověření.

Rozpracovaná výměna a oznámení odpojení přežijí ztracenou odpověď. Opakování vychází
z uloženého stavu a používá původní klíče; vyvolává se explicitní akcí retry s prodlevou.
Místní odpojení zruší přístup před síťovým voláním. Je pokryté i souběžné odpojení a nové
spřátelení zrušeného vztahu s novou pozvánkou a vyšší generací klíčů. Omezená místní
revokace může fungovat i bez dostupného šifrovaného recovery úložiště.

Pro UI jsou připravené povolené akce, bezpečné stavové projekce, revize, expirace,
stránkování, údaje o opakování a stabilní chybové kódy. Přesný kontrakt je v
[docs/COOPERATIVE_GALLERIES.md](docs/COOPERATIVE_GALLERIES.md). Další vrstva doplní
panely a zvlášť síťovou koordinaci schvalování konkrétních alb A+B+C; samotné přátelství
nezpřístupní žádné fotografie.

## 18. UI spřátelených galerií

Správa přátelství je dostupná přes nastavení funkce a globální Settings. Odkaz na kartě
funkce otevírá současný pravý panel. UI nabízí vytvoření pozvánky, její import bez souhlasu,
samostatné přijetí, stav vztahu, ruční opakování výměny a odpojení. Zobrazuje také expiraci
a čas dalšího pokusu. Všechny akce s JavaScriptem probíhají na místě, i po překreslení panelu.
Bez JavaScriptu fungují stejné formuláře jako běžné POST požadavky s HTML výsledkem.

Chyba zachová zadání a obnovení stavu ponechá identitu rozpracované pozvánky. Opožděné
výsledky nepřepíšou jiný nebo znovuotevřený panel. Kódy pozvánek se nepřenášejí v URL ani
neukládají do prohlížečového úložiště. Pro UI jsou doplněné české, anglické, německé a švédské
texty a izolované prohlížečové regrese. Síťové schvalování konkrétních alb A+B+C a jejich
společné zobrazení zůstává další etapou.

## 19. Výběr místního alba a zdrojová politika

Pro navazující albumové UI je připravený pouze čtecí Admin endpoint
`admin_cooperative_albums`: stránkuje místní alba a uvádí, zda je lze vybrat,
případně důvod odmítnutí. Nevytváří identifikátory a nekomunikuje s přáteli.

Příprava člena neměnného návrhu nově ověřuje aktuální veřejnost alba i jeho předků,
heslo, NSFW a úplnost schématu. Přihlášení administrátora ani odemčená návštěvnická
session tyto podmínky nenahrazují. Teprve potom připraví stabilní neveřejný lokální
mapovací záznam a veřejné neprůhledné ID pro návrh. Vznik ID není souhlas se sdílením.
Při následném souhlasu a exportu se musí zdrojová politika znovu ověřit.

Jsou pokryté změny ochrany po přípravě ID, smazání alba, chybějící rodič, cyklus,
odmítnutí při neznámém schématu a stránkování bez zápisů. Síťový protokol souhlasů,
skupinové UI a společné zobrazení fotografií nadále čekají na implementaci.

## 20. Síťové předávání prvních návrhů a přímé souhlasy

Je připravený backend pro první neměnný návrh propojení dvou až 32 alb, tedy i pro
počáteční A+B+C. Koordinátor předává přesný uložený návrh jednotlivým přátelům.
Každý server schvaluje pouze vlastní účast a při dotazu vrací pouze své rozhodnutí.
Import ani vytvoření návrhu nepředstavují automatický souhlas. Chybějící přímé
přátelství brání místnímu schválení; rozhodnutí jsou vázaná také na generace vztahů.

Admin API nabízí přípravu reference alba, vytvoření návrhu, přijetí/odmítnutí,
jednotlivé doručení a přímé zjištění rozhodnutí. Existuje stránkovaná místní schránka
návrhů. Opakované doručení nemění obsah ani dřívější odmítnutí; ztracenou odpověď lze
znovu vyžádat bez vytvoření jiného návrhu. Souběžné odmítnutí nebo změna přátelství
zablokují zápis zastaralé síťové odpovědi.

Jde zatím pouze o první návrhy. Síťová aktivace spolupráce, přidání C do již aktivního
A+B, úplné ověření všech hran a šíření odvolání přístupů zůstávají další etapou.
Ani souhlasné výsledky všech dotazů v této vrstvě skupinu neaktivují. Albumové UI
ani veřejné API fotografií zatím nejsou zapojené. Detailní kontrakt je v
`docs/COOPERATIVE_GALLERIES.md`; databázová migrace této etapy není potřeba.

## 21. Ověření celé skupiny a lokální aktivace

Je doplněné samostatné čerstvé ověřovací kolo. Každá instalace kontaktuje všechny
ostatní účastníky přímo, s jednorázovou výzvou vázanou na přesný návrh. Z potvrzení
obou stran každého přátelství sestaví kompletní graf; běžné uložené výsledky dotazů
ani tvrzení koordinátora k aktivaci nestačí.

Po dokončení kola lze místně aktivovat první revizi spolupráce. Oprávnění pro místní
metadata platí nejvýše 120 sekund od začátku ověřování. Samotné členství přetrvává,
ale bez nového úplného kola a dokončení obnovy se po expiraci zdrojová metadata
odmítnou. Automatická obnova a albumové UI ještě nejsou zapojené. Ověření probíhá
po jednotlivých účastnících; SQL transakce nečeká na síť.

Lokální odvolání souhlasu nebo přátelství zablokuje místní přístup ihned. Zjištěné
vzdálené odvolání zneplatní oprávnění i rozpracované důkazy; dosud nezjištěné odvolání
je omezené expirací 120sekundového oprávnění. Nejde o okamžité globální oznámení.
Změněné generace přátelství nemohou samovolně obnovit starý aktivní graf.

Je připravená autorizační funkce pro místní metadata s kontrolou klíče, člena,
přesného alba, revize, aktuální zdrojové politiky a platného ověření. Veřejný HTTP
exportér, fotografie, UI a rozšíření již aktivního A+B o C zůstávají dalšími kroky.
Záznamy využívají existující JSON úložiště skupin, takže není potřeba nová migrace.

## 22. Řízení ověřování a obnova z plánovače

Admin API nyní nabízí akci `advance`, která podle uloženého stavu provede jeden další
krok: zahájení kola, ověření konkrétního účastníka nebo dokončení aktivace. Stav vrací
pokyn pro další postup a případné čekání. Jedna akce kontaktuje nejvýše jeden server.
Souhlas se nikdy nevytváří automaticky a původní jednodenní lhůta pozvánky se nemění.

CLI `scripts/cooperative_renew.php --group=ID` obnovuje pouze již aktivní spolupráci.
Při spuštění jednou za minutu na každé instalaci obnovuje oprávnění v poslední minutě
jeho platnosti. Čerstvé oprávnění nepřepisuje; rozpracované kolo umí obnovit po pádu.
Po negativní odpovědi čeká nejméně 30 sekund, po chybě spojení nebo konfliktu skončí.
Následující běh načte skutečný uložený stav. Expirace nikdy nepovolí zastaralý přístup.

Obnova stále na dobu ověřování pozastavuje místní přístup. Pomalé nebo nedostupné
servery mohou způsobit výpadek; automatizace toto omezení neobchází. CLI se musí
nasadit do plánovače zvlášť pro každou skupinu a instalaci; žádnou úlohu nyní do
živého plánovače nezapisujeme. UI alb, e-mailové pozvánky a veřejný export zůstávají
navazující prací.

## 23. Přehled návrhů a schvalování v panelu

V Advanced Settings a ve správě přátelství je dostupná Spolupráce alb. Přehled ukazuje
místní album, účastníky, reference alb, navržená oprávnění a stav souhlasů. Uložené
vzdálené rozhodnutí uvádí čas zjištění; schválené členství a aktuální technické ověření
jsou oddělené stavy. Otevření přehledu nic neschvaluje ani neposílá.

Panel umožňuje výslovně schválit nebo odvolat souhlas, odeslat návrh jednotlivému
účastníkovi, zjistit jeho rozhodnutí a pokračovat o jeden krok v ověřování. Všechny
akce zachovávají otevřený panel a URL, fungují po překreslení a mají HTML fallback.
Tvorba návrhu pomocí výběru alb, e-mailové pozvánky a veřejné fotografie zůstávají
navazující prací. Ověřování v panelu zatím pokračuje po jednotlivých kliknutích;
automatickou obnovu aktivních spoluprací zajišťuje připravená CLI úloha.

## 24. Výběr alb a tvorba návrhu spolupráce

Každý správce může vybrat místní veřejné album a připravit kód reference. Koordinátor
vybere své album a vloží kódy ostatních účastníků, každý na samostatný řádek. Vznikne
neměnný návrh pouze pro metadata, bez automatického souhlasu nebo síťového doručení.
Reference není přístupový klíč ani pozvánka a neexpiruje jako návrh; jednodenní lhůta
začíná až vytvořením návrhu. Všichni se nadále musí spřátelit a výslovně souhlasit.

Výběr alb je stránkovaný. Panel zachová rozepsaná data i identifikátor požadavku při
obnovení, stránkování a přípravě reference v sousedním formuláři. Opakování po ztracené
odpovědi vrátí stejný návrh. Úspěšné vytvoření resetuje pouze dokončený formulář.
Kódy se neukládají do URL ani browserového úložiště. E-mailové rozesílání a fotografie
nejsou součástí této etapy.

## 25. Minimální metadata endpoint pro schválené účastníky

Je připravený POST endpoint `cooperative_metadata_api` pro název veřejného alba.
Vyžaduje přímý systémový klíč, přesnou skupinu, album a revizi a platné krátkodobé
ověření. Soukromé, skryté, heslové nebo NSFW album ani chráněný předek neprojde.
Před i po načtení názvu se znovu kontroluje zdrojová politika a oprávnění.

Odpověď obsahuje pouze neprůhledné ID alba, omezený název, korelaci požadavku a čas
konce již platného oprávnění. Nevrací fotografie, jejich adresy, interní ID, cesty,
klíče ani další syrová pole databáze. Endpoint nic neobnovuje, nezapisuje a sám
nikam nevolá. Čtení metadat klientem a společné zobrazení fotografií zůstávají
navazujícími kroky. Testování používá pouze syntetická alba v paměti.

## 26. Dokončená veřejná varianta spolupráce

Aktuální tok zahrnuje spřátelení, výběr alb, výslovné oprávnění k miniaturám
a náhledům, neměnný návrh, souhlasy všech účastníků, doručení a ověření jedním
spuštěním v panelu, e-mailové pozvánky a společné prohlížení. E-mail odkazuje
do administrace příjemce, používá nastavení Účtu a původní jednodenní lhůtu návrhu.

Společná stránka je dostupná z panelu a místního alba. Zobrazuje název a původ
každého alba, stránkuje fotografie a izoluje výpadky zdrojů. Zdroj vydává pouze
krátkodobé odkazy na existující miniatury a náhledy. Při každém čtení kontroluje
souhlas, přátelství, revizi, veřejnost, heslo i NSFW. Originály se neposílají.
Načtení může po jednotlivých krocích obnovit technické ověření, takže pro běžné
prohlížení není nutné předem nastavovat plánovač.

Rozšíření aktivního A+B o C vytváří nový samostatný návrh A+B+C se zachováním
dosavadních alb. B jej může založit, ale A, B i C musejí znovu souhlasit a všichni
musejí mít přímá přátelství. Staré A+B dál funguje, dokud z něj účastníci výslovně
nevystoupí. Nový návrh má vlastní společný odkaz; nejde o změnu existující skupiny
na místě. Fungující spolupráce je tak zachována během čekání na další souhlasy.

Odvolání souhlasu ihned zablokuje místní zdroj a panel rozešle přímá oznámení,
která ostatním zruší technické oprávnění. Neúspěšná oznámení lze opakovat.
Nedostupné servery omezí nejvýše 120sekundová expirace.

Lokální testy používají oddělené syntetické instalace, zachycené e-maily a Chromium.
Ostré HTTPS servery, skutečné SMTP doručení a MySQL nasazení nebyly k dispozici;
živá galerie se nemění. Podrobnosti jsou v docs/COOPERATIVE_GALLERIES.md.
Starší oddíly tohoto TEMP souboru popisují etapy; aktuální stav určuje tento
oddíl a trvalá dokumentace.
