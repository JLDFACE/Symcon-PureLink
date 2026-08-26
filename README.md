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
| **PureLink PT-MA-HD42UHD** | Device | HDMI-Matrix 4x2 (SCU-Firmware) | HTTP-CGI (`Control.cgi`), Port 80 |
| **PureLink PT-SW-HD41MV** | Device | HDMI 4x1 Multiview-Switch | HTTP-JSON (`/cgi-bin/instr`), Port 80 |
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

Die HDMI-Matrizen stammen dagegen von einer anderen OEM-Plattform und sprechen **kein**
`gbconfig`. Sie werden über kurze **HTTP-Requests auf `Control.cgi`** gesteuert (lwIP-Webserver).
Das Modul teilt daher nur die Konventionen (kein IO, kurzlebige Verbindung, Polling,
Pending-Logik), nicht den Transport.

Hinweis zur Protokoll-Herkunft: Das C#-Tool `Tools/FACEArtnetBridge` steuert eine **andere**
PureLink-Matrix (PT-MA-HD88DA) über `MMX32_Keyvalue.cgi` mit `{CMD=OUTxx:yy.`-Befehlen. Das
hier eingebundene 4x2-Gerät (SCU-Firmware) nutzt ein **anderes** Protokoll — siehe unten.
Beide gehören zu PureLink, teilen aber nur die Idee „HTTP-CGI ohne IO".

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

## PureLink PT-MA-HD42UHD – HDMI-Matrix 4x2 (Control.cgi)

Device-Modul für die **PureLink PT-MA-HD42UHD** (HDMI-Matrix 4x2, SCU-Firmware, lwIP-Webserver).
**Live verifiziert** (Firmware V1.0.3): 4 Eingänge, 2 Ausgänge (A/B), Auto-Switch je Ausgang,
Audio-Routing. Die Web-UI meldet sich als `SCU42(PT-MA-HD42UHD)`.

- **Transport**: roher `POST`/`GET … HTTP/1.0` via `fsockopen`, Antwort wird bis zum
  Verbindungsende gelesen. Der lwIP-Server schickt teils keine Statuszeile — das Modul
  wertet den Body dann trotzdem als Erfolg.
- **Befehle** (Klartext im POST-Body an `/Control.cgi`, verifiziert):

  | Zweck | POST-Body |
  |---|---|
  | Ausgang A auf Eingang n | `A Input1` … `A Input4` |
  | Ausgang B auf Eingang n | `B Input1` … `B Input4` |
  | Auto-Switch A / B | `A Auto On` / `A Auto Off` (analog `B`) |
  | Audio-Routing | `A De-embedded` · `B De-embedded` · `A ARC` · `B ARC` |

- **Status**: `GET /Control.cgi?Feedback` → `FeedbackData:010110`. Die Ziffernkette ist
  je Ausgang ein Paar `[Eingang][Auto]`, danach Audio und HDCP:

  | Position | Bedeutung |
  |---|---|
  | 0 | Ausgang A – Eingang (0-basiert, `0` = Input 1) |
  | 1 | Ausgang A – Auto (0/1) |
  | 2 | Ausgang B – Eingang (0-basiert) |
  | 3 | Ausgang B – Auto (0/1) |
  | 4 | Audio (1-basiert: 1=A De-embedded, 2=B De-embedded, 3=A ARC, 4=B ARC) |
  | 5 | HDCP (im Web-UI ausgeblendet, ignoriert) |

- **Auth**: `login.cgi` (Standard `admin`/`admin`) setzt nur den Cookie `SCU42=1`.
  `Control.cgi` selbst erzwingt **keine** Authentifizierung; das Modul sendet den Cookie
  trotzdem mit und kann optional einen Login absetzen.
- **Ein Verbindung zur Zeit.** Der lwIP-Server verträgt keinen Verbindungssturm: Semaphore
  je Instanz plus konfigurierbarer Mindestabstand (`MinIntervalMs`, Standard 60 ms).
- **Variablen**: je Ausgang eine schaltbare Integer-Variable (`OutA`, `OutB`, … mit
  instanz-eigenem Eingangsprofil `PLMTX.<InstanceID>.Inputs`) plus ein Auto-Switch-Boolean
  (`AutoA`, `AutoB`, …). Beim 2-Ausgang-Layout zusätzlich eine Audio-Variable.
- **Topologie konfigurierbar**: `OutputCount` erzeugt Ausgänge A, B, C … `InputCount`
  die Eingänge. **Verifiziert ist nur die 4x2.** Bei größeren SCU-Modellen (4x4/8x8) ist das
  Feedback-Layout dieser Firmware nicht gegengeprüft — vor Produktiveinsatz per
  „Rohes Feedback zeigen" (`PLMTX_QueryRaw`) validieren.
- Prefix `PLMTX_*`.

### Öffentliche Funktionen

| Funktion | Wirkung |
|---|---|
| `PLMTX_SwitchVideo($id, $out, $in)` | Ausgang (1=A, 2=B) auf Eingang (1-basiert) |
| `PLMTX_SetAutoSwitch($id, $out, $on)` | Auto-Switch je Ausgang |
| `PLMTX_SetAudioRoute($id, $mode)` | Audio 1..4 (De-embedded/ARC A/B) |
| `PLMTX_PollNow($id)` | Status abfragen und Variablen nachziehen |
| `PLMTX_TestConnection($id)` | Erreichbarkeit über Feedback |
| `PLMTX_QueryRaw($id)` | rohes `FeedbackData:` zeigen |
| `PLMTX_SendRaw($id, $body)` | beliebigen Control.cgi-Befehl senden (Diagnose) |
| `PLMTX_Login($id)` | Login absetzen (nur falls Firmware ihn verlangt) |

### Nicht per Netzwerk konfigurierbar

Die Web-UI dieser Firmware bietet **nur Steuerung** (Out A/B, Eingänge, Audio, HDCP) —
**keine Netzwerkseite**. Es existiert kein IP-/DHCP-CGI, und außer Port 80 ist nichts offen.
Die IP-Adresse lässt sich daher **nicht** über das Modul oder die Web-Oberfläche ändern;
dafür braucht es das OEM-Windows-Tool (UDP-Discovery) oder den seriellen Anschluss.

## PureLink PT-SW-HD41MV – 4x1 Multiview-Switch (HTTP-JSON)

Device-Modul für den **PureLink PT-SW-HD41MV** (4x1 HDMI-Switch mit Multiview). Moderne
Vue-Web-UI, Steuerung über eine **HTTP-JSON-API**. **Live verifiziert** (MCU 1.10.09,
Web 2.00.09).

- **Transport**: `POST /cgi-bin/instr` mit JSON-Body, roher HTTP/1.0 via `fsockopen`,
  Antwort ist `Transfer-Encoding: chunked` – das Modul entpackt die Chunks selbst.
- **Login**: `{"comhead":"set_login","username":<0|1>,"password":"…"}` → `{"result":1}`.
  `username` ist ein **Index** (0 = Admin, 1 = User), kein Klartextname. Schreibzugriffe
  brauchen einen vorherigen Login (Session ist quell-IP-gebunden).
- **Lesen**: `{"comhead":"get_video"}` (Routing/Video-State) und
  `{"comhead":"get_admin_information"}` (Modell, IP, Temperatur, Power).
- **Schalten**: `{"comhead":"set_admin_window", <kompletter Video-State mit Änderung>}`
  → `{"result":1}`. **Wichtig**: Es gibt keine Einzelfeld-Setter (`set_single_source`
  liefert `not wait comhead`). Jede Änderung sendet den **gesamten** Video-State zurück;
  das Modul holt dafür vor jedem Schreibvorgang frisch `get_video`, merged die Änderung
  und sendet alles als `set_admin_window`.
- **Video-State-Felder**: `single_source` (Eingang, 0-basiert), `auto_switch`,
  `window_type` (0=Single, 1=PiP, 2=PbP, 3=Triple, 4=Quad), `pip/pbp/triple/quad_source`
  (Arrays), diverse Modi/Aspects, `video_freeze`, `hdmi_resolution`, `hdcp_compliance`.
- **Variablen**: Quelle (Single), Auto-Switch, Multiview-Layout, Bild einfrieren, dazu
  Fenster-1..4-Quellen (je Layout relevant). Eingangsprofil `PLSW.<InstanceID>.Inputs`.
- Prefix `PLSW_*`.

### Öffentliche Funktionen

| Funktion | Wirkung |
|---|---|
| `PLSW_SelectSource($id, $in)` | Single-Quelle wählen (1-basiert) |
| `PLSW_SetWindowType($id, $type)` | Layout 0..4 (Single/PiP/PbP/Triple/Quad) |
| `PLSW_SetWindowSource($id, $win, $in)` | Quelle eines Multiview-Fensters |
| `PLSW_SetAutoSwitch($id, $on)` | Auto-Switch |
| `PLSW_SetFreeze($id, $on)` | Bild einfrieren |
| `PLSW_PollNow($id)` | Status abfragen |
| `PLSW_TestConnection($id)` | Erreichbarkeit + Modell |
| `PLSW_QueryRaw($id)` | roher `get_video` |
| `PLSW_SendRaw($id, $json)` | beliebigen JSON-Befehl senden (Diagnose) |
| `PLSW_Login($id)` | Login absetzen |

---

## Roadmap

- Configurator: MAC/OUI-Anzeige, gezieltes Rescan per Button.
- Matrix: Preset-Kommando am Gerät verifizieren; Configurator um Matrix-Erkennung erweitern.
- PTZ100: AI-Tracking-Detailparameter (Speaker/Presenter PiP, AI-Region), Fokus-Range-Anzeige.
- BYOD200: Multiview-Fenster gezielt belegen (`gblayout --start-video <name> <WinNo>`),
  Audio-Routing (`gbconfig --audio-source`), Display-Power via CEC/RS232.

---

**Autor:** FACE GmbH — IP-Symcon Modulentwicklung
