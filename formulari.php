<?php
// Rep les sol·licituds del formulari de /contacte: en desa una còpia a una base
// de dades SQLite fora de la carpeta pública i l'envia per correu.
declare(strict_types=1);

const DESTINATARIS = [
    'comandes'  => 'comandes@prosetel95.com',   // particulars i comunitats
    'comercial' => 'comercial@prosetel95.com',  // empreses i agrícoles
];
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respon(405, ['ok' => false]);

$origen = parse_url($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST);
if (!in_array(strtolower((string) $origen), ORIGENS, true)) respon(403, ['ok' => false]);

$dades = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($dades)) respon(400, ['ok' => false]);

// Camp trampa: invisible per a les persones, els robots l'omplen
if (!empty($dades['web'])) respon(200, ['ok' => true]);

$camps = ['Nom', 'Telefon', 'Correu', 'Perfil', 'Producte', 'Litres', 'Dipòsit',
          'Codi postal', 'Urgència', 'És client', 'Canal preferit', 'Notes'];
$f = [];
foreach ($camps as $c) {
    $v = trim((string) ($dades[$c] ?? ''));
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    $f[$c] = mb_substr($v, 0, $c === 'Notes' ? 2000 : 200);
}
if ($f['Nom'] === '' || !preg_match('/\d{6,}/', preg_replace('/\D/', '', $f['Telefon']) ?? '')) {
    respon(422, ['ok' => false]);
}

$tipus = in_array($f['Perfil'], ['Empresa', 'Agrícola'], true) ? 'comercial' : 'comandes';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

try {
    if (!is_dir(dirname(BD))) mkdir(dirname(BD), 0700, true);
    $bd = new PDO('sqlite:' . BD, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $bd->exec('CREATE TABLE IF NOT EXISTS sollicituds (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        data TEXT NOT NULL, tipus TEXT NOT NULL, ip TEXT,
        dades TEXT NOT NULL, correu_enviat INTEGER NOT NULL DEFAULT 0)');

    $recents = $bd->prepare("SELECT COUNT(*) FROM sollicituds WHERE ip = ? AND data > datetime('now', '-1 hour')");
    $recents->execute([$ip]);
    if ((int) $recents->fetchColumn() >= MAX_PER_HORA) respon(429, ['ok' => false]);

    $ins = $bd->prepare("INSERT INTO sollicituds (data, tipus, ip, dades) VALUES (datetime('now'), ?, ?, ?)");
    $ins->execute([$tipus, $ip, json_encode($f, JSON_UNESCAPED_UNICODE)]);
    $id = (int) $bd->lastInsertId();
} catch (Throwable $e) {
    error_log('formulari.php BD: ' . $e->getMessage());
    $bd = null;
    $id = 0;
}

$cos = "Nova sol·licitud de pressupost des del web\n\n";
foreach ($f as $c => $v) $cos .= str_pad($c . ':', 16) . ($v === '' ? '—' : $v) . "\n";
$cos .= "\nEnviat: " . date('d/m/Y H:i') . ($id ? " · Núm. $id" : '') . "\n";

$capcaleres = [
    'From: =?UTF-8?B?' . base64_encode('Web Prosetel-95') . '?= <' . REMITENT . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
];
if (filter_var($f['Correu'], FILTER_VALIDATE_EMAIL)) $capcaleres[] = 'Reply-To: ' . $f['Correu'];

$assumpte = 'Sol·licitud web: ' . $f['Nom'] . ($f['Producte'] !== '' ? ' · ' . $f['Producte'] : '');
$enviat = mail(DESTINATARIS[$tipus], '=?UTF-8?B?' . base64_encode($assumpte) . '?=', $cos,
               implode("\r\n", $capcaleres), '-f' . REMITENT);

if ($bd && $id) $bd->prepare('UPDATE sollicituds SET correu_enviat = ? WHERE id = ?')->execute([$enviat ? 1 : 0, $id]);
if (!$enviat) error_log("formulari.php: no s'ha pogut enviar el correu de la sol·licitud $id");

// La sol·licitud compta com a rebuda si ha arribat almenys a un dels dos llocs
respon($enviat || $id ? 200 : 500, ['ok' => $enviat || $id > 0]);
