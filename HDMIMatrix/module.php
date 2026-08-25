<?php

/*
 * PureLink HDMI Matrix (SCU-Serie, z.B. 4x2) - IP-Symcon Modul
 *
 * Designentscheidungen (kurz):
 * - SymBox-sicher: kein strict_types, keine PHP8-Typen, PHP7-Syntax, alles in der Klasse.
 * - Kein IO: Steuerung ueber die HTTP-CGI-Schnittstelle der Firmware, kurzlebige
 *   Verbindung pro Aktion (roher POST/GET via fsockopen, HTTP/1.0 - der lwIP-Server
 *   der Matrix ist bei Headern eigenwillig und antwortet teils ohne Statuszeile).
 *
 * Protokoll LIVE verifiziert an der 4x2 unter 192.168.0.178 (Firmware V1.0.3, lwIP):
 *   Video schalten : POST Control.cgi   Body: "A Input1".."A Input4" / "B Input1".."B Input4"
 *   Auto-Switch    : POST Control.cgi   Body: "A Auto On" / "A Auto Off" (analog B)
 *   Audio-Routing  : POST Control.cgi   Body: "A De-embedded" | "B De-embedded" | "A ARC" | "B ARC"
 *   Status lesen   : GET  Control.cgi?Feedback  ->  "FeedbackData:010110\r\n"
 *
 *   Feedback = Ziffernkette, je Ausgang ein Paar [Eingang][Auto], danach Audio + HDCP:
 *     Pos 0 = Ausgang A: Eingang (0-basiert, 0 = Input1)
 *     Pos 1 = Ausgang A: Auto (0/1)
 *     Pos 2 = Ausgang B: Eingang (0-basiert)
 *     Pos 3 = Ausgang B: Auto (0/1)
 *     Pos 4 = Audio-Out (1-basiert: 1=A De-embedded, 2=B De-embedded, 3=A ARC, 4=B ARC)
 *     Pos 5 = HDCP (im Web-UI ausgeblendet, hier ignoriert)
 *
 * - Login (login.cgi, Standard admin/admin) setzt nur den Cookie SCU42=1; Control.cgi
 *   selbst erzwingt keine Authentifizierung. Der Cookie wird trotzdem mitgesendet.
 * - Topologie konfigurierbar: OutputCount erzeugt Ausgaenge A, B, C ... - VERIFIZIERT ist
 *   nur die 4x2 (2 Ausgaenge). Fuer groessere SCU-Modelle (4x4/8x8) ist das Feedback-Layout
 *   dieser Firmware-Familie nicht gegengeprueft; dann per Diagnose-Rohabfrage validieren.
 * - Audio-Routing gibt es in dieser Form nur bei 2 Ausgaengen (4 feste Optionen); die
 *   Audio-Variable wird nur dann angelegt.
 * - Polling: Slow/Fast Timer, FastAfterChange nach Aktionen, Pending-Logik gegen
 *   springende UI-Werte. Ein Verbindung zur Zeit (Semaphore + Mindestabstand).
 */

class PureLinkPTMAHD42UHD extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // ---- Verbindung ----
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 80);
        $this->RegisterPropertyInteger('TimeoutMs', 2000);
        $this->RegisterPropertyInteger('MinIntervalMs', 60);
        $this->RegisterPropertyString('Username', 'admin');
        $this->RegisterPropertyString('Password', 'admin');

        // ---- Topologie ----
        $this->RegisterPropertyInteger('InputCount', 4);
        $this->RegisterPropertyInteger('OutputCount', 2);
        $this->RegisterPropertyBoolean('ManageAudio', true);
        $this->RegisterPropertyString('InputNames', '[]');
        $this->RegisterPropertyString('OutputNames', '[]');

        // ---- Polling ----
        $this->RegisterPropertyInteger('PollSlow', 20);
        $this->RegisterPropertyInteger('PollFast', 3);
        $this->RegisterPropertyInteger('FastAfterChange', 15);
        $this->RegisterPropertyInteger('PendingTimeout', 8);

        // ---- Buffers ----
        $this->SetBuffer('FastUntil', '0');
        $this->SetBuffer('LastCallMs', '0');

        // ---- Diagnose-Variablen ----
        $this->RegisterVariableBoolean('Online', 'Online', '~Switch', 10);
        IPS_SetIcon($this->GetIDForIdent('Online'), 'Network');
        $this->DisableAction('Online');

        $this->RegisterVariableString('LastError', 'LastError', '~TextBox', 20);

        // ---- Timers ----
        $this->RegisterTimer('PollSlow', 0, 'PLMTX_PollNow($_IPS["TARGET"]);');
        $this->RegisterTimer('PollFast', 0, 'PLMTX_PollNow($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Destroy() loescht bei jedem Modul-Update die Instanzprofile - deshalb hier
        // immer neu aufbauen, nicht nur in Create().
        $this->SyncProfiles();
        $this->SyncVariables();

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            $this->SetStatus(201);
            $this->SetTimerInterval('PollSlow', 0);
            $this->SetTimerInterval('PollFast', 0);
            return;
        }
        $this->SetStatus(102);
        $this->UpdatePollingTimers(false);
    }

    public function Destroy()
    {
        $prefix = 'PLMTX.' . $this->InstanceID . '.';
        foreach (IPS_GetVariableProfileList() as $p) {
            if (strpos($p, $prefix) === 0) {
                @IPS_DeleteVariableProfile($p);
            }
        }
        parent::Destroy();
    }

    public function GetConfigurationForm()
    {
        return file_get_contents(__DIR__ . '/form.json');
    }

    public function RequestAction($Ident, $Value)
    {
        // Eingangswahl je Ausgang: Ident "Out<Letter>", z.B. OutA / OutB
        if (preg_match('/^Out([A-Z])$/', $Ident, $m)) {
            $idx = $this->LetterToIndex($m[1]);
            if ($idx >= 0 && $this->SwitchInput($idx, intval($Value))) {
                $this->SetPending($Ident, intval($Value));
                $this->SetValueIntegerSafe($Ident, intval($Value));
                $this->BumpFastPoll();
            }
            return;
        }
        // Auto-Switch je Ausgang: Ident "Auto<Letter>"
        if (preg_match('/^Auto([A-Z])$/', $Ident, $m)) {
            $idx = $this->LetterToIndex($m[1]);
            if ($idx >= 0 && $this->SetAuto($idx, (bool)$Value)) {
                $this->SetPending($Ident, $Value ? 1 : 0);
                $this->SetValueBooleanSafe($Ident, (bool)$Value);
                $this->BumpFastPoll();
            }
            return;
        }
        // Audio-Routing (nur 2-Ausgang-Layout)
        if ($Ident === 'Audio') {
            if ($this->SetAudio(intval($Value))) {
                $this->SetPending('Audio', intval($Value));
                $this->SetValueIntegerSafe('Audio', intval($Value));
                $this->BumpFastPoll();
            }
            return;
        }
    }

    // =========================================================================
    // Public API (Form-Buttons / Skripte / Ablaufplaene)
    // =========================================================================

    // Video-Routing: $output 1-basiert (1=A,2=B,...), $input 1-basiert (1=Input1).
    public function SwitchVideo($output, $input)
    {
        $oi = intval($output) - 1;
        if (!$this->CheckOutput($oi) || !$this->CheckInput(intval($input))) {
            return false;
        }
        if ($this->SwitchInput($oi, intval($input))) {
            $ident = 'Out' . $this->IndexToLetter($oi);
            $this->SetPending($ident, intval($input));
            $this->SetValueIntegerSafe($ident, intval($input));
            $this->BumpFastPoll();
            return true;
        }
        return false;
    }

    // Auto-Switch je Ausgang setzen. $output 1-basiert.
    public function SetAutoSwitch($output, $on)
    {
        $oi = intval($output) - 1;
        if (!$this->CheckOutput($oi)) {
            return false;
        }
        if ($this->SetAuto($oi, (bool)$on)) {
            $ident = 'Auto' . $this->IndexToLetter($oi);
            $this->SetPending($ident, $on ? 1 : 0);
            $this->SetValueBooleanSafe($ident, (bool)$on);
            $this->BumpFastPoll();
            return true;
        }
        return false;
    }

    /*
     * Audio-Routing setzen. $mode 1-basiert:
     *   1 = A De-embedded, 2 = B De-embedded, 3 = A ARC, 4 = B ARC
     */
    public function SetAudioRoute($mode)
    {
        if ($this->SetAudio(intval($mode))) {
            $this->SetPending('Audio', intval($mode));
            $this->SetValueIntegerSafe('Audio', intval($mode));
            $this->BumpFastPoll();
            return true;
        }
        return false;
    }

    // Status abfragen und Variablen nachziehen.
    public function PollNow()
    {
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }
        if (trim($this->ReadPropertyString('Host')) === '') {
            return;
        }
        if (!$this->TryLock()) {
            return;
        }
        try {
            $err    = '';
            $digits = $this->ReadFeedback($err);
            if ($digits === false) {
                $this->SetOnline(false, $err);
                return;
            }

            $outs = $this->OutputCount();
            for ($i = 0; $i < $outs; $i++) {
                $letter = $this->IndexToLetter($i);
                $posIn   = 2 * $i;
                $posAuto = 2 * $i + 1;
                if (isset($digits[$posIn])) {
                    $in = intval($digits[$posIn]) + 1; // Feedback 0-basiert -> 1-basiert
                    if ($in >= 1 && $in <= $this->InputCount()) {
                        $this->ApplyIntWithPending('Out' . $letter, $in);
                    }
                }
                if (isset($digits[$posAuto])) {
                    $this->ApplyBoolWithPending('Auto' . $letter, intval($digits[$posAuto]) ? 1 : 0);
                }
            }

            if ($this->ReadPropertyBoolean('ManageAudio') && $outs == 2) {
                $posAudio = 2 * $outs; // direkt hinter den Ausgangspaaren
                if (isset($digits[$posAudio])) {
                    $a = intval($digits[$posAudio]);
                    if ($a >= 1 && $a <= 4) {
                        $this->ApplyIntWithPending('Audio', $a);
                    }
                }
            }

            $this->ClearLastError();
        } finally {
            $this->Unlock();
        }
    }

    // Reiner Erreichbarkeitstest ueber die Feedback-Abfrage.
    public function TestConnection()
    {
        $err = '';
        $d   = $this->ReadFeedback($err);
        if ($d === false) {
            $this->SetOnline(false, $err);
            return false;
        }
        $this->ClearLastError();
        return true;
    }

    // Login absetzen (setzt serverseitig die Session). Rueckgabe: true bei Antwort "1".
    public function Login()
    {
        $u = $this->ReadPropertyString('Username');
        $p = $this->ReadPropertyString('Password');
        $err = '';
        $res = $this->HttpRequest('POST', '/login.cgi', 'username=' . rawurlencode($u) . '&password=' . rawurlencode($p), $err);
        if ($res === false) {
            $this->SetOnline(false, $err);
            return false;
        }
        return (trim($res) === '1');
    }

    // Rohes Feedback zeigen (Diagnose / Layout-Pruefung bei anderen Modellen).
    public function QueryRaw()
    {
        $err = '';
        $res = $this->HttpRequest('GET', '/Control.cgi?Feedback', '', $err);
        if ($res === false) {
            return 'FEHLER: ' . $err;
        }
        return $res;
    }

    // Rohen Befehl senden (Diagnose / unbelegte Kommandos nachmessen).
    public function SendRaw($body)
    {
        $err = '';
        $res = $this->HttpRequest('POST', '/Control.cgi', strval($body), $err);
        if ($res === false) {
            return 'FEHLER: ' . $err;
        }
        return ($res === '') ? 'OK (leere Antwort)' : $res;
    }

    // =========================================================================
    // Protokoll
    // =========================================================================

    private function SwitchInput($outIndex, $input)
    {
        $letter = $this->IndexToLetter($outIndex);
        $in     = intval($input);
        if ($letter === '' || $in < 1 || $in > $this->InputCount()) {
            $this->SetOnline(false, 'Ungueltige Video-Schaltung: Ausgang ' . $outIndex . ' Eingang ' . $in);
            return false;
        }
        return $this->PostCommand($letter . ' Input' . $in);
    }

    private function SetAuto($outIndex, $on)
    {
        $letter = $this->IndexToLetter($outIndex);
        if ($letter === '') {
            return false;
        }
        return $this->PostCommand($letter . ' Auto ' . ($on ? 'On' : 'Off'));
    }

    private function SetAudio($mode)
    {
        $map = array(
            1 => 'A De-embedded',
            2 => 'B De-embedded',
            3 => 'A ARC',
            4 => 'B ARC'
        );
        $m = intval($mode);
        if (!isset($map[$m])) {
            $this->SetOnline(false, 'Audio-Modus ausserhalb 1..4: ' . $m);
            return false;
        }
        return $this->PostCommand($map[$m]);
    }

    private function PostCommand($body)
    {
        if (!$this->TryLock()) {
            $this->SetOnline(false, 'Instanz belegt, Befehl verworfen: ' . $body);
            return false;
        }
        try {
            $err = '';
            $res = $this->HttpRequest('POST', '/Control.cgi', $body, $err);
            if ($res === false) {
                $this->SetOnline(false, $err);
                return false;
            }
            $this->ClearLastError();
            return true;
        } finally {
            $this->Unlock();
        }
    }

    // Liefert die Feedback-Ziffern als String oder false.
    private function ReadFeedback(&$err)
    {
        $res = $this->HttpRequest('GET', '/Control.cgi?Feedback', '', $err);
        if ($res === false) {
            return false;
        }
        $pos = stripos($res, 'FeedbackData:');
        if ($pos === false) {
            $err = 'Keine FeedbackData in der Antwort: ' . substr(trim($res), 0, 120);
            return false;
        }
        $tail = substr($res, $pos + strlen('FeedbackData:'));
        if (preg_match('/(\d+)/', $tail, $m)) {
            return $m[1];
        }
        $err = 'FeedbackData ohne Ziffern.';
        return false;
    }

    /*
     * Rohe HTTP/1.0-Anfrage via fsockopen. GET oder POST. Antwort wird bis zum
     * Verbindungsende gelesen (HTTP/1.0-Semantik). Liefert den Body oder false.
     * Der Session-Cookie SCU42=1 wird immer mitgesendet (schadet auch ohne Auth nicht).
     */
    private function HttpRequest($method, $path, $body, &$err)
    {
        $err  = '';
        $host = trim($this->ReadPropertyString('Host'));
        $port = intval($this->ReadPropertyInteger('Port'));
        $tmo  = intval($this->ReadPropertyInteger('TimeoutMs'));
        if ($host === '') {
            $err = 'Host nicht konfiguriert.';
            return false;
        }
        if ($port < 1 || $port > 65535) {
            $port = 80;
        }
        if ($tmo < 300) {
            $tmo = 2000;
        }

        $this->Throttle();

        $errno  = 0;
        $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, max(0.3, $tmo / 1000.0));
        if (!is_resource($fp)) {
            $this->MarkCall();
            $err = 'Verbindung fehlgeschlagen (' . intval($errno) . ') ' . strval($errstr);
            return false;
        }
        stream_set_timeout($fp, intval($tmo / 1000), (intval($tmo) % 1000) * 1000);

        $payload = strval($body);
        $req  = strtoupper($method) . ' ' . $path . " HTTP/1.0\r\n";
        $req .= 'Host: ' . $host . "\r\n";
        $req .= "Cookie: SCU42=1\r\n";
        if (strtoupper($method) === 'POST') {
            $req .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $req .= 'Content-Length: ' . strlen($payload) . "\r\n";
        }
        $req .= "Connection: close\r\n\r\n";
        if (strtoupper($method) === 'POST') {
            $req .= $payload;
        }
        @fwrite($fp, $req);

        $raw      = '';
        $deadline = microtime(true) + ($tmo / 1000.0);
        while (microtime(true) < $deadline) {
            if (feof($fp)) {
                break;
            }
            $chunk = @fread($fp, 4096);
            if ($chunk === false) {
                break;
            }
            if ($chunk === '') {
                $meta = @stream_get_meta_data($fp);
                if (is_array($meta) && !empty($meta['timed_out'])) {
                    break;
                }
                usleep(10000);
                continue;
            }
            $raw .= $chunk;
        }
        @fclose($fp);
        $this->MarkCall();

        if ($raw === '') {
            $err = 'Keine Antwort vom Geraet.';
            return false;
        }

        // Header/Body trennen (CRLF oder LF)
        $sep  = strpos($raw, "\r\n\r\n");
        $skip = 4;
        if ($sep === false) {
            $sep  = strpos($raw, "\n\n");
            $skip = 2;
        }
        $headers = ($sep !== false) ? substr($raw, 0, $sep) : '';
        $resBody = ($sep !== false) ? trim(substr($raw, $sep + $skip)) : trim($raw);

        // Statuscode aus der ersten Headerzeile (lwIP schickt teils keine)
        $status = 200;
        if ($headers !== '') {
            $first = trim(explode("\n", $headers)[0]);
            if (stripos($first, 'HTTP/') === 0) {
                $parts = explode(' ', $first);
                if (count($parts) >= 2) {
                    $status = intval($parts[1]);
                }
            }
        }
        if ($headers !== '' && ($status < 200 || $status >= 300)) {
            $err = 'HTTP ' . $status . ' - ' . substr($resBody, 0, 160);
            return false;
        }

        $this->SendDebug('HTTP ' . $method . ' ' . $path, $payload . ' -> ' . substr($resBody, 0, 400), 0);
        return $resBody;
    }

    /*
     * Der lwIP-Webserver der Matrix vertraegt keinen Verbindungssturm. Zwischen zwei
     * Calls einen Mindestabstand einhalten (z.B. beim Umschalten mehrerer Ausgaenge).
     */
    private function Throttle()
    {
        $min = intval($this->ReadPropertyInteger('MinIntervalMs'));
        if ($min <= 0) {
            return;
        }
        if ($min > 2000) {
            $min = 2000;
        }
        $last = floatval($this->GetBuffer('LastCallMs'));
        if ($last <= 0) {
            return;
        }
        $wait = $min - ((microtime(true) * 1000.0) - $last);
        if ($wait > 0 && $wait <= $min) {
            IPS_Sleep(intval(ceil($wait)));
        }
    }

    private function MarkCall()
    {
        $this->SetBuffer('LastCallMs', strval(microtime(true) * 1000.0));
    }

    // =========================================================================
    // Topologie / Profile / Variablen
    // =========================================================================

    private function InputCount()
    {
        $n = intval($this->ReadPropertyInteger('InputCount'));
        if ($n < 1) $n = 1;
        if ($n > 16) $n = 16;
        return $n;
    }

    private function OutputCount()
    {
        $n = intval($this->ReadPropertyInteger('OutputCount'));
        if ($n < 1) $n = 1;
        if ($n > 8) $n = 8;
        return $n;
    }

    private function IndexToLetter($i)
    {
        $i = intval($i);
        if ($i < 0 || $i >= $this->OutputCount()) {
            return '';
        }
        return chr(ord('A') + $i);
    }

    private function LetterToIndex($letter)
    {
        $idx = ord(strtoupper(substr($letter, 0, 1))) - ord('A');
        if ($idx < 0 || $idx >= $this->OutputCount()) {
            return -1;
        }
        return $idx;
    }

    private function CheckInput($in)
    {
        if ($in >= 1 && $in <= $this->InputCount()) {
            return true;
        }
        $this->SetOnline(false, 'Eingang ausserhalb 1..' . $this->InputCount() . ': ' . intval($in));
        return false;
    }

    private function CheckOutput($outIndex)
    {
        if ($outIndex >= 0 && $outIndex < $this->OutputCount()) {
            return true;
        }
        $this->SetOnline(false, 'Ausgang ausserhalb A..' . $this->IndexToLetter($this->OutputCount() - 1) . ': Index ' . intval($outIndex));
        return false;
    }

    private function NamesFrom($property, $count, $fallbackPrefix)
    {
        $out = array();
        for ($i = 1; $i <= $count; $i++) {
            $out[$i] = $fallbackPrefix . ' ' . $i;
        }
        $raw  = $this->ReadPropertyString($property);
        $list = @json_decode($raw, true);
        if (is_array($list)) {
            $i = 1;
            foreach ($list as $row) {
                if ($i > $count) break;
                if (is_array($row) && isset($row['Name'])) {
                    $n = trim(strval($row['Name']));
                    if ($n !== '') {
                        $out[$i] = $n;
                    }
                }
                $i++;
            }
        }
        return $out;
    }

    private function InputProfileName()
    {
        return 'PLMTX.' . $this->InstanceID . '.Inputs';
    }

    private function AudioProfileName()
    {
        return 'PLMTX.' . $this->InstanceID . '.Audio';
    }

    private function SyncProfiles()
    {
        // ---- Eingangsprofil ----
        $name  = $this->InputProfileName();
        $count = $this->InputCount();
        $names = $this->NamesFrom('InputNames', $count, 'Eingang');

        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 1);
        }
        IPS_SetVariableProfileIcon($name, 'HollowLargeArrowRight');
        IPS_SetVariableProfileText($name, '', '');
        IPS_SetVariableProfileValues($name, 1, $count, 1);
        $p = IPS_GetVariableProfile($name);
        if (isset($p['Associations']) && is_array($p['Associations'])) {
            foreach ($p['Associations'] as $a) {
                @IPS_SetVariableProfileAssociation($name, $a['Value'], '', '', -1);
            }
        }
        for ($i = 1; $i <= $count; $i++) {
            IPS_SetVariableProfileAssociation($name, $i, $names[$i], '', -1);
        }

        // ---- Audioprofil (nur 2-Ausgang-Layout) ----
        if ($this->ReadPropertyBoolean('ManageAudio') && $this->OutputCount() == 2) {
            $an = $this->AudioProfileName();
            if (!IPS_VariableProfileExists($an)) {
                IPS_CreateVariableProfile($an, 1);
            }
            IPS_SetVariableProfileIcon($an, 'Speaker');
            IPS_SetVariableProfileValues($an, 1, 4, 1);
            IPS_SetVariableProfileAssociation($an, 1, 'A De-embedded', '', -1);
            IPS_SetVariableProfileAssociation($an, 2, 'B De-embedded', '', -1);
            IPS_SetVariableProfileAssociation($an, 3, 'A ARC', '', -1);
            IPS_SetVariableProfileAssociation($an, 4, 'B ARC', '', -1);
        }
    }

    private function SyncVariables()
    {
        $profile  = $this->InputProfileName();
        $outs     = $this->OutputCount();
        $outNames = $this->NamesFrom('OutputNames', $outs, 'Ausgang');
        $audio    = (bool)$this->ReadPropertyBoolean('ManageAudio');

        for ($i = 0; $i < $outs; $i++) {
            $letter = $this->IndexToLetter($i);
            $base   = $outNames[$i + 1];

            $identOut = 'Out' . $letter;
            $this->RegisterVariableInteger($identOut, $base . ' (' . $letter . ')', $profile, 100 + $i * 2);
            $this->EnableAction($identOut);

            $identAuto = 'Auto' . $letter;
            $this->RegisterVariableBoolean($identAuto, $base . ' – Auto-Switch', '~Switch', 101 + $i * 2);
            $this->EnableAction($identAuto);
        }

        // Audio nur beim verifizierten 2-Ausgang-Layout.
        if ($audio && $outs == 2) {
            $this->RegisterVariableInteger('Audio', 'Audio-Ausgang', $this->AudioProfileName(), 200);
            $this->EnableAction('Audio');
        } else {
            $this->UnregisterVariable('Audio');
        }

        // Ueberzaehlige Variablen entfernen, wenn verkleinert wurde.
        for ($i = $outs; $i < 8; $i++) {
            $letter = chr(ord('A') + $i);
            $this->UnregisterVariable('Out' . $letter);
            $this->UnregisterVariable('Auto' . $letter);
        }
    }

    // =========================================================================
    // Polling-Timer / Fast-Poll
    // =========================================================================

    private function UpdatePollingTimers($forceFast)
    {
        $slow = intval($this->ReadPropertyInteger('PollSlow'));
        $fast = intval($this->ReadPropertyInteger('PollFast'));
        if ($slow < 5) $slow = 20;
        if ($fast < 2) $fast = 3;

        $now       = time();
        $fastUntil = intval($this->GetBuffer('FastUntil'));
        $useFast   = $forceFast || ($fastUntil > $now);

        if ($useFast) {
            $this->SetTimerInterval('PollFast', $fast * 1000);
            $this->SetTimerInterval('PollSlow', 0);
        } else {
            $this->SetTimerInterval('PollFast', 0);
            $this->SetTimerInterval('PollSlow', $slow * 1000);
        }
    }

    private function BumpFastPoll()
    {
        $sec = intval($this->ReadPropertyInteger('FastAfterChange'));
        if ($sec < 5) $sec = 15;
        $this->SetBuffer('FastUntil', strval(time() + $sec));
        $this->UpdatePollingTimers(true);
    }

    // =========================================================================
    // Pending-Logik (UI-Stabilitaet)
    // =========================================================================

    private function PendingTimeoutSec()
    {
        $t = intval($this->ReadPropertyInteger('PendingTimeout'));
        if ($t < 3) $t = 8;
        return $t;
    }

    private function SetPending($ident, $value)
    {
        $this->SetBuffer('Pend_' . $ident, json_encode(array('v' => intval($value), 'ts' => time())));
    }

    private function GetPending($ident)
    {
        $raw = $this->GetBuffer('Pend_' . $ident);
        if ($raw === null || $raw === '') return false;
        $d = @json_decode($raw, true);
        if (!is_array($d) || !isset($d['v']) || !isset($d['ts'])) return false;
        return $d;
    }

    private function ClearPending($ident)
    {
        $this->SetBuffer('Pend_' . $ident, '');
    }

    private function ApplyIntWithPending($ident, $deviceValue)
    {
        $pending = $this->GetPending($ident);
        $dv = intval($deviceValue);
        if ($pending !== false) {
            $pv  = intval($pending['v']);
            $age = time() - intval($pending['ts']);
            if ($pv === $dv) {
                $this->ClearPending($ident);
                $this->SetValueIntegerSafe($ident, $dv);
                return;
            }
            if ($age < $this->PendingTimeoutSec()) return;
            $this->ClearPending($ident);
        }
        $this->SetValueIntegerSafe($ident, $dv);
    }

    private function ApplyBoolWithPending($ident, $deviceValue)
    {
        $pending = $this->GetPending($ident);
        $dv = intval($deviceValue) ? 1 : 0;
        if ($pending !== false) {
            $pv  = intval($pending['v']) ? 1 : 0;
            $age = time() - intval($pending['ts']);
            if ($pv === $dv) {
                $this->ClearPending($ident);
                $this->SetValueBooleanSafe($ident, $dv ? true : false);
                return;
            }
            if ($age < $this->PendingTimeoutSec()) return;
            $this->ClearPending($ident);
        }
        $this->SetValueBooleanSafe($ident, $dv ? true : false);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function SetOnline($online, $err)
    {
        $this->SetValueBooleanSafe('Online', (bool)$online);
        if ($err === null) $err = '';
        $err = strval($err);
        $id = @$this->GetIDForIdent('LastError');
        if ($id > 0 && GetValueString($id) !== $err) {
            $this->SetValueStringSafe('LastError', $err);
        }
        $this->UpdatePollingTimers(false);
    }

    private function ClearLastError()
    {
        $id = @$this->GetIDForIdent('LastError');
        if ($id > 0 && GetValueString($id) !== '') {
            $this->SetValueStringSafe('LastError', '');
        }
        $this->SetValueBooleanSafe('Online', true);
    }

    private function TryLock()
    {
        $key = 'PLMTX_' . $this->InstanceID;
        for ($i = 0; $i < 10; $i++) {
            if (IPS_SemaphoreEnter($key, 100)) return true;
            IPS_Sleep(20);
        }
        return false;
    }

    private function Unlock()
    {
        @IPS_SemaphoreLeave('PLMTX_' . $this->InstanceID);
    }

    private function SetValueBooleanSafe($ident, $value)
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id > 0 && GetValueBoolean($id) !== (bool)$value) {
            SetValueBoolean($id, (bool)$value);
        }
    }

    private function SetValueIntegerSafe($ident, $value)
    {
        $id = @$this->GetIDForIdent($ident);
        $v = intval($value);
        if ($id > 0 && GetValueInteger($id) !== $v) {
            SetValueInteger($id, $v);
        }
    }

    private function SetValueStringSafe($ident, $value)
    {
        $id = @$this->GetIDForIdent($ident);
        $v = strval($value);
        if ($id > 0 && GetValueString($id) !== $v) {
            SetValueString($id, $v);
        }
    }
}
