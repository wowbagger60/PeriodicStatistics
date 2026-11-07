# PeriodicStatistics

Deutsch | [English](README.md)

Modul für IP-Symcon von Ueli Schweizer / Cassanis Automation.
Präfix: `PERST`.

PeriodicStatistics berechnet Statistiken von Integer- und Float-Variablen über
frei definierbare Zeitfenster: Minimum und Maximum mit Zeitpunkt, arithmetischer
und zeitgewichteter Durchschnitt, Differenz und Integral. Es ist ein allgemeiner
Zahlenrechner, ohne Bindung an Energie, Wetter oder ein anderes Fachgebiet.

## Inhaltsverzeichnis

1. [Voraussetzungen](#1-voraussetzungen)
2. [Installation und erster Test](#2-installation-und-erster-test)
3. [Zeitfenster](#3-zeitfenster)
4. [Werte](#4-werte)
5. [Einzelvariablen](#5-einzelvariablen)
6. [Neuberechnung aus dem Archiv](#6-neuberechnung-aus-dem-archiv)
7. [Öffentliche Funktionen](#7-öffentliche-funktionen)
8. [Hinweise und Einschränkungen](#8-hinweise-und-einschränkungen)
9. [Unterstützung](#9-unterstützung)
10. [Änderungen](#10-änderungen)

## 1. Voraussetzungen

- IP-Symcon ab Version 8.1.
- Archivierung der Quellvariablen, wenn Statistiken aus historischen Daten
  berechnet werden sollen.

## 2. Installation und erster Test

1. Die Bibliothek über den Module Store oder das Module Control installieren und
   eine PeriodicStatistics-Instanz anlegen.
2. Unter «Variablen» die numerischen Quellvariablen hinzufügen und übernehmen.
3. Unterhalb der Instanz wird für jede Quellvariable eine JSON-Variable mit
   allen Werten angelegt.
4. Wenn die Quellvariablen archiviert werden: unten im Konfigurationsdialog
   «Alle Variablen» wählen und «Aus Archiv neu berechnen» klicken.
5. Optional im Bearbeitungsdialog einer Variable Werte und in der Spalte
   «Einzelvariablen» eines Zeitfensters Perioden anhaken, um Einzelvariablen
   anzulegen.

## 3. Zeitfenster

Unter «Zeitfenster» definiert jede Zeile eine Periode. Neue Instanzen beginnen
mit Tag, Woche, Monat, Jahr und Dekade, die wie jedes andere Zeitfenster
geändert oder gelöscht werden können. Gesamt wird zusätzlich immer erfasst.

| Spalte | Bedeutung |
|---|---|
| Name | Name in der JSON-Ausgabe, den Funktionen und den Einzelvariablen |
| Startdatum | Beginn der ersten Periode, um Mitternacht |
| Dauer, Einheit | Länge einer Periode, z. B. 1 Woche oder 10 Jahre |
| Wiederholen | Wiederkehrendes Zeitfenster (z. B. jeden Monat) oder ein einzelnes, festes Zeitfenster (z. B. «Heizperiode 2026/27») |
| Einzelvariablen | Einzelvariablen für dieses Zeitfenster anlegen |

Die Standard-Zeitfenster beginnen am 1. Januar 1970, ausser der Woche, die am
Montag, 5. Januar 1970, beginnt. Der Wochentag des Startdatums bestimmt, an
welchem Tag eine Woche beginnt; für eine Woche ab Sonntag den 4. Januar 1970
verwenden.

Jedes Zeitfenster erhält beim Übernehmen der Konfiguration eine versteckte,
unveränderliche ID. Statistik und Einzelvariablen werden unter dieser ID
gespeichert, ein Zeitfenster kann also ohne Datenverlust umbenannt werden. Das
Löschen eines Zeitfensters entfernt dessen Statistik und Einzelvariablen; ein
mit denselben Einstellungen neu angelegtes Zeitfenster beginnt leer. Der Name
`Alltime` ist reserviert.

Das «Aktualisierungsintervall» veröffentlicht Werte, die sich auch ohne neue
Messwerte mit der Zeit ändern, regelmässig neu: den zeitgewichteten
Durchschnitt und das Integral (Minuten, 0 = aus).

## 4. Werte

Alle Werte werden immer berechnet und in der JSON-Variable jeder Quellvariable
gespeichert.

| JSON-Schlüssel | Einzelvariable | Bedeutung |
|---|---|---|
| `min`, `max` | Minimum, Maximum | Extremwerte als `{ "value": ..., "timestamp": ... }`; optional mit Variablen für den Zeitpunkt |
| `avgArithmetic` | Durchschnitt (arithmetisch) | Mittelwert der Wertänderungen in der Periode |
| `avgTimeWeighted` | Durchschnitt (zeitgewichtet) | Jeder Wert gewichtet mit der Dauer, während der er galt |
| `delta` | Differenz | Zunahme eines Zählerstands in der Periode |
| `integral` | Integral | Fläche unter der Kurve in Wert × Stunden |

Nicht jeder Wert ist für jede Variable sinnvoll:

- **Minimum, Maximum und der zeitgewichtete Durchschnitt** eignen sich für
  Messwerte wie Temperatur, Luftfeuchtigkeit oder Leistung.
- **Arithmetischer Durchschnitt:** der Mittelwert der Wertänderungen.
  Unveränderte Aktualisierungen zählen nicht, entsprechend dem, was das Archiv
  bei Standard-Aggregation speichert. Ein Sensor, der bei schnellen Änderungen
  häufiger meldet, wird entsprechend stärker gewichtet; meist ist der
  zeitgewichtete Durchschnitt die bessere Wahl. Bis zur ersten Änderung in einer
  Periode wird der zu Periodenbeginn gültige Wert ausgegeben.
- **Differenz:** gedacht für Zählerstände, die nur steigen, etwa Energie-,
  Wasser-, Gas- oder Regenzähler. Sie summiert die Zunahmen zwischen
  aufeinanderfolgenden Werten. Ein Rückgang gilt als Zählerreset oder -wechsel:
  Der neue Wert zählt als Zunahme ab null. Für andere Variablen hat die Differenz
  keine sinnvolle Bedeutung.
- **Integral:** gedacht für Raten, etwa Leistung in W (Integral in Wh),
  Durchfluss in l/h (Integral in l) oder Regenrate in mm/h (Integral in mm). Für
  andere Variablen, etwa die Temperatur, hat es keine sinnvolle Bedeutung.

## 5. Einzelvariablen

Die Werte werden pro Quellvariable in deren Bearbeitungsdialog oder direkt in
der Liste gewählt, die Perioden in der Spalte «Einzelvariablen» der Zeitfenster
sowie mit dem Schalter «Einzelvariablen für Gesamt». Die Anzahl Variablen ist
Quellen × gewählte Werte × gewählte Perioden; der Konfigurationsdialog zeigt die
aktuelle Anzahl.

- «Zeitpunkte von Minimum und Maximum» legt für jeden gewählten Extremwert eine
  Datum/Zeit-Variable an.
- Durchschnitte und Integral sind immer Float; Minimum, Maximum und Differenz
  haben den Typ der Quelle.
- Neue Variablen übernehmen die Darstellung ihrer Quellvariable, ausser das
  Integral, dessen Einheit abweicht. Zeitpunkte verwenden `~UnixTimestamp`.
- Idents lauten `<Quell-ID>_<Periode>_<Wert>`, bei Zeitfenstern mit der
  unveränderlichen ID. Das Umbenennen einer Quelle oder eines Zeitfensters
  benennt generierte Namen mit um, lässt selbst geänderte Namen aber unberührt.
- Wird ein Wert oder eine Periode abgewählt, werden die zugehörigen Variablen
  samt Archivdaten gelöscht.

Wird eine Quellvariable gelöscht, stoppt deren Statistik und die Instanz meldet
eine fehlende Variable; alle anderen Variablen laufen weiter. Ausgaben und
Statistik bleiben erhalten, bis die Zeile aus der Konfiguration entfernt wird.

## 6. Neuberechnung aus dem Archiv

Um die Statistik aus archivierten Daten zu berechnen, etwa nach dem Anlegen der
Instanz oder dem Hinzufügen einer Variable, unten im Konfigurationsdialog eine
Variable oder «Alle Variablen» wählen und «Aus Archiv neu berechnen» klicken.
Die Statistik der gewählten Variablen wird durch Werte ersetzt, die aus dem
Archiv berechnet werden. Die Liste der Archivjobs darunter zeigt den Fortschritt
und wird bei geöffnetem Dialog laufend aktualisiert.

- Die Archivierung muss für die Quellvariable aktiviert sein.
- Jede Neuberechnung liest die gesamte Archivhistorie der Variable, asynchron in
  Seiten zu 5000 Datensätzen. Aktualisierungen während der Neuberechnung werden
  danach nachgetragen.
- Pro Variable kann nur eine Neuberechnung ausstehen. Ausstehende
  Neuberechnungen laufen nach einem Neustart von Symcon weiter; das Übernehmen
  der Instanzkonfiguration bricht sie ab.
- Abgeschlossene Jobs werden nach einer Stunde aus der Liste entfernt.

## 7. Öffentliche Funktionen

`$instanceID` und `$variableID` durch die ID der Instanz bzw. der Quellvariable
ersetzen.

```php
$values = PERST_GetValuesByVariable($instanceID, $variableID);
$all = PERST_GetValuesByVariable($instanceID, 0); // alle Quellvariablen, nach ID
$period = PERST_GetValuesByPeriod($instanceID, 'Monat');

// Neuberechnungen liefern ein Token und laufen asynchron.
$token = PERST_RebuildFromArchive($instanceID, $variableID);
$status = PERST_GetJobStatus($instanceID, $token);
$jobs = PERST_GetJobs($instanceID);
```

Perioden werden über ihren Namen angesprochen, ohne Beachtung der Gross- und
Kleinschreibung. Scripts, die einen Periodennamen verwenden, müssen beim
Umbenennen des Zeitfensters angepasst werden.

Der Jobstatus meldet `queued`, `running`, `finished` oder `failed`, dazu die
Anzahl verarbeiteter Datensätze und eine Meldung.

Weitere Funktionen:

| Funktion | Zweck |
|---|---|
| `PERST_ProcessValue($instanceID, $variableID, $periodName, $value, $timestamp)` | Verarbeitet einen einzelnen Wert für eine Periode |
| `PERST_ResetValue($instanceID, $variableID, $periodName, $extreme)` | Setzt Werte einer Periode zurück |

`PERST_ResetValue` akzeptiert `min`, `max`, `aggregates` (Durchschnitte,
Differenz und Integral) oder eine leere Zeichenkette (alles). Zurückgesetzte
Extremwerte bleiben `null`, bis der nächste Wert eintrifft. Zurückgesetzte
Aggregate einer laufenden Periode beginnen neu mit dem aktuellen Wert zum
aktuellen Zeitpunkt; bei einer abgeschlossenen Periode werden sie `null`.

## 8. Hinweise und Einschränkungen

- Zeitgewichtete Durchschnitte und Integrale verwenden den zuletzt bekannten
  Wert bis zum nächsten Wert oder zum Periodenende.
- War die Archivierung unterbrochen, während ein Wert ungleich null galt, zählt
  dieser Wert für die ganze Lücke. Einen korrigierenden Wert ins Archiv einfügen
  und die Variable neu berechnen.
- Vor dem ersten bekannten Wert wird nichts angenommen. Eine früher begonnene
  Periode wird ab diesem Wert gewichtet; eine Periode, die vor dem ersten
  archivierten Wert endete, erhält keinen Durchschnitt. Ohne Archivdaten beginnt
  eine neue Instanz mit dem aktuellen Wert ab dessen letzter Änderung.
- Verschiedene Werte mit identischem Zeitstempel und Wert in derselben Sekunde
  lassen sich beim Nachtragen der Aktualisierungen nicht immer unterscheiden.
- Ein harter Abbruch während einer Neuberechnung kann zwischengespeicherte
  Aktualisierungen verlieren; dann die Neuberechnung wiederholen.

## 9. Unterstützung

Dieses Modul ist kostenlos. Schenkungen als Unterstützung für den Autor werden
gerne angenommen: PayPal an ueli.schweizer@cassanis.fr

## 10. Änderungen

**1.0 Build 1:** Erstveröffentlichung.
