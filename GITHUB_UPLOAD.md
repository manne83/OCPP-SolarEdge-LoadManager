# Update auf GitHub und Installation in IP-Symcon

## 1. Bestehendes Repository aktualisieren

1. `https://github.com/manne83/IPSymcon-Dreame` öffnen.
2. **Add file → Upload files** auswählen.
3. Den Inhalt des entpackten Uploadpakets in das Upload-Feld ziehen. Die
   Ordner `DreameRobot`, `DreameCoordinator`, `DreameMap` und `libs` sowie
   `library.json` müssen direkt auf der obersten Ebene liegen.
4. Vorhandene Dateien dürfen durch die Version aus dem Paket ersetzt werden.
5. Als Beschreibung `Add X60 multi-robot live map version 0.4` eintragen und
   **Commit changes** anklicken.

## 2. In IP-Symcon installieren

1. In der Verwaltungskonsole **Kerninstanzen → Modulverwaltung** öffnen.
2. **Modul hinzufügen** auswählen.
3. Die Repository-URL eintragen:
   `https://github.com/manne83/IPSymcon-Dreame`
4. Für jedes Gerät eine Instanz vom Hersteller **Dreame** und Typ
   **Dreame Saugwischroboter** erstellen.
5. Zugangsdaten eintragen und zunächst **Anmeldung testen und Geräte suchen**
   verwenden.
6. Erst nach erfolgreichem Test **Aktiv** einschalten und übernehmen.
7. In Dreamehome für jeden Roboter Raum- oder Zonen-Kurzbefehle anlegen.
8. In jeder Roboterinstanz **Dreame-Kurzbefehle anzeigen** anklicken und die
   angezeigten IDs notieren.
9. Danach eine Instanz **Dreame Mehrroboter-Koordinator** erstellen und die
   parallelen Aufträge samt Teilfläche, Kurzbefehl-ID und Abhängigkeiten anlegen.
10. In jeder Roboterinstanz zuerst **Live-Karte testen**. Anschließend eine
    Instanz **Dreame Mehrroboter-Karte** erstellen, beide Roboter auswählen und
    die Kartenansicht aktivieren.

Zugangsdaten oder vollständige Debug-Ausgaben niemals in GitHub hochladen.
