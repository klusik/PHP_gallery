# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/translation_catalog/watcher_translation_extensions.py
# Module Type: Windows Tooling Localization Catalog
# Purpose: Apply Watcher and gallery control label overrides.
# Responsibilities:
#   - Keep Watcher, connection recovery and completion labels together.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Apply Watcher and gallery control label overrides."""

from .watcher_translation_catalog import (
    _WATCH_QUEUE_REFRESH_LABELS,
    _WATCH_RECOVERY_LABELS,
)

def apply_watcher_translation_extensions(translations: dict[str, dict[str, str]]) -> None:
    """Apply the maintained translation overrides for this interface area.

    Args:
        translations (dict[str, dict[str, str]]): Ordered language maps to extend.

    Returns:
        None: Adds the catalog entries in their original declaration order.
    """
    for _queue_language, _queue_refresh_label in _WATCH_QUEUE_REFRESH_LABELS.items():
        translations[_queue_language]['watch_queue_refresh_hint'] = _queue_refresh_label

    for _recovery_language, _recovery_labels in _WATCH_RECOVERY_LABELS.items():
        translations[_recovery_language].update(_recovery_labels)

    # The four catalogues share the same compact gallery-controls contract.
    _GALLERY_CONTROL_LABELS = {'en': {'order_oldest': 'Oldest first · click for newest first (WinApp only)',
            'order_newest': 'Newest first · click for oldest first (WinApp only)',
            'order_unsaved': 'Order applies now but could not be saved on this '
                             'PC.',
            'menu_information': 'Information & access',
            'menu_local_cache': 'Local cache',
            'menu_tools': 'Edit & tools',
            'menu_create': 'Create',
            'edit_title': 'Edit gallery title',
            'edit_description': 'Edit description',
            'edit_confirm': '✓ Save',
            'edit_online': 'This change is saved to the online gallery.',
            'edit_requires_approval': 'Editing needs a current server and '
                                      'separate gallery-edit approval.',
            'approve_edit': 'Approve gallery editing…',
            'edit_conflict': 'The gallery changed while saving. Your text is kept '
                             'and can still be edited.',
            'edit_uncertain': 'Saving could not be completed. Your text is kept '
                              'and can still be edited.',
            'edit_invalid': 'Enter a nonempty title (up to 255 characters), or a '
                            'description up to 65,535 UTF-8 bytes.'},
     'cs': {'order_oldest': 'Nejstarší první · kliknutím nejnovější první (jen '
                            'WinApp)',
            'order_newest': 'Nejnovější první · kliknutím nejstarší první (jen '
                            'WinApp)',
            'order_unsaved': 'Řazení platí nyní, ale nepodařilo se je uložit v '
                             'tomto počítači.',
            'menu_information': 'Informace a přístup',
            'menu_local_cache': 'Lokální cache',
            'menu_tools': 'Úpravy a nástroje',
            'menu_create': 'Vytvořit',
            'edit_title': 'Upravit název galerie',
            'edit_description': 'Upravit popis',
            'edit_confirm': '✓ Uložit',
            'edit_online': 'Tato změna se uloží i do online galerie.',
            'edit_requires_approval': 'Úpravy vyžadují aktuální server a '
                                      'samostatné schválení úprav galerie.',
            'approve_edit': 'Schválit úpravy galerie…',
            'edit_conflict': 'Galerie se při ukládání změnila. Rozepsaný text '
                             'zůstává zachovaný a lze jej dál upravovat.',
            'edit_uncertain': 'Uložení se nepodařilo dokončit. Rozepsaný text '
                              'zůstává zachovaný a lze jej dál upravovat.',
            'edit_invalid': 'Zadejte neprázdný název (nejvýše 255 znaků) nebo '
                            'popis do 65 535 bajtů UTF-8.'},
     'de': {'order_oldest': 'Älteste zuerst · klicken für neueste zuerst (nur '
                            'WinApp)',
            'order_newest': 'Neueste zuerst · klicken für älteste zuerst (nur '
                            'WinApp)',
            'order_unsaved': 'Die Reihenfolge gilt jetzt, konnte aber lokal '
                             'nicht gespeichert werden.',
            'menu_information': 'Informationen und Zugriff',
            'menu_local_cache': 'Lokaler Cache',
            'menu_tools': 'Bearbeiten und Werkzeuge',
            'menu_create': 'Erstellen',
            'edit_title': 'Galerietitel bearbeiten',
            'edit_description': 'Beschreibung bearbeiten',
            'edit_confirm': '✓ Speichern',
            'edit_online': 'Diese Änderung wird in der Online-Galerie '
                           'gespeichert.',
            'edit_requires_approval': 'Bearbeitung erfordert einen aktuellen '
                                      'Server und eine eigene Freigabe für '
                                      'Galerieänderungen.',
            'approve_edit': 'Galeriebearbeitung freigeben…',
            'edit_conflict': 'Die Galerie änderte sich beim Speichern. Ihr Text '
                             'bleibt erhalten und bearbeitbar.',
            'edit_uncertain': 'Speichern konnte nicht abgeschlossen werden. Ihr '
                              'Text bleibt erhalten und bearbeitbar.',
            'edit_invalid': 'Geben Sie einen nicht leeren Titel (höchstens 255 '
                            'Zeichen) oder eine Beschreibung mit höchstens '
                            '65.535 UTF-8-Bytes ein.'},
     'sv': {'order_oldest': 'Äldsta först · klicka för nyaste först (endast '
                            'WinApp)',
            'order_newest': 'Nyaste först · klicka för äldsta först (endast '
                            'WinApp)',
            'order_unsaved': 'Ordningen gäller nu men kunde inte sparas på '
                             'datorn.',
            'menu_information': 'Information och åtkomst',
            'menu_local_cache': 'Lokal cache',
            'menu_tools': 'Redigera och verktyg',
            'menu_create': 'Skapa',
            'edit_title': 'Redigera gallerititel',
            'edit_description': 'Redigera beskrivning',
            'edit_confirm': '✓ Spara',
            'edit_online': 'Ändringen sparas i onlinegalleriet.',
            'edit_requires_approval': 'Redigering kräver en aktuell server och '
                                      'separat godkännande för galleriändringar.',
            'approve_edit': 'Godkänn galleriredigering…',
            'edit_conflict': 'Galleriet ändrades medan det sparades. Texten '
                             'finns kvar och kan fortfarande redigeras.',
            'edit_uncertain': 'Det gick inte att slutföra sparandet. Texten '
                              'finns kvar och kan fortfarande redigeras.',
            'edit_invalid': 'Ange en titel som inte är tom (högst 255 tecken) '
                            'eller en beskrivning med högst 65 535 UTF-8-byte.'}}
    _CONNECTION_RECOVERY_LABELS = {
        'en': {
            'linking_reconnect_needed': 'Connection needs a new sign-in. Click Connect to continue.',
            'linking_connection_retry': 'Gallery is temporarily unavailable. Connection will retry automatically.',
            'linking_unlinked_locally': 'Disconnected on this computer. You can connect again.',
            'linking_local_remove_failed': 'Could not remove the saved connection. Check secure storage and try Connect again.',
            'linking_reconnecting': 'Preparing a new gallery sign-in…',
            'linking_disconnecting': 'Disconnecting this computer…',
            'photos_workspace_field_hide_listing': 'Hide from public listings',
        },
        'cs': {
            'linking_reconnect_needed': 'Připojení potřebuje nové přihlášení. Pokračujte tlačítkem Připojit.',
            'linking_connection_retry': 'Galerie je dočasně nedostupná. Připojení se zkusí obnovit automaticky.',
            'linking_unlinked_locally': 'Na tomto počítači odpojeno. Můžete se znovu připojit.',
            'linking_local_remove_failed': 'Uložené připojení se nepodařilo odstranit. Zkontrolujte bezpečné úložiště a zkuste Připojit znovu.',
            'linking_reconnecting': 'Připravuji nové přihlášení do galerie…',
            'linking_disconnecting': 'Odpojuji tento počítač…',
            'photos_workspace_field_hide_listing': 'Skrýt z veřejných výpisů',
        },
        'de': {
            'linking_reconnect_needed': 'Bitte erneut anmelden. Klicken Sie auf Verbinden.',
            'linking_connection_retry': 'Galerie vorübergehend nicht erreichbar. Verbindung wird automatisch erneut versucht.',
            'linking_unlinked_locally': 'Auf diesem Computer getrennt. Sie können sich erneut verbinden.',
            'linking_local_remove_failed': 'Gespeicherte Verbindung konnte nicht entfernt werden. Prüfen Sie den sicheren Speicher und versuchen Sie Verbinden erneut.',
            'linking_reconnecting': 'Neue Galerie-Anmeldung wird vorbereitet…',
            'linking_disconnecting': 'Dieser Computer wird getrennt…',
            'photos_workspace_field_hide_listing': 'Aus öffentlichen Listen ausblenden',
        },
        'sv': {
            'linking_reconnect_needed': 'Anslutningen behöver en ny inloggning. Klicka på Anslut.',
            'linking_connection_retry': 'Galleriet är tillfälligt otillgängligt. Anslutningen försöker igen automatiskt.',
            'linking_unlinked_locally': 'Frånkopplad på den här datorn. Du kan ansluta igen.',
            'linking_local_remove_failed': 'Kunde inte ta bort den sparade anslutningen. Kontrollera säker lagring och försök Anslut igen.',
            'linking_reconnecting': 'Förbereder en ny galleriinloggning…',
            'linking_disconnecting': 'Kopplar från den här datorn…',
            'photos_workspace_field_hide_listing': 'Dölj från offentliga listor',
        },
    }
    for _recovery_language, _recovery_labels in _CONNECTION_RECOVERY_LABELS.items():
        translations[_recovery_language].update(_recovery_labels)

    for _control_language, _control_labels in _GALLERY_CONTROL_LABELS.items():
        translations[_control_language].update({
            "photos_workspace_" + key: value for key, value in _control_labels.items()
        })

    _COMPLETION_LABELS = {
        'en': {'progress_autoclose': 'Completed. Closes in {seconds} s.', 'keep_open': 'Keep open'},
        'cs': {'progress_autoclose': 'Hotovo. Zavře se za {seconds} s.', 'keep_open': 'Ponechat otevřené'},
        'de': {'progress_autoclose': 'Fertig. Schließt in {seconds} s.', 'keep_open': 'Geöffnet lassen'},
        'sv': {'progress_autoclose': 'Klart. Stängs om {seconds} s.', 'keep_open': 'Behåll öppet'},
    }
    for _completion_language, _completion_labels in _COMPLETION_LABELS.items():
        translations[_completion_language].update(_completion_labels)
