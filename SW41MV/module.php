<?php

/*
 * PureLink PT-SW-HD41MV - 4x1 HDMI-Multiview-Switch - IP-Symcon Modul
 *
 * Designentscheidungen (kurz):
 * - SymBox-sicher: kein strict_types, keine PHP8-Typen, PHP7-Syntax, alles in der Klasse.
 * - Kein IO: Steuerung ueber die HTTP-JSON-API der Firmware (Vue-Web-UI), kurzlebige
 *   Verbindung pro Aktion (roher POST via fsockopen, HTTP/1.0).
 *
 * Protokoll LIVE verifiziert am Geraet (Modell PT-SW-HD41MV, MCU 1.10.09, Web 2.00.09):
 *   Login       : POST /cgi-bin/instr  {"comhead":"set_login","username":0,"password":"admin"} -> {"result":1}
 *                 username ist ein INDEX: 0 = Admin, 1 = User (kein Klartext-Benutzername).
 *   Video lesen : POST /cgi-bin/instr  {"comhead":"get_video"} -> voller Video-State
 *   Info lesen  : POST /cgi-bin/instr  {"comhead":"get_admin_information"} -> model_name, temperature, power, ip ...
 *   Schalten    : POST /cgi-bin/instr  {"comhead":"set_admin_window", <KOMPLETTER Video-State mit Aenderung>} -> {"result":1}
 *
 * WICHTIG: Das Geraet kennt KEINE Einzelfeld-Setter (set_single_source -> "not wait comhead").
 * Jede Aenderung laeuft ueber set_admin_window und MUSS den gesamten Video-State zuruecksenden.
 * Deshalb: vor jedem Schreibvorgang get_video holen, gewuenschtes Feld mergen, alles senden.
 * Schreibzugriffe erfordern einen vorherigen Login (Session ist quell-IP-gebunden).
 *
 * Video-State-Felder (get_video):
 *   auto_switch(0/1), window_type(0=Single,1=PiP,2=PbP,3=Triple,4=Quad),
 *   single_source(0-basiert), pip_source[2], pbp_source[2], triple_source[3], quad_source[4],
 *   pip_position, pip_size, pbp_mode/aspect, triple_mode/aspect, quad_mode/aspect,
 *   window_border_color[4], hdmi_resolution, hdcp_compliance, video_freeze.
 */

class PureLinkPTSWHD41MV extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // ---- Verbindung ----
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 80);
        $this->RegisterPropertyInteger('TimeoutMs', 3000);
        $this->RegisterPropertyInteger('MinIntervalMs', 60);
        $this->RegisterPropertyInteger('UserRole', 0); // 0 = Admin, 1 = User
        $this->RegisterPropertyString('Password', 'admin');

        // ---- Topologie ----
        $this->RegisterPropertyInteger('InputCount', 4);
        $this->RegisterPropertyString('InputNames', '[]');

        // ---- Polling ----
        $this->RegisterPropertyInteger('PollSlow', 15);
        $this->RegisterPropertyInteger('PollFast', 3);
        $this->RegisterPropertyInteger('FastAfterChange', 12);
        $this->RegisterPropertyInteger('PendingTimeout', 8);

        // ---- Buffers ----
        $this->SetBuffer('FastUntil', '0');
        $this->SetBuffer('LastCallMs', '0');

        // ---- Diagnose ----
        $this->RegisterVariableBoolean('Online', 'Online', '~Switch', 10);
        IPS_SetIcon($this->GetIDForIdent('Online'), 'Network');
        $this->DisableAction('Online');
        $this->RegisterVariableString('LastError', 'LastError', '~TextBox', 20);
        $this->RegisterVariableString('Model', 'Modell', '~TextBox', 30);

        // ---- Timers ----
        $this->RegisterTimer('PollSlow', 0, 'PLSW_PollNow($_IPS["TARGET"]);');
        $this->RegisterTimer('PollFast', 0, 'PLSW_PollNow($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

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
        $prefix = 'PLSW.' . $this->InstanceID . '.';
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
        if ($Ident === 'Source') {
            $in = intval($Value);
            if ($this->WriteField(array('single_source' => $in - 1))) {
                $this->SetPending('Source', $in);
                $this->SetValueIntegerSafe('Source', $in);
                $this->BumpFastPoll();
            }
            return;
        }
        if ($Ident === 'AutoSwitch') {
            if ($this->WriteField(array('auto_switch' => $Value ? 1 : 0))) {
                $this->SetPending('AutoSwitch', $Value ? 1 : 0);
                $this->SetValueBooleanSafe('AutoSwitch', (bool)$Value);
                $this->BumpFastPoll();
            }
            return;
        }
        if ($Ident === 'WindowType') {
            $wt = intval($Value);
            if ($wt >= 0 && $wt <= 4 && $this->WriteField(array('window_type' => $wt))) {
                $this->SetPending('WindowType', $wt);
                $this->SetValueIntegerSafe('WindowType', $wt);
                $this->BumpFastPoll();
            }
            return;
        }
        if ($Ident === 'Freeze') {
            if ($this->WriteField(array('video_freeze' => $Value ? 1 : 0))) {
                $this->SetPending('Freeze', $Value ? 1 : 0);
                $this->SetValueBooleanSafe('Freeze', (bool)$Value);
                $this->BumpFastPoll();
            }
            return;
        }
        if (preg_match('/^Win(\d+)Source$/', $Ident, $m)) {
            $this->SetWindowSource(intval($m[1]), intval($Value));
            return;
        }
    }

    // =========================================================================
    // Public API
    // =========================================================================

    // Einzelquelle (Single-Modus) waehlen. $input 1-basiert.
    public function SelectSource($input)
    {
        $in = intval($input);
        if (!$this->CheckInput($in)) {
            return false;
        }
        if ($this->WriteField(array('single_source' => $in - 1))) {
            $this->SetPending('Source', $in);
            $this->SetValueIntegerSafe('Source', $in);
            $this->BumpFastPoll();
            return true;
        }
        return false;
    }

    public function SetAutoSwitch($on)
    {
        if ($this->WriteField(array('auto_switch' => $on ? 1 : 0))) {
            $this->SetValueBooleanSafe('AutoSwitch', (bool)$on);
            $this->BumpFastPoll();
            return true;
        }
        return false;
    }

    // Multiview-Layout: 0=Single, 1=PiP, 2=PbP, 3=Triple, 4=Quad
    public function SetWindowType($type)
    {
        $wt = intval($type);
        if ($wt < 0 || $wt > 4) {
            $this->SetOnline(false, 'Window-Type ausserhalb 0..4: ' . $wt);
            return false;
        }
        if ($this->WriteField(array('window_type' => $wt))) {
            $this->SetValueIntegerSafe('WindowType', $wt);
            $this->BumpFastPoll();
            return true;
        }
        return false;
    }

    public function SetFreeze($on)
    {
        if ($this->WriteField(array('video_freeze' => $on ? 1 : 0))) {
            $this->SetValueBooleanSafe('Freeze', (bool)$on);
            $this->BumpFastPoll();
            return true;
        }
        return false;
    }

    // Quelle eines Multiview-Fensters setzen. $window 1-basiert (Fenster 1..n je Layout),
    // $input 1-basiert. Aktualisiert das zum aktuellen window_type passende Quell-Array.
    public function SetWindowSource($window, $input)
    {
        $win = intval($window);
        $in  = intval($input);
        if (!$this->CheckInput($in)) {
            return false;
        }
        $state = $this->GetVideoState();
        if ($state === false) {
            return false;
        }
        $key = $this->SourceArrayKey(intval($state['window_type']));
        if ($key === '') {
            $this->SetOnline(false, 'Im aktuellen Layout gibt es keine Fensterquellen (Single-Modus).');
            return false;
        }
        $arr = isset($state[$key]) && is_array($state[$key]) ? array_values($state[$key]) : array();
        if ($win < 1 || $win > count($arr)) {
            $this->SetOnline(false, 'Fenster ' . $win . ' im Layout nicht vorhanden.');
            return false;
        }
        $arr[$win - 1] = $in - 1;
        if ($this->WriteField(array($key => $arr))) {
            $this->SetPending('Win' . $win . 'Source', $in);
            $this->SetValueIntegerSafe('Win' . $win . 'Source', $in);
            $this->BumpFastPoll();
            return true;
        }
        return false;
    }

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
            $err   = '';
            $video = $this->PostJson(array('comhead' => 'get_video'), $err, false);
            if ($video === false || !isset($video['single_source'])) {
                $this->SetOnline(false, $err !== '' ? $err : 'Keine Video-Daten.');
                return;
            }

            $this->ApplyIntWithPending('Source', intval($video['single_source']) + 1);
            $this->ApplyBoolWithPending('AutoSwitch', intval($video['auto_switch']) ? 1 : 0);
            $this->ApplyIntWithPending('WindowType', intval($video['window_type']));
            $this->ApplyBoolWithPending('Freeze', intval($video['video_freeze']) ? 1 : 0);

            // Multiview-Fensterquellen aus dem passenden Array
            $key = $this->SourceArrayKey(intval($video['window_type']));
            $arr = ($key !== '' && isset($video[$key]) && is_array($video[$key])) ? array_values($video[$key]) : array();
            for ($w = 1; $w <= 4; $w++) {
                $ident = 'Win' . $w . 'Source';
                if ($w <= count($arr)) {
                    $this->ApplyIntWithPending($ident, intval($arr[$w - 1]) + 1);
                }
            }

            // Geraeteinfo (best effort)
            $info = $this->PostJson(array('comhead' => 'get_admin_information'), $err, false);
            if (is_array($info) && isset($info['model_name'])) {
                $this->SetValueStringSafe('Model', strval($info['model_name']));
            }

            $this->ClearLastError();
        } finally {
            $this->Unlock();
        }
    }

    public function TestConnection()
    {
        $err  = '';
        $info = $this->PostJson(array('comhead' => 'get_admin_information'), $err, false);
        if (is_array($info) && isset($info['model_name'])) {
            $this->SetValueStringSafe('Model', strval($info['model_name']));
            $this->ClearLastError();
            return true;
        }
        $this->SetOnline(false, $err !== '' ? $err : 'Keine Antwort.');
        return false;
    }

    // Login absetzen (Rueckgabe true bei result==1).
    public function Login()
    {
        $err = '';
        $r = $this->PostJson(array(
            'comhead'  => 'set_login',
            'username' => intval($this->ReadPropertyInteger('UserRole')),
            'password' => strval($this->ReadPropertyString('Password'))
        ), $err, false);
        return (is_array($r) && isset($r['result']) && intval($r['result']) === 1);
    }

    // Rohes get_video als lesbaren String (Diagnose).
    public function QueryRaw()
    {
        $err = '';
        $r = $this->PostJson(array('comhead' => 'get_video'), $err, true);
        return ($r === false) ? ('FEHLER: ' . $err) : $r;
    }

    // Beliebigen JSON-Befehl senden (Diagnose). $json = JSON-String.
    public function SendRaw($json)
    {
        $obj = @json_decode(strval($json), true);
        if (!is_array($obj)) {
            return 'FEHLER: kein gueltiges JSON.';
        }
        $err = '';
        $r = $this->PostJson($obj, $err, true);
        return ($r === false) ? ('FEHLER: ' . $err) : $r;
    }

    // =========================================================================
    // Protokoll
    // =========================================================================

    private function SourceArrayKey($windowType)
    {
        switch (intval($windowType)) {
            case 1: return 'pip_source';
            case 2: return 'pbp_source';
            case 3: return 'triple_source';
            case 4: return 'quad_source';
            default: return ''; // 0 = Single -> single_source
        }
    }

    // Aktuellen Video-State holen (ohne comhead-Feld). false bei Fehler.
    private function GetVideoState()
    {
        $err = '';
        $v = $this->PostJson(array('comhead' => 'get_video'), $err, false);
        if ($v === false || !isset($v['single_source'])) {
            $this->SetOnline(false, $err !== '' ? $err : 'get_video fehlgeschlagen.');
            return false;
        }
        unset($v['comhead']);
        return $v;
    }

    /*
     * Ein oder mehrere Felder schreiben: Login, aktuellen Voll-State holen, mergen,
     * als set_admin_window zuruecksenden. $changes = assoziatives Array feld=>wert.
     */
    private function WriteField($changes)
    {
        if (!$this->TryLock()) {
            $this->SetOnline(false, 'Instanz belegt, Befehl verworfen.');
            return false;
        }
        try {
            if (!$this->Login()) {
                $this->SetOnline(false, 'Login fehlgeschlagen.');
                return false;
            }
            $state = $this->GetVideoState();
            if ($state === false) {
                return false;
            }
            foreach ($changes as $k => $v) {
                $state[$k] = $v;
            }
            $state['comhead'] = 'set_admin_window';
            $err = '';
            $r = $this->PostJson($state, $err, false);
            if (is_array($r) && isset($r['result']) && intval($r['result']) === 1) {
                $this->ClearLastError();
                return true;
            }
            $msg = is_array($r) ? json_encode($r) : $err;
            $this->SetOnline(false, 'Schreiben fehlgeschlagen: ' . $msg);
            return false;
        } finally {
            $this->Unlock();
        }
    }

    /*
     * POST eines JSON-Objekts an /cgi-bin/instr. $raw=true liefert den Body als String,
     * sonst als dekodiertes Array. false bei Transportfehler.
     */
    private function PostJson($obj, &$err, $raw)
    {
        $err  = '';
        $host = trim($this->ReadPropertyString('Host'));
        $port = intval($this->ReadPropertyInteger('Port'));
        $tmo  = intval($this->ReadPropertyInteger('TimeoutMs'));
        if ($host === '') { $err = 'Host nicht konfiguriert.'; return false; }
        if ($port < 1 || $port > 65535) $port = 80;
        if ($tmo < 300) $tmo = 3000;

        $this->Throttle();

        $payload = json_encode($obj);
        $errno = 0; $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, max(0.3, $tmo / 1000.0));
        if (!is_resource($fp)) {
            $this->MarkCall();
            $err = 'Verbindung fehlgeschlagen (' . intval($errno) . ') ' . strval($errstr);
            return false;
        }
        stream_set_timeout($fp, intval($tmo / 1000), (intval($tmo) % 1000) * 1000);

        $req  = "POST /cgi-bin/instr HTTP/1.0\r\n";
        $req .= 'Host: ' . $host . "\r\n";
        $req .= "Content-Type: application/json\r\n";
        $req .= 'Content-Length: ' . strlen($payload) . "\r\n";
        $req .= "Connection: close\r\n\r\n";
        $req .= $payload;
        @fwrite($fp, $req);

        $raw_resp = '';
        $deadline = microtime(true) + ($tmo / 1000.0);
        while (microtime(true) < $deadline) {
            if (feof($fp)) break;
            $chunk = @fread($fp, 4096);
            if ($chunk === false) break;
            if ($chunk === '') {
                $meta = @stream_get_meta_data($fp);
                if (is_array($meta) && !empty($meta['timed_out'])) break;
                usleep(10000);
                continue;
            }
            $raw_resp .= $chunk;
        }
        @fclose($fp);
        $this->MarkCall();

        if ($raw_resp === '') { $err = 'Keine Antwort vom Geraet.'; return false; }

        $sep  = strpos($raw_resp, "\r\n\r\n"); $skip = 4;
        if ($sep === false) { $sep = strpos($raw_resp, "\n\n"); $skip = 2; }
        $headers = ($sep !== false) ? substr($raw_resp, 0, $sep) : '';
        $body    = ($sep !== false) ? trim(substr($raw_resp, $sep + $skip)) : trim($raw_resp);

        // Transfer-Encoding: chunked entpacken (lwIP/httpd der Firmware nutzt chunked)
        if (stripos($headers, 'chunked') !== false) {
            $body = $this->DechunkBody($body);
        }

        $this->SendDebug('POST', $payload . ' -> ' . substr($body, 0, 400), 0);

        if ($raw) return $body;

        $data = @json_decode($body, true);
        if (!is_array($data)) {
            $err = 'Antwort kein JSON: ' . substr($body, 0, 160);
            return false;
        }
        return $data;
    }

    private function DechunkBody($body)
    {
        $out = '';
        $pos = 0;
        $len = strlen($body);
        while ($pos < $len) {
            $nl = strpos($body, "\r\n", $pos);
            if ($nl === false) break;
            $sizeHex = trim(substr($body, $pos, $nl - $pos));
            // evtl. chunk-extensions abschneiden
            $semi = strpos($sizeHex, ';');
            if ($semi !== false) $sizeHex = substr($sizeHex, 0, $semi);
            $size = hexdec($sizeHex);
            if ($size === 0 || $sizeHex === '') break;
            $start = $nl + 2;
            $out .= substr($body, $start, $size);
            $pos = $start + $size + 2; // ueberspringe CRLF nach dem Chunk
        }
        return ($out !== '') ? $out : $body;
    }

    private function Throttle()
    {
        $min = intval($this->ReadPropertyInteger('MinIntervalMs'));
        if ($min <= 0) return;
        if ($min > 2000) $min = 2000;
        $last = floatval($this->GetBuffer('LastCallMs'));
        if ($last <= 0) return;
        $wait = $min - ((microtime(true) * 1000.0) - $last);
        if ($wait > 0 && $wait <= $min) IPS_Sleep(intval(ceil($wait)));
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

    private function CheckInput($in)
    {
        if ($in >= 1 && $in <= $this->InputCount()) return true;
        $this->SetOnline(false, 'Eingang ausserhalb 1..' . $this->InputCount() . ': ' . intval($in));
        return false;
    }

    private function NamesFrom($property, $count, $fallbackPrefix)
    {
        $out = array();
        for ($i = 1; $i <= $count; $i++) $out[$i] = $fallbackPrefix . ' ' . $i;
        $list = @json_decode($this->ReadPropertyString($property), true);
        if (is_array($list)) {
            $i = 1;
            foreach ($list as $row) {
                if ($i > $count) break;
                if (is_array($row) && isset($row['Name'])) {
                    $n = trim(strval($row['Name']));
                    if ($n !== '') $out[$i] = $n;
                }
                $i++;
            }
        }
        return $out;
    }

    private function InputProfileName()  { return 'PLSW.' . $this->InstanceID . '.Inputs'; }
    private function WindowProfileName()  { return 'PLSW.' . $this->InstanceID . '.Window'; }

    private function SyncProfiles()
    {
        $name  = $this->InputProfileName();
        $count = $this->InputCount();
        $names = $this->NamesFrom('InputNames', $count, 'Eingang');
        if (!IPS_VariableProfileExists($name)) IPS_CreateVariableProfile($name, 1);
        IPS_SetVariableProfileIcon($name, 'HollowLargeArrowRight');
        IPS_SetVariableProfileValues($name, 1, $count, 1);
        $p = IPS_GetVariableProfile($name);
        if (isset($p['Associations']) && is_array($p['Associations'])) {
            foreach ($p['Associations'] as $a) @IPS_SetVariableProfileAssociation($name, $a['Value'], '', '', -1);
        }
        for ($i = 1; $i <= $count; $i++) IPS_SetVariableProfileAssociation($name, $i, $names[$i], '', -1);

        $wn = $this->WindowProfileName();
        if (!IPS_VariableProfileExists($wn)) IPS_CreateVariableProfile($wn, 1);
        IPS_SetVariableProfileIcon($wn, 'Layout');
        IPS_SetVariableProfileValues($wn, 0, 4, 1);
        IPS_SetVariableProfileAssociation($wn, 0, 'Single', '', -1);
        IPS_SetVariableProfileAssociation($wn, 1, 'PiP', '', -1);
        IPS_SetVariableProfileAssociation($wn, 2, 'PbP', '', -1);
        IPS_SetVariableProfileAssociation($wn, 3, 'Triple', '', -1);
        IPS_SetVariableProfileAssociation($wn, 4, 'Quad', '', -1);
    }

    private function SyncVariables()
    {
        $inProfile = $this->InputProfileName();

        $this->RegisterVariableInteger('Source', 'Quelle (Single)', $inProfile, 100);
        $this->EnableAction('Source');

        $this->RegisterVariableBoolean('AutoSwitch', 'Auto-Switch', '~Switch', 110);
        $this->EnableAction('AutoSwitch');

        $this->RegisterVariableInteger('WindowType', 'Multiview-Layout', $this->WindowProfileName(), 120);
        $this->EnableAction('WindowType');

        $this->RegisterVariableBoolean('Freeze', 'Bild einfrieren', '~Switch', 130);
        $this->EnableAction('Freeze');

        // Multiview-Fensterquellen (bis 4). Nur im jeweiligen Layout relevant.
        for ($w = 1; $w <= 4; $w++) {
            $ident = 'Win' . $w . 'Source';
            $this->RegisterVariableInteger($ident, 'Fenster ' . $w . ' Quelle', $inProfile, 140 + $w);
            $this->EnableAction($ident);
        }
    }

    // =========================================================================
    // Polling / Fast-Poll
    // =========================================================================

    private function UpdatePollingTimers($forceFast)
    {
        $slow = intval($this->ReadPropertyInteger('PollSlow'));
        $fast = intval($this->ReadPropertyInteger('PollFast'));
        if ($slow < 5) $slow = 15;
        if ($fast < 2) $fast = 3;
        $now = time();
        $fastUntil = intval($this->GetBuffer('FastUntil'));
        $useFast = $forceFast || ($fastUntil > $now);
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
        if ($sec < 5) $sec = 12;
        $this->SetBuffer('FastUntil', strval(time() + $sec));
        $this->UpdatePollingTimers(true);
    }

    // =========================================================================
    // Pending-Logik
    // =========================================================================

    private function PendingTimeoutSec()
    {
        $t = intval($this->ReadPropertyInteger('PendingTimeout'));
        if ($t < 3) $t = 8;
        return $t;
    }
    private function SetPending($ident, $value) { $this->SetBuffer('Pend_' . $ident, json_encode(array('v' => intval($value), 'ts' => time()))); }
    private function GetPending($ident)
    {
        $raw = $this->GetBuffer('Pend_' . $ident);
        if ($raw === null || $raw === '') return false;
        $d = @json_decode($raw, true);
        if (!is_array($d) || !isset($d['v']) || !isset($d['ts'])) return false;
        return $d;
    }
    private function ClearPending($ident) { $this->SetBuffer('Pend_' . $ident, ''); }

    private function ApplyIntWithPending($ident, $deviceValue)
    {
        $pending = $this->GetPending($ident); $dv = intval($deviceValue);
        if ($pending !== false) {
            $pv = intval($pending['v']); $age = time() - intval($pending['ts']);
            if ($pv === $dv) { $this->ClearPending($ident); $this->SetValueIntegerSafe($ident, $dv); return; }
            if ($age < $this->PendingTimeoutSec()) return;
            $this->ClearPending($ident);
        }
        $this->SetValueIntegerSafe($ident, $dv);
    }
    private function ApplyBoolWithPending($ident, $deviceValue)
    {
        $pending = $this->GetPending($ident); $dv = intval($deviceValue) ? 1 : 0;
        if ($pending !== false) {
            $pv = intval($pending['v']) ? 1 : 0; $age = time() - intval($pending['ts']);
            if ($pv === $dv) { $this->ClearPending($ident); $this->SetValueBooleanSafe($ident, $dv ? true : false); return; }
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
        if ($id > 0 && GetValueString($id) !== $err) $this->SetValueStringSafe('LastError', $err);
        $this->UpdatePollingTimers(false);
    }
    private function ClearLastError()
    {
        $id = @$this->GetIDForIdent('LastError');
        if ($id > 0 && GetValueString($id) !== '') $this->SetValueStringSafe('LastError', '');
        $this->SetValueBooleanSafe('Online', true);
    }
    private function TryLock()
    {
        $key = 'PLSW_' . $this->InstanceID;
        for ($i = 0; $i < 15; $i++) { if (IPS_SemaphoreEnter($key, 100)) return true; IPS_Sleep(20); }
        return false;
    }
    private function Unlock() { @IPS_SemaphoreLeave('PLSW_' . $this->InstanceID); }

    private function SetValueBooleanSafe($ident, $value)
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id > 0 && GetValueBoolean($id) !== (bool)$value) SetValueBoolean($id, (bool)$value);
    }
    private function SetValueIntegerSafe($ident, $value)
    {
        $id = @$this->GetIDForIdent($ident); $v = intval($value);
        if ($id > 0 && GetValueInteger($id) !== $v) SetValueInteger($id, $v);
    }
    private function SetValueStringSafe($ident, $value)
    {
        $id = @$this->GetIDForIdent($ident); $v = strval($value);
        if ($id > 0 && GetValueString($id) !== $v) SetValueString($id, $v);
    }
}
