<?php
// Rep les sol·licituds del formulari de /contacte i de la portada, i les subscripcions
// al butlletí del blog: en desa una còpia a una base de dades SQLite fora de la carpeta
// pública i l'envia per correu.
//
// Cada enviament ha de portar el consentiment explícit de la política de privacitat
// (camp Consentiment del JSON). Sense ell es rebutja, i amb ell es desa la prova
// (text acceptat, hora, pàgina, IP i navegador) a la columna consentiment i al correu.
declare(strict_types=1);

const DESTINATARI = 'avia@prosetel95.com';  // tots els correus del web (sol·licituds i subscripcions)
const REMITENT = 'info@prosetel95.com';
const ORIGENS = ['prosetel95.com', 'www.prosetel95.com', 'prosetel95.com.mialias.net'];
const MAX_PER_HORA = 5;  // enviaments per IP

// Fora de la carpeta pública (web/), per això no és accessible des d'internet
define('BD', dirname(__DIR__) . '/dades/formularis.sqlite');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respon(int $codi, array $cos): void {
    http_response_code($codi);
    echo json_encode($cos, JSON_UNESCAPED_UNICODE);
    exit;
}

// Les bases de dades creades abans del registre del consentiment no tenen la columna
function assegura_columna(PDO $bd, string $taula, string $columna): void {
    foreach ($bd->query("PRAGMA table_info($taula)") as $col) {
        if (($col['name'] ?? '') === $columna) return;
    }
    $bd->exec("ALTER TABLE $taula ADD COLUMN $columna TEXT");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respon(405, ['ok' => false]);

$origen = parse_url($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST);
if (!in_array(strtolower((string) $origen), ORIGENS, true)) respon(403, ['ok' => false]);

$dades = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($dades)) respon(400, ['ok' => false]);

// Camp trampa: invisible per a les persones, els robots l'omplen
if (!empty($dades['web'])) respon(200, ['ok' => true]);

// Consentiment de la política de privacitat: cap sol·licitud ni subscripció es desa sense ell.
// No n'hi ha prou amb la casella del navegador: ha de venir al JSON amb el text acceptat.
$c = is_array($dades['Consentiment'] ?? null) ? $dades['Consentiment'] : [];
$text_rgpd = mb_substr(trim((string) ($c['text'] ?? '')), 0, 500);
if (($c['acceptat'] ?? null) !== true || $text_rgpd === '') {
    respon(422, ['ok' => false, 'error' => 'consentiment']);
}
$ara = date('d/m/Y H:i:s');
$consentiment = [
    'acceptat' => true,
    'text' => $text_rgpd,
    'data_servidor' => $ara,
    'data_navegador' => mb_substr(trim((string) ($c['data'] ?? '')), 0, 40),
    'pagina' => mb_substr(trim((string) ($c['pagina'] ?? '')), 0, 300),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'navegador' => mb_substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 300),
];
$consentiment_json = json_encode($consentiment, JSON_UNESCAPED_UNICODE);
// Mateix contingut, llegible, per al correu que rep avia@prosetel95.com
$consentiment_cos = "Consentiment de la política de privacitat\n"
    . "  Acceptat: sí · " . $ara . ' (hora del servidor)'
    . ($consentiment['data_navegador'] !== '' ? ' · ' . $consentiment['data_navegador'] . ' (hora del navegador)' : '') . "\n"
    . ($consentiment['pagina'] !== '' ? '  Pàgina: ' . $consentiment['pagina'] . "\n" : '')
    . ($consentiment['ip'] !== '' ? '  IP: ' . $consentiment['ip'] . "\n" : '')
    . '  Text acceptat: «' . $text_rgpd . "»\n";

// Subscripció al butlletí del blog: només porta el correu (sense telèfon)
if (($dades['Tipus'] ?? '') === 'Butlletí') {
    $correu = mb_substr(trim((string) ($dades['Correu'] ?? '')), 0, 200);
    if (!filter_var($correu, FILTER_VALIDATE_EMAIL)) respon(422, ['ok' => false]);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    try {
        if (!is_dir(dirname(BD))) mkdir(dirname(BD), 0700, true);
        $bd = new PDO('sqlite:' . BD, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $bd->exec('CREATE TABLE IF NOT EXISTS subscripcions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            data TEXT NOT NULL, ip TEXT, correu TEXT NOT NULL, correu_enviat INTEGER NOT NULL DEFAULT 0,
            consentiment TEXT)');
        assegura_columna($bd, 'subscripcions', 'consentiment');

        $recents = $bd->prepare("SELECT COUNT(*) FROM subscripcions WHERE ip = ? AND data > datetime('now', '-1 hour')");
        $recents->execute([$ip]);
        if ((int) $recents->fetchColumn() >= MAX_PER_HORA) respon(429, ['ok' => false]);

        $ins = $bd->prepare("INSERT INTO subscripcions (data, ip, correu, consentiment)
                             VALUES (datetime('now'), ?, ?, ?)");
        $ins->execute([$ip, $correu, $consentiment_json]);
        $id = (int) $bd->lastInsertId();
    } catch (Throwable $e) {
        error_log('formulari.php BD (butlletí): ' . $e->getMessage());
        $bd = null;
        $id = 0;
    }

    $capcaleres = [
        'From: =?UTF-8?B?' . base64_encode('Web Prosetel-95') . '?= <' . REMITENT . '>',
        'Reply-To: ' . $correu,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $cos = "Nova subscripció al butlletí del blog\n\nCorreu: $correu\n\n" . $consentiment_cos
         . "\nEnviat: " . date('d/m/Y H:i') . ($id ? " · Núm. $id" : '') . "\n";
    $enviat = mail(DESTINATARI, '=?UTF-8?B?' . base64_encode('Subscripció al butlletí: ' . $correu) . '?=', $cos,
                   implode("\r\n", $capcaleres), '-f' . REMITENT);

    if ($bd && $id) $bd->prepare('UPDATE subscripcions SET correu_enviat = ? WHERE id = ?')->execute([$enviat ? 1 : 0, $id]);
    if (!$enviat) error_log("formulari.php: no s'ha pogut enviar el correu de la subscripció $id");
    respon($enviat || $id ? 200 : 500, ['ok' => $enviat || $id > 0]);
}

// Formulari de /contacte (complet) i formulari ràpid de la portada (sense nom ni perfil)
$camps = ['Origen', 'Nom', 'Telefon', 'Correu', 'Perfil', 'Producte', 'Litres', 'Dipòsit',
          'Codi postal', 'Urgència', 'Franja', 'És client', 'Canal preferit', 'Notes'];
$f = [];
foreach ($camps as $c) {
    $v = trim((string) ($dades[$c] ?? ''));
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    $f[$c] = mb_substr($v, 0, $c === 'Notes' ? 2000 : 200);
}
if (!preg_match('/\d{9,}/', preg_replace('/\D/', '', $f['Telefon']) ?? '')) respon(422, ['ok' => false]);
if ($f['Origen'] === '') $f['Origen'] = 'Formulari de contacte';

// Tot va a la mateixa bústia; el tipus (comercial: empreses i agrícoles, i gasoil B des de la portada;
// comandes: particulars i comunitats) queda a l'assumpte per poder-ho triar d'una ullada
$tipus = in_array($f['Perfil'], ['Empresa', 'Agrícola'], true)
      || ($f['Perfil'] === '' && str_starts_with($f['Producte'], 'Gasoil B'))
    ? 'comercial' : 'comandes';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

try {
    if (!is_dir(dirname(BD))) mkdir(dirname(BD), 0700, true);
    $bd = new PDO('sqlite:' . BD, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $bd->exec('CREATE TABLE IF NOT EXISTS sollicituds (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        data TEXT NOT NULL, tipus TEXT NOT NULL, ip TEXT,
        dades TEXT NOT NULL, correu_enviat INTEGER NOT NULL DEFAULT 0,
        consentiment TEXT)');
    assegura_columna($bd, 'sollicituds', 'consentiment');

    $recents = $bd->prepare("SELECT COUNT(*) FROM sollicituds WHERE ip = ? AND data > datetime('now', '-1 hour')");
    $recents->execute([$ip]);
    if ((int) $recents->fetchColumn() >= MAX_PER_HORA) respon(429, ['ok' => false]);

    $ins = $bd->prepare("INSERT INTO sollicituds (data, tipus, ip, dades, consentiment)
                         VALUES (datetime('now'), ?, ?, ?, ?)");
    $ins->execute([$tipus, $ip, json_encode($f, JSON_UNESCAPED_UNICODE), $consentiment_json]);
    $id = (int) $bd->lastInsertId();
} catch (Throwable $e) {
    error_log('formulari.php BD: ' . $e->getMessage());
    $bd = null;
    $id = 0;
}

$cos = "Nova sol·licitud de pressupost des del web\n\n";
foreach ($f as $camp => $v) $cos .= str_pad($camp . ':', 16) . ($v === '' ? '—' : $v) . "\n";
$cos .= "\n" . $consentiment_cos;
$cos .= "\nEnviat: " . date('d/m/Y H:i') . ($id ? " · Núm. $id" : '') . "\n";

$capcaleres = [
    'From: =?UTF-8?B?' . base64_encode('Web Prosetel-95') . '?= <' . REMITENT . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
];
if (filter_var($f['Correu'], FILTER_VALIDATE_EMAIL)) $capcaleres[] = 'Reply-To: ' . $f['Correu'];

$assumpte = 'Sol·licitud web (' . $tipus . '): ' . ($f['Nom'] !== '' ? $f['Nom'] : $f['Telefon'])
          . ($f['Producte'] !== '' ? ' · ' . $f['Producte'] : '');
$enviat = mail(DESTINATARI, '=?UTF-8?B?' . base64_encode($assumpte) . '?=', $cos,
               implode("\r\n", $capcaleres), '-f' . REMITENT);

if ($bd && $id) $bd->prepare('UPDATE sollicituds SET correu_enviat = ? WHERE id = ?')->execute([$enviat ? 1 : 0, $id]);
if (!$enviat) error_log("formulari.php: no s'ha pogut enviar el correu de la sol·licitud $id");

// La sol·licitud compta com a rebuda si ha arribat almenys a un dels dos llocs
respon($enviat || $id ? 200 : 500, ['ok' => $enviat || $id > 0]);
