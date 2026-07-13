# Symcon-PureLink

IP-Symcon Modul-Library für **PureLink**-Geräte. Ein Repository, mehrere Geräte-Module –
Struktur analog zu Symcon-JustAddPower.

Alle Module sind **SymBox-kompatibel** und arbeiten **ohne IO-Instanz** (kurzlebige
Verbindungen pro Aktion).

---

## Enthaltene Module

| Modul | Typ | Gerät / Zweck | Transport |
|-------|-----|---------------|-----------|
| **PureLink VL-BYOD200** | Device | 4K60 BYOD/BYOM Presentation Switcher | Telnet-CLI (`gbconfig`/`gblayout`), Port 23 |
| **PureLink VL-PTZ100** | Device | Compact 4K Dual-Lens PTZ Kamera | Telnet-CLI (`gbconfig`/`gbcontrol`), Port 23 |
| **PureLink Configurator** | Configurator | Netzwerk-Discovery + Instanz-Anlage | HTTPS-Titel-Erkennung |

---

## Plattform-Hintergrund

BYOD200 und PTZ100 stammen von derselben OEM-Plattform (gemeinsame MAC-OUI `34-1b-22`)
und werden beide über eine **Telnet-CLI** gesteuert (`gbconfig` / `gbcontrol` / `gblayout`).
Die offizielle API-Doku (PureLink „API Manual") beschreibt diese CLI. Beide Geräte-Module
teilen daher denselben Telnet-Transport (kurzlebige Verbindung pro Aktion, IAC-fähig).

- **BYOD200**: Telnet auf **Port 23**, ohne Login (Prompt `~ #` erscheint direkt). Der
  Login-Handshake ist optional implementiert – falls eine Firmware `username:`/`password:`
  abfragt, werden die konfigurierten Zugangsdaten gesendet. (Hinweis: Das API-Manual nennt
  Port 24; die getestete Firmware V1.1.3 nutzt jedoch Port 23.)
- **PTZ100**: Telnet auf **Port 23**, Login Benutzer `admin` mit leerem Passwort.

Hinweis: Die BYOD200 besitzt zusätzlich eine interne HTTP-`/action`-API (vom Web-UI genutzt);
diese wird nicht mehr verwendet, da die Telnet-CLI die dokumentierte, unterstützte Schnittstelle ist.

---

## Installation

1. Repository über **Module Control** hinzufügen (GitHub-URL).
2. Gewünschte Module installieren.
3. Entweder den **Configurator** anlegen und das Netz scannen lassen, oder direkt eine
   Geräte-Instanz anlegen und Host/IP eintragen.

---

## Configurator

Der Configurator scannt das lokale `/24`-Subnetz (Port 443, paralleler Connect-Scan) und
erkennt PureLink-Geräte am Web-Titel (`VL-BYOD200` / `VL-PTZ100`). Gefundene Geräte werden
mit Modell, IP und Status („neu" / „angelegt") gelistet und lassen sich per Klick als
Instanz anlegen (Host wird vorbelegt).

- **Subnetz-Basis**: leer = automatische Ermittlung aus der Server-IP, sonst z. B.
  `192.168.2` eintragen.

---

## VL-BYOD200 (Telnet-CLI)

- Login: Telnet **Port 23**, ohne Zugangsdaten.
- Befehle: `gbconfig --video-source {usbc|hdmi|guide}` (Vollbild/Guide), `gbconfig --multiview {y|n}`,
  `gblayout --set <LayoutID>`, `gbcontrol --reboot`; Query via `gbconfig -s <item>`
  (`device-info`, `name`, `video-source`, `multiview`, `input-video usbc|hdmi`).
- Funktionen: Quellenwahl (Vollbild), Multiview, Guide-Screen, Layout, Reboot, Status-Polling.
- Prefix `PLBYOD_*`. Statusvariablen-Idents unverändert (bestehende Instanzen bleiben kompatibel).

## VL-PTZ100 (Telnet-CLI)

- Login: Telnet Port 23, Benutzer `admin`, **leeres Passwort**.
- Befehle: `gbconfig` (Konfig/Steuerung), `gbcontrol` (Aktionen), Query via `gbconfig -s <item>`.
- Funktionen: Pan/Tilt (`--camera-autocoord`), Zoom, Presets 1–9, Tracking-Modus
  (Auto-Framing/Speaker/Presenter/Gallery), Autofokus, Fokus, HDMI-Out, Privacy, Reboot.
- **Wichtig**: Manuelles PTZ nur bei Tracking-Modus `0` (aus). Das Modul schaltet auf Wunsch
  automatisch um (Option „Bei manueller Steuerung Tracking automatisch abschalten").
- Prefix `PLPTZ_*`.

---

## Roadmap

- Configurator: MAC/OUI-Anzeige, gezieltes Rescan per Button.
- PTZ100: AI-Tracking-Detailparameter (Speaker/Presenter PiP, AI-Region), Fokus-Range-Anzeige.
- BYOD200: Multiview-Fenster gezielt belegen (`gblayout --start-video <name> <WinNo>`),
  Audio-Routing (`gbconfig --audio-source`), Display-Power via CEC/RS232.

---

**Autor:** FACE GmbH — IP-Symcon Modulentwicklung
