# OCPP Central System

[![IP-Symcon is awesome!](https://img.shields.io/badge/IP--Symcon-6.4-blue.svg)](https://www.symcon.de)
[![Check Style](https://github.com/symcon/ocpp/workflows/Check%20Style/badge.svg)](https://github.com/symcon/ocpp/actions)
[![Run Tests](https://github.com/symcon/ocpp/workflows/Run%20Tests/badge.svg)](https://github.com/symcon/ocpp/actions)


Folgende Module beinhaltet das OCPP Central System Repository:

- __OCPP Splitter__ ([Dokumentation](https://www.symcon.de/de/service/dokumentation/modulreferenz/ocpp/))  
	Kümmert sich um die Kommunikation

- __OCPP Konfigurator__ ([Dokumentation](https://www.symcon.de/de/service/dokumentation/modulreferenz/ocpp/)) 
	Erleichtert die Konfiguration eines Ladepunktes

- __OCPP Charging Point__ ([Dokumentation](https://www.symcon.de/de/service/dokumentation/modulreferenz/ocpp/))
	Bildet einen Ladepunkt ab

- __OCPP SolarEdge Load Manager Controller__
	Verteilt einen gemeinsamen Stromrahmen auf mehrere SolarEdge-Ladepunkte und verwaltet wartende Fahrzeuge nach dem FIFO-Prinzip.

## Sicheres Lastmanagement

Der Lastmanager ist eine zusätzliche, standardmäßig deaktivierte Instanz. Das originale
IP-Symcon-OCPP-Modul wird nicht verändert. Die SolarEdge-Ladepunkte dieses Repositorys
unterstützen zusätzlich die OCPP-1.6-Befehle `SetChargingProfile` und
`ClearChargingProfile`.

Vor der Freigabe einer Wallbox muss einmal geprüft werden, ob sie eine 6-A-Grenze mit
`Accepted` bestätigt und tatsächlich einhält. Erst danach wird die Wallbox in der Liste
als geprüft markiert. Solange eine aktivierte Wallbox nicht geprüft ist, startet der
Lastmanager aus Sicherheitsgründen kein Fahrzeug.

### Empfohlene Einstellungen für diese Anlage

- Betriebsart: Dynamische Stromverteilung mit FIFO-Freigabe
- Maximaler Gesamtstrom: 50 A
- Sicherheitsreserve: 2 A
- Mindeststrom je Fahrzeug: 6 A
- Maximalstrom je Fahrzeug: 16 A
- Anschluss-ID: 1
- Anzahl Phasen: 3

Mit 48 A nutzbarem Strom erhalten drei Fahrzeuge bis zu 16 A, vier Fahrzeuge jeweils
12 A und acht Fahrzeuge jeweils 6 A. Ein neuntes Fahrzeug wartet, bis wieder mindestens
6 A sicher verfügbar sind. Der Lastmanager verwendet dabei die bestätigten Stromgrenzen
und nicht nur die momentane Stromaufnahme, weil ein Fahrzeug seine Aufnahme jederzeit
wieder erhöhen könnte.

### Einrichtung

1. Für jeden Ladepunkt den Start nur nach Id-Tag-Prüfung einstellen und die lokale Liste leer lassen.
2. Eine Instanz `OCPP SolarEdge Load Manager Controller` erstellen.
3. Den automatischen Betrieb zunächst ausgeschaltet lassen.
4. Jede Wallbox einzeln mit der eingebauten 6-A-Testfunktion prüfen und danach wieder 16 A setzen.
5. Nur bestätigte Wallboxen aktivieren und als `6-A-Test Accepted` markieren.
6. Erst nach Abschluss aller Tests das automatische Lastmanagement einschalten.

Hinweis: OCPP 1.6 definiert Smart Charging, die Funktion ist bei Ladestationen jedoch
optional. Deshalb ist der praktische Test jeder einzelnen Wallbox zwingend vorgesehen.
