# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/translation_catalog/report_translation_extensions.py
# Module Type: Windows Tooling Localization Catalog
# Purpose: Apply report and comparison label overrides.
# Responsibilities:
#   - Keep report presentation vocabulary separate from its rendering logic.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Apply report and comparison label overrides."""

def apply_report_translation_extensions(translations: dict[str, dict[str, str]]) -> None:
    """Apply the maintained translation overrides for this interface area.

    Args:
        translations (dict[str, dict[str, str]]): Ordered language maps to extend.

    Returns:
        None: Adds the catalog entries in their original declaration order.
    """
    translations["en"].update({
        "simbrief_load": "Load from SimBrief",
        "simbrief_load_quick": "Load SimBrief",
        "simbrief_identity": "SimBrief pilot ID or username",
        "simbrief_loading": "Loading the latest SimBrief OFP…",
        "simbrief_loaded": "OFP loaded. Re-create PDFs to include the plan.",
        "simbrief_retry": "The flight changed or report creation started. Load the OFP again.",
        "simbrief_confirm": "Attach this latest OFP to the selected saved flight? Confirm that it belongs to this flight.",
        "simbrief_planned": "SimBrief planned flight",
        "simbrief_passengers": "Planned passengers",
        "simbrief_fuel": "Planned ramp fuel",
        "simbrief_evidence": "Dispatch plan, not measured onboard data. Route points come from this OFP's navlog.",
        "simbrief_file": "Attach OFP file…",
        "simbrief_identity_hint": "Enter a pilot ID or username. It is saved only after the OFP loads successfully.",
        "simbrief_identity_invalid": "Enter a valid pilot ID or username (up to 128 characters).",
        "simbrief_cancel": "Cancel",
        "simbrief_ready": "✓ SimBrief loaded",
        "simbrief_unload": "Unload SimBrief",
        "simbrief_next_flight": "Next flight",
        "simbrief_staged_loaded": "OFP is loaded. Finish or re-create PDFs to use it automatically for that flight.",
        "simbrief_not_attached": "No OFP attached to this flight",
        "simbrief_available_for_report": "Loaded OFP available when creating PDFs",
        "simbrief_use_staged": "Use the loaded OFP for this saved flight and its new PDFs? Confirm that the route belongs to this flight. Yes attaches this plan; No keeps the flight's current plan; Cancel stops generation.",
        "simbrief_position_legend": "Solid: recorded · Purple dashed: OFP at recorded positions",
        "simbrief_comparison_unavailable": "OFP comparison unavailable: insufficient matching data",
        "simbrief_loading_short": "Loading SimBrief…",
        "pdf_dependency_missing": "PDF component missing: {module}. Install the source dependencies or repair the app, then retry. Saved flights remain available.",
    })
    translations["cs"].update({
        "simbrief_load": "Načíst ze SimBriefu",
        "simbrief_load_quick": "Načíst SimBrief",
        "simbrief_identity": "SimBrief ID pilota nebo uživatelské jméno",
        "simbrief_loading": "Načítám nejnovější OFP ze SimBriefu…",
        "simbrief_loaded": "OFP načteno. Plán zahrnete vytvořením nové verze PDF.",
        "simbrief_retry": "Let se změnil nebo začalo vytváření reportů. Načtěte OFP znovu.",
        "simbrief_confirm": "Přiřadit tento nejnovější OFP k vybranému uloženému letu? Potvrďte, že k němu patří.",
        "simbrief_planned": "Plán letu ze SimBriefu",
        "simbrief_passengers": "Plánovaný počet cestujících",
        "simbrief_fuel": "Plánované palivo na stojánce",
        "simbrief_evidence": "Údaje letového plánu, nikoli měření na palubě. Body trasy pocházejí z navlogu tohoto OFP.",
        "simbrief_file": "Přiřadit soubor OFP…",
        "simbrief_identity_hint": "Zadejte ID pilota nebo uživatelské jméno. Uloží se až po úspěšném načtení OFP.",
        "simbrief_identity_invalid": "Zadejte platné ID pilota nebo uživatelské jméno (nejvýše 128 znaků).",
        "simbrief_cancel": "Zrušit",
        "simbrief_ready": "✓ SimBrief načten",
        "simbrief_unload": "Odpojit SimBrief",
        "simbrief_next_flight": "Příští let",
        "simbrief_staged_loaded": "OFP je načteno. Ukončení letu nebo nové vytvoření PDF jej automaticky použije pro daný let.",
        "simbrief_not_attached": "K tomuto letu není přiřazeno OFP",
        "simbrief_available_for_report": "Načtené OFP lze použít při vytváření PDF",
        "simbrief_use_staged": "Použít načtené OFP pro tento uložený let a jeho nová PDF? Potvrďte, že trasa patří k tomuto letu. Ano přiřadí tento plán; Ne ponechá současný plán letu; Zrušit zastaví generování.",
        "simbrief_position_legend": "Plná: záznam · Fialová přerušovaná: OFP dle zaznamenané polohy",
        "simbrief_comparison_unavailable": "OFP nelze porovnat: chybí odpovídající údaje",
        "simbrief_loading_short": "Načítám SimBrief…",
        "pdf_dependency_missing": "Chybí součást pro PDF: {module}. Doplňte závislosti zdrojové aplikace nebo opravte instalaci a zkuste to znovu. Uložené lety zůstávají dostupné.",
    })
    translations["de"].update({
        "simbrief_load": "Aus SimBrief laden",
        "simbrief_load_quick": "SimBrief laden",
        "simbrief_identity": "SimBrief-Piloten-ID oder Benutzername",
        "simbrief_loading": "Neuester SimBrief-OFP wird geladen…",
        "simbrief_loaded": "OFP geladen. PDFs neu erstellen, um den Plan einzubeziehen.",
        "simbrief_retry": "Flug geändert oder Berichterstellung gestartet. OFP erneut laden.",
        "simbrief_confirm": "Diesen neuesten OFP dem ausgewählten gespeicherten Flug zuordnen? Zugehörigkeit bestätigen.",
        "simbrief_planned": "Geplanter SimBrief-Flug",
        "simbrief_passengers": "Geplante Passagiere",
        "simbrief_fuel": "Geplanter Rampentreibstoff",
        "simbrief_evidence": "Flugplanwerte, keine gemessenen Borddaten. Routenpunkte stammen aus dem Navlog dieses OFP.",
        "simbrief_file": "OFP-Datei zuordnen…",
        "simbrief_identity_hint": "Piloten-ID oder Benutzername eingeben. Gespeichert wird erst nach erfolgreichem Laden des OFP.",
        "simbrief_identity_invalid": "Gültige Piloten-ID oder Benutzernamen eingeben (höchstens 128 Zeichen).",
        "simbrief_cancel": "Abbrechen",
        "simbrief_ready": "✓ SimBrief geladen",
        "simbrief_unload": "SimBrief entladen",
        "simbrief_next_flight": "Nächster Flug",
        "simbrief_staged_loaded": "OFP ist geladen. Flugabschluss oder neue PDFs verwenden es automatisch für diesen Flug.",
        "simbrief_not_attached": "Diesem Flug ist kein OFP zugeordnet",
        "simbrief_available_for_report": "Geladenes OFP beim Erstellen von PDFs verfügbar",
        "simbrief_use_staged": "Geladenes OFP für diesen gespeicherten Flug und seine neuen PDFs verwenden? Zugehörigkeit der Route bestätigen. Ja ordnet diesen Plan zu; Nein behält den bisherigen Plan; Abbrechen stoppt die Erstellung.",
        "simbrief_position_legend": "Durchgezogen: Aufzeichnung · Violett: OFP an erfassten Positionen",
        "simbrief_comparison_unavailable": "OFP-Vergleich fehlt: zu wenige passende Daten",
        "simbrief_loading_short": "SimBrief wird geladen…",
        "pdf_dependency_missing": "PDF-Komponente fehlt: {module}. Quellabhängigkeiten installieren oder die App reparieren, dann erneut versuchen. Gespeicherte Flüge bleiben verfügbar.",
    })
    translations["sv"].update({
        "simbrief_load": "Läs in från SimBrief",
        "simbrief_load_quick": "Läs in SimBrief",
        "simbrief_identity": "SimBrief pilot-ID eller användarnamn",
        "simbrief_loading": "Läser in senaste OFP från SimBrief…",
        "simbrief_loaded": "OFP inläst. Skapa PDF-filerna igen för att inkludera planen.",
        "simbrief_retry": "Flygningen ändrades eller rapportskapandet startade. Läs in OFP igen.",
        "simbrief_confirm": "Koppla senaste OFP till vald sparad flygning? Bekräfta att planen hör till flygningen.",
        "simbrief_planned": "Planerad SimBrief-flygning",
        "simbrief_passengers": "Planerat antal passagerare",
        "simbrief_fuel": "Planerat rampbränsle",
        "simbrief_evidence": "Färdplansuppgifter, inte uppmätta omborddata. Ruttpunkter kommer från denna OFP:s navlogg.",
        "simbrief_file": "Koppla OFP-fil…",
        "simbrief_identity_hint": "Ange pilot-ID eller användarnamn. Det sparas först när OFP har lästs in.",
        "simbrief_identity_invalid": "Ange ett giltigt pilot-ID eller användarnamn (högst 128 tecken).",
        "simbrief_cancel": "Avbryt",
        "simbrief_ready": "✓ SimBrief inläst",
        "simbrief_unload": "Koppla bort SimBrief",
        "simbrief_next_flight": "Nästa flygning",
        "simbrief_staged_loaded": "OFP är inläst. Slutför flygningen eller skapa nya PDF-filer för att använda planen automatiskt.",
        "simbrief_not_attached": "Inget OFP kopplat till denna flygning",
        "simbrief_available_for_report": "Inläst OFP tillgängligt när PDF-filer skapas",
        "simbrief_use_staged": "Använd inläst OFP för denna sparade flygning och dess nya PDF-filer? Bekräfta att rutten hör hit. Ja kopplar planen; Nej behåller flygningens befintliga plan; Avbryt stoppar skapandet.",
        "simbrief_position_legend": "Heldraget: inspelat · Lila: OFP vid inspelade positioner",
        "simbrief_comparison_unavailable": "OFP-jämförelse saknas: för få matchande uppgifter",
        "simbrief_loading_short": "Läser in SimBrief…",
        "pdf_dependency_missing": "PDF-komponent saknas: {module}. Installera källans beroenden eller reparera appen och försök igen. Sparade flygningar är tillgängliga.",
    })


    translations['en'].update({
        'simbrief_compare_route': 'Recorded and planned route',
        'recorded_short': 'Recorded: blue',
        'planned_short': 'OFP: purple dashed',
        'map_tiles_unavailable': 'Map background unavailable; route retained',
        'map_positions_omitted': 'Isolated position jumps omitted from map; raw data retained',
        'simbrief_dispatch_title': 'SimBrief dispatch plan',
        'simbrief_fuel_load_title': 'Planned fuel and loading',
        'simbrief_overlay_legend': 'Solid: recorded · Purple dashed: OFP plan',
        'simbrief_alignment_note': 'OFP curves use recorded simulator time from detected takeoff. If this anchor is unavailable, the labelled position comparison places planned fixes near the recorded trajectory (within 10 NM). Straight segments join planned fixes; capture gaps remain breaks. Only matching captured data is compared; planned values are not onboard measurements.',
        'simbrief_cargo': 'Planned cargo',
        'simbrief_payload': 'Planned payload',
        'simbrief_distance': 'Planned route distance',
        'simbrief_duration': 'Planned airborne time',
        'simbrief_cruise': 'Planned cruise altitude',
        'simbrief_registration': 'Aircraft registration',
        'simbrief_callsign': 'Flight / callsign',
        'simbrief_cost_index': 'Cost index',
        'simbrief_runways': 'Planned runways',
        'simbrief_alternate': 'Alternate airport',
        'simbrief_schedule': 'Scheduled departure / arrival (UTC)',
        'simbrief_trip_fuel': 'Planned trip fuel',
        'simbrief_taxi_fuel': 'Planned taxi fuel',
        'simbrief_reserve_fuel': 'Planned reserve fuel',
        'simbrief_contingency': 'Planned contingency fuel',
        'simbrief_zfw': 'Planned zero-fuel weight',
        'simbrief_tow': 'Planned takeoff weight',
        'simbrief_ldw': 'Planned landing weight',
        'simbrief_aircraft': 'Planned aircraft type',
        'simbrief_route_text': 'Filed routing',
    })
    translations['cs'].update({
        'simbrief_compare_route': 'Zaznamenaná a plánovaná trasa',
        'recorded_short': 'Záznam: modře',
        'planned_short': 'OFP: fialově přerušovaně',
        'map_tiles_unavailable': 'Mapový podklad není dostupný; trasa zachována',
        'map_positions_omitted': 'Izolované skoky polohy vynechány z mapy; surová data zachována',
        'simbrief_dispatch_title': 'Letový plán ze SimBriefu',
        'simbrief_fuel_load_title': 'Plán paliva a naložení',
        'simbrief_overlay_legend': 'Plná: záznam · Fialová přerušovaná: OFP',
        'simbrief_alignment_note': 'Křivky OFP využívají čas simulátoru od rozpoznaného vzletu. Pokud tato kotva chybí, označené srovnání podle polohy přiřadí plánované body blízko zaznamenané trasy (do 10 NM). Úsečky spojují plánované body, mezery záznamu zůstávají přerušené. Srovnávají se pouze odpovídající zachycená data; plánované hodnoty nejsou měřením na palubě.',
        'simbrief_cargo': 'Plánovaný náklad',
        'simbrief_payload': 'Plánované užitečné zatížení',
        'simbrief_distance': 'Plánovaná délka trasy',
        'simbrief_duration': 'Plánovaný čas ve vzduchu',
        'simbrief_cruise': 'Plánovaná cestovní výška',
        'simbrief_registration': 'Registrace letadla',
        'simbrief_callsign': 'Let / volací znak',
        'simbrief_cost_index': 'Cost index',
        'simbrief_runways': 'Plánované dráhy',
        'simbrief_alternate': 'Náhradní letiště',
        'simbrief_schedule': 'Plánovaný odlet / přílet (UTC)',
        'simbrief_trip_fuel': 'Palivo na trať',
        'simbrief_taxi_fuel': 'Palivo na pojíždění',
        'simbrief_reserve_fuel': 'Záložní palivo',
        'simbrief_contingency': 'Contingency palivo',
        'simbrief_zfw': 'Plánovaná hmotnost bez paliva',
        'simbrief_tow': 'Plánovaná vzletová hmotnost',
        'simbrief_ldw': 'Plánovaná přistávací hmotnost',
        'simbrief_aircraft': 'Plánovaný typ letadla',
        'simbrief_route_text': 'Podaná trať',
    })
    translations['de'].update({
        'simbrief_compare_route': 'Aufgezeichnete und geplante Route',
        'recorded_short': 'Aufzeichnung: blau',
        'planned_short': 'OFP: violett gestrichelt',
        'map_tiles_unavailable': 'Kartenhintergrund fehlt; Route bleibt sichtbar',
        'map_positions_omitted': 'Isolierte Positionssprünge auf der Karte ausgelassen; Rohdaten erhalten',
        'simbrief_dispatch_title': 'SimBrief-Flugplan',
        'simbrief_fuel_load_title': 'Geplanter Treibstoff und Beladung',
        'simbrief_overlay_legend': 'Durchgezogen: Aufzeichnung · Violett gestrichelt: OFP',
        'simbrief_alignment_note': 'OFP-Kurven nutzen die Simulatorzeit ab erkanntem Start. Fehlt dieser Anker, ordnet der gekennzeichnete Positionsvergleich Planpunkte der nahen aufgezeichneten Route zu (bis 10 NM). Geraden verbinden Planpunkte; Aufzeichnungslücken bleiben Unterbrechungen. Nur passende erfasste Daten werden verglichen; Planwerte sind keine Bordmessungen.',
        'simbrief_cargo': 'Geplante Fracht',
        'simbrief_payload': 'Geplante Nutzlast',
        'simbrief_distance': 'Geplante Routendistanz',
        'simbrief_duration': 'Geplante Flugzeit',
        'simbrief_cruise': 'Geplante Reiseflughöhe',
        'simbrief_registration': 'Luftfahrzeugkennzeichen',
        'simbrief_callsign': 'Flug / Rufzeichen',
        'simbrief_cost_index': 'Cost Index',
        'simbrief_runways': 'Geplante Pisten',
        'simbrief_alternate': 'Ausweichflughafen',
        'simbrief_schedule': 'Geplanter Abflug / Ankunft (UTC)',
        'simbrief_trip_fuel': 'Geplanter Streckentreibstoff',
        'simbrief_taxi_fuel': 'Geplanter Rolltreibstoff',
        'simbrief_reserve_fuel': 'Geplanter Reservetreibstoff',
        'simbrief_contingency': 'Contingency-Treibstoff',
        'simbrief_zfw': 'Geplantes Nullkraftstoffgewicht',
        'simbrief_tow': 'Geplantes Startgewicht',
        'simbrief_ldw': 'Geplantes Landegewicht',
        'simbrief_aircraft': 'Geplanter Flugzeugtyp',
        'simbrief_route_text': 'Aufgegebene Route',
    })
    translations['sv'].update({
        'simbrief_compare_route': 'Inspelad och planerad rutt',
        'recorded_short': 'Inspelat: blått',
        'planned_short': 'OFP: lila streckat',
        'map_tiles_unavailable': 'Kartbakgrund saknas; rutten visas ändå',
        'map_positions_omitted': 'Isolerade positionshopp utelämnade från kartan; rådata bevaras',
        'simbrief_dispatch_title': 'SimBrief-färdplan',
        'simbrief_fuel_load_title': 'Planerat bränsle och last',
        'simbrief_overlay_legend': 'Heldraget: inspelat · Lila streckat: OFP',
        'simbrief_alignment_note': 'OFP-kurvor använder simulatortiden från upptäckt start. Om ankaret saknas kopplar den markerade positionsjämförelsen planpunkter till den närliggande inspelade rutten (inom 10 NM). Raka segment förbinder planpunkter; inspelningsluckor förblir avbrott. Bara matchande inspelade data jämförs; planvärden är inga mätningar ombord.',
        'simbrief_cargo': 'Planerad frakt',
        'simbrief_payload': 'Planerad nyttolast',
        'simbrief_distance': 'Planerad ruttdistans',
        'simbrief_duration': 'Planerad flygtid',
        'simbrief_cruise': 'Planerad marschhöjd',
        'simbrief_registration': 'Flygplansregistrering',
        'simbrief_callsign': 'Flyg / anropssignal',
        'simbrief_cost_index': 'Cost index',
        'simbrief_runways': 'Planerade banor',
        'simbrief_alternate': 'Alternativ flygplats',
        'simbrief_schedule': 'Planerad avgång / ankomst (UTC)',
        'simbrief_trip_fuel': 'Planerat färdbränsle',
        'simbrief_taxi_fuel': 'Planerat taxibränsle',
        'simbrief_reserve_fuel': 'Planerat reservbränsle',
        'simbrief_contingency': 'Contingency-bränsle',
        'simbrief_zfw': 'Planerad vikt utan bränsle',
        'simbrief_tow': 'Planerad startvikt',
        'simbrief_ldw': 'Planerad landningsvikt',
        'simbrief_aircraft': 'Planerad flygplanstyp',
        'simbrief_route_text': 'Inlämnad rutt',
    })


    # Parallel labels share the established EN/CS/DE/SV registry order; this keeps
    # every comparison table, graph and quality message present in all editions.
    _COMPARISON_LABELS = {
        "title": ("Plan versus actual", "Plán a skutečnost", "Plan und Wirklichkeit", "Plan och utfall"),
        "metric": ("Metric", "Ukazatel", "Kennzahl", "Mått"),
        "plan": ("Planned", "Plán", "Geplant", "Planerat"),
        "actual": ("Actual", "Skutečnost", "Tatsächlich", "Utfall"),
        "delta": ("Actual − plan", "Skutečnost − plán", "Ist − Plan", "Utfall − plan"),
        "percent": ("Difference %", "Rozdíl %", "Differenz %", "Skillnad %"),
        "airtime": ("Airborne duration", "Doba ve vzduchu", "Flugzeit", "Tid i luften"),
        "distance": ("Airborne ground-track distance", "Vzdálenost ve vzduchu vůči zemi", "Flugstrecke über Grund", "Flugen markdistans"),
        "trip_fuel": ("Trip fuel used", "Spotřeba od vzletu do přistání", "Streckentreibstoffverbrauch", "Förbrukat färdbränsle"),
        "takeoff_fuel": ("Fuel at takeoff", "Palivo při vzletu", "Treibstoff beim Start", "Bränsle vid start"),
        "landing_fuel": ("Fuel at landing", "Palivo při přistání", "Treibstoff bei Landung", "Bränsle vid landning"),
        "ramp_fuel": ("Fuel at estimated off-block", "Palivo při odhadovaném odjezdu", "Treibstoff bei geschätztem Off-Block", "Bränsle vid uppskattad avgång"),
        "tow": ("Takeoff weight", "Vzletová hmotnost", "Startgewicht", "Startvikt"),
        "ldw": ("Landing weight", "Přistávací hmotnost", "Landegewicht", "Landningsvikt"),
        "zfw": ("Zero-fuel weight at takeoff", "Hmotnost bez paliva při vzletu", "Nullkraftstoffgewicht beim Start", "Vikt utan bränsle vid start"),
        "reserve_margin": ("Landing fuel above OFP reserve", "Palivo při přistání nad rezervou OFP", "Landetreibstoff über OFP-Reserve", "Landningsbränsle över OFP-reserv"),
        "block": ("Estimated block duration", "Odhad doby mezi stojánkami", "Geschätzte Blockzeit", "Uppskattad blocktid"),
        "taxiout": ("Estimated taxi-out duration", "Odhad doby pojíždění před vzletem", "Geschätzte Rollzeit vor Start", "Uppskattad uttaxningstid"),
        "taxiin": ("Estimated taxi-in duration", "Odhad doby pojíždění po přistání", "Geschätzte Rollzeit nach Landung", "Uppskattad intaxningstid"),
        "departure_delay": ("Estimated off-block delay", "Odhad zpoždění odjezdu", "Geschätzte Off-Block-Verspätung", "Uppskattad avgångsförsening"),
        "arrival_delay": ("Estimated on-block delay", "Odhad zpoždění příjezdu", "Geschätzte On-Block-Verspätung", "Uppskattad ankomstförsening"),
        "takeoff_delay": ("Detected takeoff delay", "Zpoždění rozpoznaného vzletu", "Verspätung des erkannten Starts", "Försening för upptäckt start"),
        "landing_delay": ("Detected landing delay", "Zpoždění rozpoznaného přistání", "Verspätung der erkannten Landung", "Försening för upptäckt landning"),
        "cruise_tas": ("Cruise TAS", "Cestovní TAS", "Reiseflug-TAS", "TAS under marsch"),
        "toc_time": ("Top of climb: time since takeoff", "Konec stoupání: čas od vzletu", "Steigflugende: Zeit seit Start", "Slut på stigning: tid från start"),
        "tod_time": ("Top of descent: time since takeoff", "Začátek klesání: čas od vzletu", "Sinkflugbeginn: Zeit seit Start", "Början av sjunkning: tid från start"),
        "toc_position": ("Top of climb: displacement", "Konec stoupání: posun místa", "Steigflugende: Ortsabweichung", "Slut på stigning: positionsavvikelse"),
        "tod_position": ("Top of descent: displacement", "Začátek klesání: posun místa", "Sinkflugbeginn: Ortsabweichung", "Början av sjunkning: positionsavvikelse"),
        "phases": ("Flight phase analysis", "Rozbor fází letu", "Flugphasenanalyse", "Analys av flygfaser"),
        "phase": ("Phase", "Fáze", "Phase", "Fas"),
        "time_plan": ("Time P", "Čas P", "Zeit P", "Tid P"),
        "time_actual": ("Time A", "Čas A", "Zeit A", "Tid A"),
        "fuel_plan": ("Fuel P", "Palivo P", "Treibstoff P", "Bränsle P"),
        "fuel_actual": ("Fuel A", "Palivo A", "Treibstoff A", "Bränsle A"),
        "coverage": ("Coverage", "Pokrytí", "Abdeckung", "Täckning"),
        "flow": ("Consumption /h", "Spotřeba /h", "Verbrauch /h", "Förbrukning /h"),
        "efficiency": ("Consumption /NM", "Spotřeba /NM", "Verbrauch /NM", "Förbrukning /NM"),
        "speeds": ("Speed kt", "Rychlost kt", "Geschw. kt", "Fart kt"),
        "wind": ("Wind kt", "Vítr kt", "Wind kt", "Vind kt"),
        "headwind": ("Head +", "Proti +", "Gegen +", "Mot +"),
        "crosswind": ("Cross", "Boční", "Quer", "Sida"),
        "performance": ("Observed performance", "Naměřené výkony", "Beobachtete Leistung", "Uppmätta prestanda"),
        "mean": ("Weighted mean", "Vážený průměr", "Gewichtetes Mittel", "Viktat medel"),
        "min": ("Minimum", "Minimum", "Minimum", "Minimum"),
        "max": ("Maximum", "Maximum", "Maximum", "Maximum"),
        "route_height": ("Route, altitude and data coverage", "Trasa, výška a pokrytí dat", "Route, Höhe und Datenabdeckung", "Rutt, höjd och datatäckning"),
        "route_mean": ("Mean route separation", "Průměrná vzdálenost od trasy", "Mittlerer Routenabstand", "Genomsnittligt ruttavstånd"),
        "route_max": ("Maximum route separation", "Největší vzdálenost od trasy", "Größter Routenabstand", "Största ruttavstånd"),
        "route_outside": ("Time beyond {band} NM", "Doba dále než {band} NM", "Zeit außerhalb {band} NM", "Tid utanför {band} NM"),
        "height_mean": ("Mean absolute altitude error", "Průměrná absolutní výšková odchylka", "Mittlerer absoluter Höhenfehler", "Genomsnittligt absolut höjdfel"),
        "height_max": ("Maximum absolute altitude error", "Největší absolutní výšková odchylka", "Größter absoluter Höhenfehler", "Största absoluta höjdfel"),
        "height_outside": ("Time beyond ±{band} ft", "Doba mimo ±{band} ft", "Zeit außerhalb ±{band} ft", "Tid utanför ±{band} ft"),
        "levels": ("Dominant observed cruise levels", "Převládající cestovní hladiny", "Häufigste Reiseflughöhen", "Vanligaste observerade marschnivåer"),
        "flightplan": ("Completed flightplan · planned and actual", "Vyplněný letový plán · plán a skutečnost", "Ausgefüllter Flugplan · Plan und Ist", "Ifylld färdplan · plan och utfall"),
        "fix": ("Fix / stage", "Bod / fáze", "Punkt / Phase", "Punkt / fas"),
        "elapsed": ("Time since takeoff", "Čas od vzletu", "Zeit seit Start", "Tid från start"),
        "utc": ("UTC date / time", "Datum / čas UTC", "Datum / Zeit UTC", "Datum / tid UTC"),
        "fuel_remaining": ("Fuel remaining", "Zbývající palivo", "Resttreibstoff", "Kvarvarande bränsle"),
        "pressure": ("Altitude P / pressure A", "Výška P / tlaková A", "Höhe P / Druckhöhe A", "Höjd P / tryckhöjd A"),
        "tas": ("TAS kt", "TAS kt", "TAS kt", "TAS kt"),
        "gs": ("GS kt", "GS kt", "GS kt", "GS kt"),
        "match": ("Fix separation", "Vzdálenost od bodu", "Punktabstand", "Punktavstånd"),
        "unmatched": ("Not matched", "Nepřiřazeno", "Nicht zugeordnet", "Ej matchad"),
        "deviation_charts": ("Deviation profiles", "Grafy odchylek", "Abweichungsprofile", "Avvikelseprofiler"),
        "time_profile": ("Time deviation · positive = behind plan", "Časová odchylka · kladná = za plánem", "Zeitabweichung · positiv = verspätet", "Tidsavvikelse · positiv = efter plan"),
        "fuel_profile": ("Fuel onboard deviation · positive = more onboard", "Odchylka zásoby paliva · kladná = více na palubě", "Treibstoffabweichung · positiv = mehr an Bord", "Bränsleavvikelse · positiv = mer ombord"),
        "burn_profile": ("Fuel burn deviation · adjusted for initial load", "Odchylka spotřeby · očištěná o počáteční zásobu", "Verbrauchsabweichung · anfängliche Beladung bereinigt", "Förbrukningsavvikelse · justerad för startmängd"),
        "height_profile": ("Pressure altitude deviation", "Odchylka tlakové výšky", "Druckhöhenabweichung", "Tryckhöjdsavvikelse"),
        "route_profile": ("Separation from the planned polyline", "Vzdálenost od plánované trasy", "Abstand von der geplanten Routenlinie", "Avstånd från planerad ruttlinje"),
        "distribution": ("Absolute error distribution · time weighted", "Rozdělení absolutních odchylek · vážené časem", "Absolute Fehlerverteilung · zeitgewichtet", "Absolut felfördelning · tidsviktad"),
        "statistics": ("Descriptive deviation statistics", "Popisná statistika odchylek", "Beschreibende Abweichungsstatistik", "Beskrivande avvikelsestatistik"),
        "bias": ("Signed mean", "Průměr se znaménkem", "Vorzeichenbehaftetes Mittel", "Medel med tecken"),
        "mae": ("MAE", "MAE", "MAE", "MAE"),
        "rmse": ("RMSE", "RMSE", "RMSE", "RMSE"),
        "p95": ("P95 |error| ≈", "P95 |odchylka| ≈", "P95 |Fehler| ≈", "P95 |fel| ≈"),
        "elapsed_axis": ("Simulator minutes since takeoff", "Minuty simulátoru od vzletu", "Simulatorminuten seit Start", "Simulatorminuter från start"),
        "route_highlight": ("Amber: beyond comparison corridor", "Oranžová: mimo srovnávací koridor", "Orange: außerhalb Vergleichskorridor", "Orange: utanför jämförelsekorridor"),
    }
    for _comparison_key, _comparison_values in _COMPARISON_LABELS.items():
        for _comparison_language, _comparison_value in zip(("en", "cs", "de", "sv"), _comparison_values):
            translations[_comparison_language]["cmp_" + _comparison_key] = _comparison_value

    translations["en"].update({
        "cmp_fact": "{metric}: planned {planned}; actual {actual}; difference {delta}.",
        "cmp_basis": "Positive differences mean actual minus plan. Flight totals require matched airports, one captured leg and complete comparable evidence. Recording duration is not airborne duration. No passenger count or cause of a deviation is inferred.",
        "cmp_fuel_basis": "Fuel is measured mass, normalized to the declared OFP unit. A larger initial load is separate from lower consumption. Remaining fuel above OFP reserve is a numerical comparison, not an operational reserve assessment. Net fuel loss includes any unrecorded dumping or other removal.",
        "cmp_phase_basis": "P = planned, A = observed. Phases are inferred. OFP stage totals and observed approach/flare boundaries differ; partial planned stages remain unknown. Fuel coverage is shown explicitly; consumption rates use only observed fuel intervals.",
        "cmp_performance_basis": "Means use simulator-time weights from original saved observations. IAS/TAS/GS are kt; V/S is fpm. Min/max retain observed endpoint extrema. Each metric has its own covered duration.",
        "cmp_geometry_basis": "Route separation describes the stored OFP polyline. The 5 NM and ±300 ft bands are descriptive comparison settings, not aircraft, ATC or airspace limits. Vectoring, shortcuts and holds are not classified as errors by the pilot.",
        "cmp_height_basis": "Altitude deltas compare pressure altitude only above both known airport transition altitudes. Missing pressure/reference data suppresses deltas. The OFP profile is piecewise linear; dominant levels are grouped into 100-ft bins.",
        "cmp_data": "Matched points: {matched}/{total}. Valid simulator intervals: {valid}. Excluded intervals: {gaps}. Route coverage: {route}; height coverage: {height}.",
        "cmp_blocks": "Simulator UTC — estimated OUT: {out}; takeoff: {off}; landing: {on}; estimated IN: {inside}.",
        "cmp_flightplan_basis": "P = plan, A = actual estimate, Δ = A − P. All OFP points remain in order. Intermediate passages require proximity within 2 NM and chronological matching; airports use captured takeoff/landing within 10 NM. Values are interpolated only within a continuous original observation interval. Unmatched/absent values remain unknown. Altitude A uses standard pressure; a delta appears only with compatible references. Wind is true direction-from / kt. UTC uses the simulator calendar, never the host clock.",
        "cmp_chart_basis": "Profiles compare the observation with its nearest position on the OFP polyline; these are position-aligned planning estimates, distinct from exact ordered fix matches in the final table. Time uses simulator minutes since takeoff. Gaps remain breaks. Fuel-burn delta removes the initial fuel-load difference; positive means greater net fuel use. Decimation affects plotted detail only.",
        "cmp_statistics_basis": "MAE is mean absolute error; RMSE is root mean squared error. P95 is time-weighted absolute error estimated from fixed bins (0.1 NM, 25 ft, 1 s); the overflow bin gives a lower bound. Histogram height is covered simulator time in %. Observations are correlated: no independent-sample confidence interval or causal attribution is claimed.",
        "cmp_note_visits_limited": "The bounded repeated-waypoint visit history was exhausted; additional visits were not retained.",
        "cmp_note_plan_time_order": "Some OFP cumulative times are out of order; their time comparisons are unavailable.",
        "cmp_note_route_unverified": "Fewer than two distinct ordered route positions were matched. Planned values remain visible; numerical plan comparisons are suppressed.",
        "cmp_note_partial": "Complete flight totals are unavailable: a matched single takeoff-to-landing recording with continuous comparable timing is required.",
        "cmp_note_gaps": "Capture gaps, clock breaks or invalid intervals were excluded. No statistics use the presentation-only interpolation in existing charts.",
        "cmp_note_fuel_changed": "An airborne fuel increase was observed. Net trip-burn comparisons are unavailable across the loading/refuelling change.",
        "cmp_note_fuel_missing": "Measured fuel mass does not cover all airborne intervals. Volume-only historical recordings remain usable without fabricated mass statistics.",
        "cmp_note_pressure_missing": "No comparable pressure-altitude intervals are available. Pressure observations and both airport transition references are required.",
        "cmp_note_block_estimate": "Block events require sustained stationary parking-brake evidence followed by ground movement or final parking. Missing events remain unknown; detected block times are estimates and do not identify a stand.",
        "cmp_note_multiple_legs": "Multiple takeoffs or landings were recorded. Single-flight total comparisons are suppressed; observed phases and matched route points remain visible.",
        "cmp_note_schedule_date": "OFP and simulator UTC events differ by more than one day or lack compatible timestamps. A schedule-delay number is not reported.",
    })
    translations["cs"].update({
        "cmp_fact": "{metric}: plán {planned}; skutečnost {actual}; rozdíl {delta}.",
        "cmp_basis": "Kladný rozdíl znamená skutečnost minus plán. Souhrny letu vyžadují odpovídající letiště, jeden zachycený let a úplná srovnatelná data. Doba záznamu není doba ve vzduchu. Počet cestujících ani příčiny odchylek se neodhadují.",
        "cmp_fuel_basis": "Palivo je měřená hmotnost v jednotce OFP. Větší počáteční zásoba je oddělena od nižší spotřeby. Zůstatek nad rezervou OFP je číselné srovnání, nikoli provozní posouzení rezerv. Čistý úbytek zahrnuje i případné nezaznamenané vypouštění či jiné odebrání paliva.",
        "cmp_phase_basis": "P = plán, A = pozorovaná skutečnost. Fáze jsou rozpoznané odhadem. Hranice fází OFP a pozorovaného přiblížení či podrovnání se liší; neúplné plánované fáze zůstávají neznámé. Pokrytí měřením paliva je uvedeno; průběžná spotřeba využívá jen zachycené intervaly.",
        "cmp_performance_basis": "Průměry jsou vážené časem simulátoru z původních uložených vzorků. IAS/TAS/GS jsou v kt, V/S ve fpm. Minima a maxima zachovávají naměřené krajní hodnoty. Každý ukazatel má vlastní dobu pokrytí.",
        "cmp_geometry_basis": "Vzdálenost od trasy se vztahuje k uložené lomené čáře OFP. Pásma 5 NM a ±300 ft slouží k popisu odchylek; nejsou limity letadla, ATC ani vzdušného prostoru. Vektorování, zkratky a vyčkávání se neoznačují za chybu pilota.",
        "cmp_height_basis": "Výškové odchylky porovnávají tlakovou výšku pouze nad oběma známými přechodovými výškami letišť. Chybějící tlaková výška či reference výpočet potlačí. Profil OFP spojuje body úsečkami; převládající hladiny jsou seskupeny po 100 ft.",
        "cmp_data": "Přiřazené body: {matched}/{total}. Platné intervaly simulátoru: {valid}. Vyloučené intervaly: {gaps}. Pokrytí trasy: {route}; výšky: {height}.",
        "cmp_blocks": "UTC simulátoru — odhad OUT: {out}; vzlet: {off}; přistání: {on}; odhad IN: {inside}.",
        "cmp_flightplan_basis": "P = plán, A = odhad skutečnosti, Δ = A − P. Všechny body OFP zůstávají v pořadí. Mezilehlé průlety vyžadují vzdálenost do 2 NM a časové pořadí; letiště používají zachycený vzlet/přistání do 10 NM. Hodnoty se interpolují jen uvnitř spojitého intervalu původních vzorků. Nepřiřazené či chybějící údaje zůstávají neznámé. Výška A používá standardní tlak; rozdíl se ukáže jen při společné referenci. Vítr je pravý směr odkud / kt. UTC vychází z kalendáře simulátoru, nikoli hodin počítače.",
        "cmp_chart_basis": "Grafy porovnávají záznam s průmětem na nejbližší místo trasy OFP; jde o plánovací odhad podle polohy, odlišný od přiřazených průletů v závěrečné tabulce. Čas je v minutách simulátoru od vzletu. Mezery zůstávají přerušené. Odchylka spotřeby odečítá rozdíl počáteční zásoby; kladná znamená větší čistý úbytek paliva. Ředění ovlivňuje jen podrobnost grafu.",
        "cmp_statistics_basis": "MAE je průměrná absolutní odchylka; RMSE odmocnina průměru čtverců odchylek. P95 je časově vážený percentil absolutní odchylky z pevných intervalů (0,1 NM, 25 ft, 1 s); krajní interval udává dolní mez. Výška histogramu je podíl zachyceného času simulátoru v %. Pozorování jsou závislá; neuvádí se interval spolehlivosti pro nezávislé vzorky ani příčinné závěry.",
        "cmp_note_visits_limited": "Byl vyčerpán limit uchovaných opakovaných průletů bodu; další průlety se neuchovaly.",
        "cmp_note_plan_time_order": "Některé kumulativní časy OFP nejsou v pořadí; příslušná časová srovnání nejsou dostupná.",
        "cmp_note_route_unverified": "Nepodařilo se přiřadit alespoň dvě různá místa trasy v pořadí. Plán zůstává zobrazen, číselná srovnání s plánem jsou potlačena.",
        "cmp_note_partial": "Úplné souhrny letu nejsou dostupné: vyžadují jeden odpovídající záznam od vzletu do přistání se spojitým srovnatelným časem.",
        "cmp_note_gaps": "Výpadky záznamu, zlomy hodin a neplatné intervaly byly vyřazeny. Statistiky nepoužívají interpolaci určenou pouze pro zobrazení stávajících grafů.",
        "cmp_note_fuel_changed": "Za letu byl zachycen nárůst paliva. Přes změnu naložení či doplnění se souhrnná spotřeba neporovnává.",
        "cmp_note_fuel_missing": "Měřená hmotnost paliva nepokrývá všechny intervaly ve vzduchu. Starší objemové záznamy zůstávají použitelné bez vymyšlené hmotnostní statistiky.",
        "cmp_note_pressure_missing": "Nejsou dostupné srovnatelné intervaly tlakové výšky. Je potřeba měřená tlaková výška a obě přechodové výšky letišť.",
        "cmp_note_block_estimate": "Časy mezi stojánkami vyžadují souvislé stání s parkovací brzdou a následný pohyb či konečné zaparkování. Chybějící události zůstávají neznámé; zjištěné časy jsou odhady a neurčují konkrétní stojánku.",
        "cmp_note_multiple_legs": "Záznam obsahuje více vzletů či přistání. Souhrnné srovnání jediného letu je potlačeno; zachycené fáze a přiřazené body zůstávají zobrazené.",
        "cmp_note_schedule_date": "Události OFP a UTC simulátoru se liší o více než den nebo nemají srovnatelný čas. Číselné zpoždění vůči letovému řádu se neuvádí.",
    })
    translations["de"].update({
        "cmp_fact": "{metric}: geplant {planned}; tatsächlich {actual}; Differenz {delta}.",
        "cmp_basis": "Positive Differenzen bedeuten Ist minus Plan. Flugsummen erfordern passende Flughäfen, einen aufgezeichneten Flug und vollständige vergleichbare Daten. Aufzeichnungsdauer ist keine Flugzeit. Passagierzahlen und Ursachen werden nicht abgeleitet.",
        "cmp_fuel_basis": "Treibstoff ist gemessene Masse in der OFP-Einheit. Mehr Starttreibstoff wird von geringerem Verbrauch getrennt. Der Abstand zur OFP-Reserve ist ein Zahlenvergleich, keine betriebliche Reservebewertung. Nettoverlust umfasst auch nicht erfasstes Ablassen oder Entnehmen.",
        "cmp_phase_basis": "P = geplant, A = beobachtet. Phasen werden geschätzt. OFP-Phasen und erkannte Anflug-/Abfanggrenzen unterscheiden sich; unvollständige Planphasen bleiben unbekannt. Treibstoffabdeckung wird angegeben; Verbrauchsraten nutzen nur erfasste Intervalle.",
        "cmp_performance_basis": "Mittelwerte werden mit Simulatorzeit aus ursprünglichen gespeicherten Messungen gewichtet. IAS/TAS/GS in kt, V/S in fpm. Min/max behalten beobachtete Extremwerte. Jede Kennzahl hat ihre eigene Abdeckungsdauer.",
        "cmp_geometry_basis": "Routenabstände beziehen sich auf die gespeicherte OFP-Polylinie. 5 NM und ±300 ft sind beschreibende Vergleichsbänder, keine Flugzeug-, ATC- oder Luftraumgrenzen. Vektoren, Abkürzungen und Warteschleifen gelten nicht als Pilotenfehler.",
        "cmp_height_basis": "Höhendifferenzen vergleichen Druckhöhe nur über beiden bekannten Flughafen-Übergangshöhen. Fehlende Druck-/Referenzdaten unterdrücken Differenzen. OFP-Punkte werden linear verbunden; dominante Höhen werden in 100-ft-Klassen gruppiert.",
        "cmp_data": "Zugeordnete Punkte: {matched}/{total}. Gültige Simulatorintervalle: {valid}. Ausgeschlossene Intervalle: {gaps}. Routenabdeckung: {route}; Höhenabdeckung: {height}.",
        "cmp_blocks": "Simulator-UTC — geschätztes OUT: {out}; Start: {off}; Landung: {on}; geschätztes IN: {inside}.",
        "cmp_flightplan_basis": "P = Plan, A = geschätzter Istwert, Δ = A − P. Alle OFP-Punkte bleiben geordnet. Zwischenpunkte erfordern zeitliche Reihenfolge und höchstens 2 NM Abstand; Flughäfen nutzen erkannten Start/Landung innerhalb 10 NM. Nur innerhalb zusammenhängender ursprünglicher Messintervalle wird interpoliert. Nicht zugeordnete/fehlende Daten bleiben unbekannt. Höhe A ist Druckhöhe; Differenzen nur bei gleicher Referenz. Wind: wahre Herkunftsrichtung / kt. UTC stammt aus dem Simulatorkalender, nicht der Rechneruhr.",
        "cmp_chart_basis": "Profile vergleichen Beobachtungen mit der nächsten Position der OFP-Polylinie; dies sind positionsbezogene Planschätzungen, getrennt von geordneten Punktzuordnungen der Endtabelle. Zeit in Simulatorminuten seit Start. Lücken bleiben offen. Verbrauchsdifferenzen bereinigen die anfängliche Treibstoffmenge; positiv bedeutet höheren Nettoverbrauch. Ausdünnung betrifft nur die Grafik.",
        "cmp_statistics_basis": "MAE ist mittlerer absoluter Fehler; RMSE die Wurzel des mittleren quadratischen Fehlers. P95 ist ein zeitgewichtetes absolutes Fehlerperzentil aus festen Klassen (0,1 NM, 25 ft, 1 s); die Überlaufklasse liefert eine Untergrenze. Histogrammhöhe: Anteil erfasster Simulatorzeit in %. Messungen sind abhängig; keine Konfidenzintervalle für unabhängige Stichproben oder Kausalaussagen.",
        "cmp_note_visits_limited": "Die begrenzte Historie wiederholter Punktpassagen ist voll; weitere Besuche wurden nicht gespeichert.",
        "cmp_note_plan_time_order": "Einige kumulative OFP-Zeiten sind ungeordnet; zugehörige Zeitvergleiche fehlen.",
        "cmp_note_route_unverified": "Weniger als zwei unterschiedliche Routenpositionen wurden geordnet zugeordnet. Planwerte bleiben sichtbar; numerische Planvergleiche sind unterdrückt.",
        "cmp_note_partial": "Vollständige Flugsummen fehlen: erforderlich ist ein passender einzelner Start-bis-Landung-Flug mit durchgehend vergleichbarer Zeit.",
        "cmp_note_gaps": "Aufzeichnungslücken, Uhrsprünge und ungültige Intervalle wurden ausgeschlossen. Statistiken nutzen keine reine Darstellungsinterpolation bestehender Diagramme.",
        "cmp_note_fuel_changed": "Im Flug wurde ein Treibstoffanstieg beobachtet. Über diese Beladungs-/Betankungsänderung wird kein Gesamtverbrauch verglichen.",
        "cmp_note_fuel_missing": "Gemessene Treibstoffmasse deckt nicht alle Flugintervalle ab. Alte Volumenaufzeichnungen bleiben ohne erfundene Massenstatistik nutzbar.",
        "cmp_note_pressure_missing": "Keine vergleichbaren Druckhöhenintervalle vorhanden. Druckmessung und beide Flughafen-Übergangshöhen sind erforderlich.",
        "cmp_note_block_estimate": "Blockereignisse benötigen bestätigten Stillstand mit Parkbremse und folgende Bodenbewegung oder endgültiges Parken. Fehlendes bleibt unbekannt; erkannte Zeiten sind Schätzungen ohne Standplatzbestimmung.",
        "cmp_note_multiple_legs": "Mehrere Starts oder Landungen wurden aufgezeichnet. Einzelflug-Summenvergleiche fehlen; beobachtete Phasen und zugeordnete Punkte bleiben sichtbar.",
        "cmp_note_schedule_date": "OFP- und Simulator-UTC-Ereignisse liegen mehr als einen Tag auseinander oder haben keine vergleichbare Zeit. Keine numerische Flugplanverspätung wird angegeben.",
    })
    translations["sv"].update({
        "cmp_fact": "{metric}: planerat {planned}; utfall {actual}; skillnad {delta}.",
        "cmp_basis": "Positiv skillnad betyder utfall minus plan. Flygsummor kräver matchande flygplatser, en inspelad flygning och fullständiga jämförbara data. Inspelningstid är inte flygtid. Passagerarantal och orsaker härleds inte.",
        "cmp_fuel_basis": "Bränsle är uppmätt massa i OFP-enheten. Större startmängd skiljs från lägre förbrukning. Bränsle över OFP-reserv är en numerisk jämförelse, ingen operativ reservbedömning. Nettoförlust omfattar även oregistrerad dumpning eller annan borttagning.",
        "cmp_phase_basis": "P = planerat, A = observerat. Faser uppskattas. OFP-faser och observerad inflygning/utflytning har olika gränser; ofullständiga planfaser förblir okända. Bränsletäckning visas; förbrukningstakt använder bara registrerade intervall.",
        "cmp_performance_basis": "Medelvärden viktas med simulatortid från ursprungliga sparade observationer. IAS/TAS/GS i kt; V/S i fpm. Min/max bevarar observerade extremvärden. Varje mått har egen täckt tid.",
        "cmp_geometry_basis": "Ruttavstånd avser den sparade OFP-linjen. Banden 5 NM och ±300 ft beskriver jämförelsen och är inga flygplans-, ATC- eller luftrumsgränser. Vektorering, genvägar och väntlägen klassas inte som pilotfel.",
        "cmp_height_basis": "Höjdskillnader jämför tryckhöjd endast över båda kända övergångshöjderna. Saknade tryck-/referensdata döljer skillnader. OFP-profilen är styckvis linjär; dominerande nivåer grupperas i 100-ft-klasser.",
        "cmp_data": "Matchade punkter: {matched}/{total}. Giltiga simulatorintervall: {valid}. Uteslutna intervall: {gaps}. Rutttäckning: {route}; höjdtäckning: {height}.",
        "cmp_blocks": "Simulator-UTC — uppskattad OUT: {out}; start: {off}; landning: {on}; uppskattad IN: {inside}.",
        "cmp_flightplan_basis": "P = plan, A = uppskattat utfall, Δ = A − P. Alla OFP-punkter behåller ordningen. Mellanpassager kräver tidsordning och högst 2 NM avstånd; flygplatser använder registrerad start/landning inom 10 NM. Interpolation sker endast inom sammanhängande ursprungliga observationsintervall. Omatchade/saknade värden förblir okända. Höjd A använder standardtryck; skillnad visas bara vid gemensam referens. Vind är sann riktning från / kt. UTC följer simulatorkalendern, inte datorns klocka.",
        "cmp_chart_basis": "Profiler jämför observationen med närmaste position på OFP-linjen; detta är positionsanpassade planskattningar, skilda från ordnade punktmatchningar i sluttabellen. Tid i simulatorminuter från start. Luckor förblir avbrott. Förbrukningsskillnad tar bort skillnaden i startmängd; positivt betyder större nettoförbrukning. Uttunning påverkar bara grafiken.",
        "cmp_statistics_basis": "MAE är genomsnittligt absolut fel; RMSE roten ur medelkvadratfelet. P95 är tidsviktad absolut felpercentil från fasta klasser (0,1 NM, 25 ft, 1 s); sista klassen ger en undre gräns. Histogramhöjd är andel registrerad simulatortid i %. Observationer är beroende; inga konfidensintervall för oberoende stickprov eller kausala slutsatser anges.",
        "cmp_note_visits_limited": "Den begränsade historiken för upprepade punktpassager fylldes; ytterligare besök sparades inte.",
        "cmp_note_plan_time_order": "Vissa kumulativa OFP-tider är oordnade; motsvarande tidsjämförelser saknas.",
        "cmp_note_route_unverified": "Färre än två olika ruttpositioner matchades i ordning. Planvärden visas; numeriska planjämförelser döljs.",
        "cmp_note_partial": "Fullständiga flygsummor saknas: en matchande flygning från start till landning med sammanhängande jämförbar tid krävs.",
        "cmp_note_gaps": "Inspelningsluckor, klockbrott och ogiltiga intervall uteslöts. Statistik använder inte befintliga grafers rena visningsinterpolation.",
        "cmp_note_fuel_changed": "En bränsleökning observerades i luften. Totalförbrukning jämförs inte över ändrad last eller tankning.",
        "cmp_note_fuel_missing": "Uppmätt bränslemassa täcker inte alla luftintervall. Äldre volyminspelningar förblir användbara utan påhittad massastatistik.",
        "cmp_note_pressure_missing": "Inga jämförbara tryckhöjdsintervall finns. Tryckobservationer och båda flygplatsernas övergångshöjder krävs.",
        "cmp_note_block_estimate": "Blockhändelser kräver bekräftat stillastående med parkeringsbroms följt av markrörelse eller slutlig parkering. Saknade händelser förblir okända; upptäckta tider är uppskattningar utan identifierad uppställningsplats.",
        "cmp_note_multiple_legs": "Flera starter eller landningar registrerades. Summor för en enda flygning jämförs inte; observerade faser och matchade punkter visas.",
        "cmp_note_schedule_date": "OFP och simulator-UTC skiljer mer än en dag eller saknar jämförbar tid. Ingen numerisk tidtabellsförsening anges.",
    })


    _COMPARISON_COMPACT = {
        "time_route": ("Time and route", "Čas a trasa", "Zeit und Route", "Tid och rutt"),
        "fuel_weight": ("Fuel and weight", "Palivo a hmotnost", "Treibstoff und Gewicht", "Bränsle och vikt"),
        "time_pair": ("Time P / A / Δ", "Čas P / A / Δ", "Zeit P / A / Δ", "Tid P / A / Δ"),
        "fuel_pair": ("Fuel P / A / Δ", "Palivo P / A / Δ", "Treibstoff P / A / Δ", "Bränsle P / A / Δ"),
        "height_short": ("Height P / A", "Výška P / A", "Höhe P / A", "Höjd P / A"),
        "plan_utc": ("Planned UTC", "Plán UTC", "Plan UTC", "Plan UTC"),
        "group_preflight": ("Ground before taxi", "Příprava na zemi", "Bodenvorbereitung", "Förberedelse på marken"),
        "group_taxiout": ("Observed taxi-out", "Zachycené pojíždění", "Beobachtetes Rollen", "Observerad uttaxning"),
        "group_climb": ("Takeoff / climb", "Vzlet / stoupání", "Start / Steigflug", "Start / stigning"),
        "group_cruise": ("Cruise", "Cestovní let", "Reiseflug", "Marsch"),
        "group_descent": ("Descent / approach", "Klesání / přiblížení", "Sinkflug / Anflug", "Sjunkning / inflygning"),
        "group_taxiin": ("Landing / ground", "Přistání / dojezd", "Landung / Rollen", "Landning / mark"),
        "phase_time_chart": (
            "Phase duration · Δ % of plan", "Čas fází · Δ % plánu",
            "Phasendauer · Δ % des Plans", "Fastid · Δ % av plan",
        ),
        "phase_fuel_chart": (
            "Phase fuel use · Δ % of plan", "Spotřeba fází · Δ % plánu",
            "Phasenverbrauch · Δ % des Plans", "Fasförbrukning · Δ % av plan",
        ),
        "cruise_ranges": (
            "Cruise speed ranges · observed min / mean / max",
            "Rozpětí cestovních rychlostí · minimum / průměr / maximum",
            "Reisefluggeschwindigkeiten · Minimum / Mittel / Maximum",
            "Marschfarter · minimum / medel / maximum",
        ),
        "minutes_short": (
            "Simulator minutes since takeoff", "Minuty simulátoru od vzletu",
            "Simulatorminuten seit Start", "Simulatorminuter från start",
        ),
        "histogram_short": (
            "Absolute error · time distribution", "Absolutní odchylka · rozdělení času",
            "Absoluter Fehler · Zeitverteilung", "Absolut fel · tidsfördelning",
        ),
        "compact_legend": (
            "P = SimBrief, A = recorded, Δ = A − P, — = no comparable value. "
            "Green: less time/fuel used or more fuel remaining; red: the opposite. "
            "Loading, speed and wind differences remain descriptive.",
            "P = SimBrief, A = záznam, Δ = A − P, — = bez srovnatelné hodnoty. "
            "Zelená: méně času/spotřeby nebo více zbývajícího paliva; červená: opak. "
            "Rozdíly naložení, rychlosti a větru jsou popisné.",
            "P = SimBrief, A = Aufzeichnung, Δ = A − P, — = kein Vergleichswert. "
            "Grün: weniger Zeit/Verbrauch oder mehr Resttreibstoff; Rot: umgekehrt. "
            "Beladung, Geschwindigkeit und Wind bleiben beschreibend.",
            "P = SimBrief, A = inspelat, Δ = A − P, — = inget jämförbart värde. "
            "Grönt: mindre tid/förbrukning eller mer bränsle kvar; rött: motsatsen. "
            "Last-, fart- och vindskillnader är beskrivande.",
        ),
        "compact_data": (
            "Matched fixes {matched}/{total} · valid simulator time {valid} · "
            "excluded intervals {gaps}. Phase boundaries are inferred.",
            "Přiřazené body {matched}/{total} · platný čas simulátoru {valid} · "
            "vyloučené intervaly {gaps}. Hranice fází jsou odhadované.",
            "Zugeordnete Punkte {matched}/{total} · gültige Simulatorzeit {valid} · "
            "ausgeschlossene Intervalle {gaps}. Phasengrenzen sind geschätzt.",
            "Matchade punkter {matched}/{total} · giltig simulatortid {valid} · "
            "uteslutna intervall {gaps}. Fasgränser uppskattas.",
        ),
        "legacy_height": (
            "Recorded AMSL height is marked *. Flight-level errors require recorded "
            "pressure altitude and compatible transition references.",
            "Zachycená výška AMSL má značku *. Odchylky hladin vyžadují zaznamenanou "
            "tlakovou výšku a odpovídající přechodové výšky.",
            "Aufgezeichnete AMSL-Höhe trägt *. Flugflächenfehler benötigen erfasste "
            "Druckhöhe und passende Übergangshöhen.",
            "Registrerad AMSL-höjd markeras *. Flygnivåfel kräver registrerad "
            "tryckhöjd och motsvarande övergångshöjder.",
        ),
        "legacy_clock": (
            "UTC below is planned: this recording has no simulator UTC channel. "
            "Captured elapsed time since takeoff remains usable.",
            "UTC je plánované: tento záznam nemá UTC simulátoru. Zachycený relativní "
            "čas od vzletu zůstává použitelný.",
            "UTC ist geplant: Diese Aufzeichnung hat keinen Simulator-UTC-Kanal. "
            "Erfasste Zeit seit Start bleibt nutzbar.",
            "UTC är planerad: inspelningen saknar simulator-UTC. Registrerad "
            "förfluten tid från start kan fortfarande användas.",
        ),
        "group_basis": (
            "P/A and Δ share one cell. Takeoff joins climb; approach/flare join "
            "descent; contact/rollout join ground after landing. Fuel totals/rates "
            "use covered measured intervals; coverage is shown explicitly.",
            "P/A a Δ jsou v jedné buňce. Vzlet patří ke stoupání, přiblížení a "
            "podrovnání ke klesání, kontakt a dojezd k příletu. Součty a průběžná "
            "spotřeba používají pokryté měřené intervaly; pokrytí je uvedeno.",
            "P/A und Δ stehen gemeinsam. Start gehört zum Steigflug, Anflug/Abfangen "
            "zum Sinkflug, Kontakt/Ausrollen zur Ankunft. Treibstoffsummen/-raten "
            "nutzen abgedeckte Messintervalle; die Abdeckung ist angegeben.",
            "P/A och Δ delar cell. Start ingår i stigning, inflygning/utflytning "
            "i sjunkning, kontakt/utrullning i ankomst. Bränslesummor/-takt använder "
            "täckta uppmätta intervall; täckningen anges.",
        ),
        "range_basis": (
            "First line: simulator-time-weighted mean. Second line: observed "
            "minimum–maximum; shorter channel coverage is appended when needed. "
            "Positive headwind opposes the recorded ground track.",
            "První řádek: průměr vážený časem simulátoru. Druhý řádek: zachycené "
            "minimum–maximum; kratší pokrytí kanálu je případně doplněno. "
            "Kladný protivítr působí proti zaznamenané trati.",
            "Erste Zeile: simulatorzeitgewichtetes Mittel. Zweite: beobachtetes "
            "Minimum–Maximum; kürzere Kanalabdeckung wird gegebenenfalls ergänzt. "
            "Positiver Gegenwind wirkt gegen den aufgezeichneten Bodenkurs.",
            "Första raden: simulatortidsviktat medel. Andra: observerat minimum–maximum; "
            "kortare kanaltäckning anges vid behov. Positiv motvind motverkar "
            "den registrerade markkursen.",
        ),
        "whisker_basis": (
            "Whiskers = observed min/max, body = weighted mean, purple marker = "
            "planned TAS. These are observed ranges, not confidence intervals.",
            "Vousy = zachycené minimum/maximum, tělo = vážený průměr, fialová značka "
            "= plánované TAS. Jde o naměřené rozsahy, nikoli intervaly spolehlivosti.",
            "Antennen = beobachtetes Minimum/Maximum, Körper = gewichtetes Mittel, "
            "violette Marke = Plan-TAS. Beobachtete Bereiche, keine Konfidenzintervalle.",
            "Spröt = observerat min/max, kropp = viktat medel, lila markör = planerad "
            "TAS. Observerade intervall, inte konfidensintervall.",
        ),
        "chart_short": (
            "Position-aligned OFP comparison; gaps remain breaks. Fuel-use delta "
            "removes the initial load difference. Zero = plan; bands are descriptive.",
            "Srovnání v odpovídající poloze OFP; výpadky zůstávají přerušené. "
            "Odchylka spotřeby odečítá rozdíl počáteční zásoby. Nula = plán; "
            "pásma jsou popisná.",
            "Positionsbezogener OFP-Vergleich; Lücken bleiben offen. Verbrauchsdelta "
            "bereinigt die Startmenge. Null = Plan; Bänder sind beschreibend.",
            "Positionsanpassad OFP-jämförelse; luckor förblir avbrott. "
            "Förbrukningsdelta justerar startmängden. Noll = plan; banden beskriver.",
        ),
        "stats_short": (
            "MAE: mean absolute error. RMSE: root mean square error. P95 is an "
            "approximate time-weighted absolute-error percentile (bins 1 s / 0.1 NM / "
            "25 ft; overflow gives a lower bound). Histogram height = covered time %.",
            "MAE: průměrná absolutní odchylka. RMSE: odmocnina průměru čtverců. "
            "P95 je přibližný časově vážený percentil absolutní odchylky (intervaly "
            "1 s / 0,1 NM / 25 ft; krajní udává dolní mez). Histogram = podíl času %.",
            "MAE: mittlerer absoluter Fehler. RMSE: Wurzel des mittleren quadratischen "
            "Fehlers. P95 ist zeitgewichtet und näherungsweise (Klassen 1 s / 0,1 NM / "
            "25 ft; Überlauf als Untergrenze). Histogrammhöhe = erfasster Zeitanteil %.",
            "MAE: medelabsolutfel. RMSE: roten ur medelkvadratfelet. P95 är en "
            "ungefärlig tidsviktad absolutfelpercentil (klasser 1 s / 0,1 NM / 25 ft; "
            "sista ger undre gräns). Histogramhöjd = täckt tidsandel %.",
        ),
        "band_short": (
            "Time beyond the descriptive band: route >5 NM {route}; height "
            "outside ±300 ft {height}. These are not operating limits or pilot scores.",
            "Doba mimo popisné pásmo: trasa >5 NM {route}; výška mimo ±300 ft {height}. "
            "Nejde o provozní limity ani skóre pilota.",
            "Zeit außerhalb Vergleichsband: Route >5 NM {route}; Höhe außerhalb "
            "±300 ft {height}. Keine Betriebsgrenzen oder Pilotennoten.",
            "Tid utanför beskrivande band: rutt >5 NM {route}; höjd utanför ±300 ft "
            "{height}. Inga operativa gränser eller pilotbetyg.",
        ),
        "flightplan_short": (
            "P = plan / A = passage estimate; Δ = A − P. UTC context: {date}. "
            "Units are in column headings; wind = true direction-from/kt. Ordered "
            "fixes match within 2 NM, airport anchors within 10 NM; — = no value/match.",
            "P = plán / A = odhad při průletu; Δ = A − P. Datum UTC: {date}. "
            "Jednotky jsou v hlavičkách; vítr = pravý směr odkud/kt. Postupné body "
            "se přiřazují do 2 NM, letištní kotvy do 10 NM; — = bez hodnoty/přiřazení.",
            "P = Plan / A = Passageschätzung; Δ = A − P. UTC-Datum: {date}. "
            "Einheiten in Kopfzeilen; Wind = wahre Herkunftsrichtung/kt. Geordnete "
            "Punkte innerhalb 2 NM, Flughafenanker innerhalb 10 NM; — = kein Wert.",
            "P = plan / A = passageskattning; Δ = A − P. UTC-datum: {date}. "
            "Enheter i rubrikerna; vind = sann riktning från/kt. Ordnade punkter "
            "inom 2 NM, flygplatsankare inom 10 NM; — = inget värde eller matchning.",
        ),
        "amsl_marker": (
            "* A uses recorded AMSL height where pressure altitude was not captured; "
            "these rows have no pressure-height delta.",
            "* A používá zaznamenanou výšku AMSL, pokud chybí tlaková výška; "
            "u těchto řádků se tlaková výšková odchylka nepočítá.",
            "* A zeigt erfasste AMSL-Höhe ohne Druckhöhenmessung; "
            "diese Zeilen haben kein Druckhöhendelta.",
            "* A visar registrerad AMSL-höjd när tryckhöjd saknas; "
            "dessa rader har inget tryckhöjdsdelta.",
        ),
    }
    for _compact_key, _compact_values in _COMPARISON_COMPACT.items():
        for _compact_language, _compact_value in zip(("en", "cs", "de", "sv"), _compact_values):
            translations[_compact_language]["cmp_" + _compact_key] = _compact_value
