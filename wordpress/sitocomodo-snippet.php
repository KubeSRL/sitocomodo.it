/*
 * SitoComodo — pagine e ordini dentro WordPress (snippet per il plugin Code Snippets, «Esegui ovunque»).
 * 1. Serve il sito com'è stato disegnato: i file stanno in wp-content/uploads/sitocomodo/ con i percorsi di sempre
 *    (/, /ordina/, /ordine/, /checkout/, /grazie/, /esempi/..., /fonts/..., robots.txt, sitemap.xml, llms.txt).
 * 2. Riceve il modulo «Raccontaci la tua attività» (testi + foto) su /wp-admin/admin-post.php?action=sc_ordine,
 *    salva l'ordine «in attesa di pagamento» e porta il cliente al pagamento (/checkout/). L'email a info@sitocomodo.it
 *    con testi e link alle foto parte SOLO quando Stripe conferma il pagamento (webhook /wp-json/sitocomodo/v1/stripe).
 *    In bacheca: menu «Ordini SitoComodo» (elenco ordini + codice del webhook Stripe).
 * 3. /wp-json/sitocomodo/v1/file e /registro: solo l'amministratore (caricare i file del sito, leggere gli ordini).
 *    I dati degli ordini stanno nel database (opzione sc_ordini); su disco solo le foto, in cartelle con nome casuale.
 */

if (!defined('SC_DESTINATARIO')) {
    define('SC_DESTINATARIO', 'info@sitocomodo.it');
}

// ---------------------------------------------------------------- 1. il sito statico dentro WordPress
// Tutti i file del sito (pagine, esempi, font, immagini) stanno in wp-content/uploads/sitocomodo/ con lo stesso
// percorso che avevano su GitHub: /ordina/ → sitocomodo/ordina/index.html, /fonts/x.woff2 → sitocomodo/fonts/x.woff2.
function sc_cartella_sito() {
    $up = wp_upload_dir();
    return trailingslashit($up['basedir']) . 'sitocomodo';
}

add_action('template_redirect', function () {
    // un solo indirizzo per Google: www.sitocomodo.it → sitocomodo.it
    if (strtolower($_SERVER['HTTP_HOST'] ?? '') === 'www.sitocomodo.it') {
        wp_redirect('https://sitocomodo.it' . ($_SERVER['REQUEST_URI'] ?? '/'), 301);
        exit;
    }
    $percorso = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    if (preg_match('#^/(wp-admin|wp-json|wp-content|wp-includes|wp-login)#', $percorso) || strpos($percorso, '..') !== false) {
        return;
    }
    $base = sc_cartella_sito();
    $file = $base . $percorso;
    if (substr($percorso, -1) === '/' || is_dir($file)) {
        $file = rtrim($file, '/') . '/index.html';
    }
    if (!is_file($file)) {
        return;
    }
    $tipi = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'js' => 'application/javascript', 'json' => 'application/json',
             'txt' => 'text/plain; charset=utf-8', 'xml' => 'application/xml; charset=utf-8', 'svg' => 'image/svg+xml',
             'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif',
             'ico' => 'image/x-icon', 'woff2' => 'font/woff2', 'woff' => 'font/woff'];
    $est = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!isset($tipi[$est])) {
        return;
    }
    status_header(200);
    header('Content-Type: ' . $tipi[$est]);
    header('Cache-Control: ' . ($est === 'html' || $est === 'txt' || $est === 'xml' ? 'no-cache' : 'public, max-age=2592000'));
    if ($est === 'woff2' || $est === 'woff') {
        header('Access-Control-Allow-Origin: *');
    }
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}, 0);

add_filter('wp_sitemaps_enabled', '__return_false');

// caricamento dei file del sito: solo per l'amministratore, dalla bacheca di WordPress
add_action('rest_api_init', function () {
    register_rest_route('sitocomodo/v1', '/file', [
        'methods' => 'POST',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback' => function (WP_REST_Request $r) {
            $percorso = ltrim((string) $r->get_param('percorso'), '/');
            if ($percorso === '' || strpos($percorso, '..') !== false || !preg_match('#^[A-Za-z0-9._/\-]+$#', $percorso)) {
                return new WP_Error('percorso', 'percorso non valido', ['status' => 400]);
            }
            $est = strtolower(pathinfo($percorso, PATHINFO_EXTENSION));
            if (!in_array($est, ['html', 'css', 'js', 'json', 'txt', 'xml', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'ico', 'woff2', 'woff'], true)) {
                return new WP_Error('tipo', 'tipo non ammesso', ['status' => 400]);
            }
            $f = $r->get_file_params();
            if (empty($f['file']['tmp_name'])) {
                return new WP_Error('file', 'file mancante', ['status' => 400]);
            }
            $dest = sc_cartella_sito() . '/' . $percorso;
            wp_mkdir_p(dirname($dest));
            if (!@move_uploaded_file($f['file']['tmp_name'], $dest)) {
                return new WP_Error('salva', 'impossibile salvare', ['status' => 500]);
            }
            return ['ok' => true, 'percorso' => $percorso, 'byte' => filesize($dest)];
        },
    ]);
});

// ---------------------------------------------------------------- 2. ordini
// Il modulo salva l'ordine «in attesa di pagamento» (nessuna email).
// L'email a info@ parte SOLO quando Stripe conferma il pagamento (webhook firmato su /wp-json/sitocomodo/v1/stripe).
add_action('admin_post_nopriv_sc_ordine', 'sc_ricevi_ordine');
add_action('admin_post_sc_ordine', 'sc_ricevi_ordine');

function sc_campi_ordine() {
    return [
        'nome' => "Nome dell'attività", 'tipo' => 'Che attività è', 'chi' => 'Chi siete', 'servizi' => 'Cosa offre',
        'telefono' => 'Telefono', 'whatsapp' => 'WhatsApp', 'email' => 'Email per i contatti', 'indirizzo' => 'Indirizzo',
        'orari' => 'Orari', 'colori' => 'Colori preferiti', 'social' => 'Link social', 'google' => 'Scheda Google Maps',
        'modello_scelto' => 'Modello', 'carattere_scelto' => 'Carattere dei titoli',
    ];
}

function sc_ordini_leggi() {
    $o = get_option('sc_ordini', []);
    return is_array($o) ? $o : [];
}

function sc_ordini_scrivi($o) {
    update_option('sc_ordini', array_values(array_slice($o, -300)), false);
}

function sc_url_foto($cartella, $file) {
    $up = wp_upload_dir();
    return trailingslashit($up['baseurl']) . 'ordini-sitocomodo/' . rawurlencode($cartella) . '/' . rawurlencode($file);
}

function sc_campo($k, $max = 4000) {
    $v = isset($_POST[$k]) ? wp_unslash((string) $_POST[$k]) : '';
    $v = str_replace("\r\n", "\n", trim($v));
    return mb_substr($v, 0, $max, 'UTF-8');
}

function sc_file_caricati($nome) {
    if (empty($_FILES[$nome])) {
        return [];
    }
    $f = $_FILES[$nome];
    if (is_array($f['name'])) {
        $lista = [];
        foreach ($f['name'] as $i => $n) {
            $lista[] = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
        }
        return $lista;
    }
    return [$f];
}

function sc_ricevi_ordine() {
    $pagamento = home_url('/checkout/');
    if (!empty($_POST['_honey'])) {           // trappola per i robot
        wp_safe_redirect($pagamento, 303);
        exit;
    }
    $dominio = strtolower(preg_replace('/[^a-zA-Z0-9.\-]/', '', sc_campo('dominio', 70)));
    if ($dominio === '') {
        wp_safe_redirect(home_url('/ordina/'), 303);
        exit;
    }
    $dati = ['dominio' => $dominio];
    foreach (sc_campi_ordine() as $k => $etichetta) {
        $dati[$k] = sc_campo($k);
    }
    $dati['modello'] = sc_campo('modello', 40);
    $dati['carattere'] = sc_campo('carattere', 40);
    $dati['diritti'] = isset($_POST['diritti']) ? 'sì' : 'no';
    $dati['riferimento_stripe'] = str_replace('.', '_', $dominio);
    $dati['ricevuto'] = wp_date('d/m/Y H:i:s');

    $up = wp_upload_dir();
    $base = trailingslashit($up['basedir']) . 'ordini-sitocomodo';
    wp_mkdir_p($base);
    if (!file_exists($base . '/index.html')) {
        @file_put_contents($base . '/index.html', '');
    }
    $cartella = wp_date('Ymd-His') . '-' . $dominio . '-' . bin2hex(random_bytes(10));
    $dir = $base . '/' . $cartella;
    wp_mkdir_p($dir);
    @file_put_contents($dir . '/index.html', '');

    $tipi = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/heic' => 'heic', 'image/heif' => 'heif'];
    $salvati = [];
    $problemi = [];
    foreach (['logo' => 'logo', 'foto' => 'foto', 'foto_telefono' => 'foto-telefono'] as $campo => $prefisso) {
        $n = 0;
        foreach (sc_file_caricati($campo) as $f) {
            if ($f['error'] === UPLOAD_ERR_NO_FILE) continue;
            if ($f['error'] !== UPLOAD_ERR_OK) { $problemi[] = "$campo: errore di caricamento {$f['error']}"; continue; }
            if ($f['size'] > 15 * 1024 * 1024) { $problemi[] = "$campo: file troppo grande ({$f['name']})"; continue; }
            if ($campo === 'foto' && $n >= 6) { $problemi[] = 'foto oltre la sesta ignorate'; break; }
            $mime = function_exists('mime_content_type') ? (string) mime_content_type($f['tmp_name']) : '';
            if (!isset($tipi[$mime])) { $problemi[] = "$campo: tipo non accettato $mime ({$f['name']})"; continue; }
            $n++;
            $nome = $prefisso . ($campo === 'foto' ? '-' . $n : '') . '.' . $tipi[$mime];
            if (@move_uploaded_file($f['tmp_name'], $dir . '/' . $nome)) {
                $salvati[] = ['campo' => $campo, 'file' => $nome, 'kb' => (int) round($f['size'] / 1024)];
            } else {
                $problemi[] = "$campo: impossibile salvare {$f['name']}";
            }
        }
    }
    $dati['file'] = $salvati;
    $dati['problemi'] = $problemi;
    $dati['cartella'] = $cartella;
    $dati['stato'] = 'in attesa di pagamento';

    $ordini = sc_ordini_leggi();
    $ordini[] = $dati;
    sc_ordini_scrivi($ordini);

    wp_safe_redirect($pagamento, 303);
    exit;
}

// email a info@ con tutto l'ordine: parte solo a pagamento confermato
function sc_manda_email_pagato($dati, $pag) {
    $righe = ['NUOVO ORDINE SITOCOMODO — PAGATO ✔',
              'Pagamento: ' . $pag['importo'] . ' il ' . $pag['quando'] . ' (riferimento Stripe: ' . ($dati['riferimento_stripe'] ?? '') . ')'];
    if (!empty($pag['email_cliente'])) {
        $righe[] = 'Email di chi ha pagato: ' . $pag['email_cliente'] . (!empty($pag['nome_cliente']) ? ' (' . $pag['nome_cliente'] . ')' : '');
    }
    $righe[] = '';
    if (empty($dati['dominio'])) {
        $righe[] = 'ATTENZIONE: è arrivato un pagamento ma non c\'è il modulo con testi e foto collegato a questo riferimento.';
        $righe[] = 'Contatta il cliente all\'email qui sopra per farti mandare i dati.';
    } else {
        $righe[] = 'Dominio: ' . $dati['dominio'];
        foreach (sc_campi_ordine() as $k => $etichetta) {
            if (($dati[$k] ?? '') === '') continue;
            $righe[] = (strpos($dati[$k], "\n") !== false) ? "$etichetta:\n{$dati[$k]}" : "$etichetta: {$dati[$k]}";
        }
        $righe[] = 'Diritti su foto e testi confermati: ' . ($dati['diritti'] ?? '');
        $righe[] = '';
        $righe[] = 'INTESTATARIO DEL DOMINIO:';
        $righe[] = empty($dati['intestatario']) ? 'mancano i dati: il dominio va comprato a mano dopo averli chiesti al cliente'
                                                : sc_intestatario_testo($dati['intestatario']);
        $righe[] = 'Il dominio viene comprato in automatico su OVH: ti arriva un\'altra email con l\'esito.';
        $righe[] = '';
        $righe[] = 'FOTO E LOGO (' . count($dati['file'] ?? []) . '):';
        foreach (($dati['file'] ?? []) as $s) {
            $righe[] = '- ' . $s['campo'] . ': ' . sc_url_foto($dati['cartella'], $s['file']) . ' (' . $s['kb'] . ' KB)';
        }
        if (!empty($dati['problemi'])) {
            $righe[] = '';
            $righe[] = 'ATTENZIONE: ' . implode('; ', $dati['problemi']);
        }
        $righe[] = '';
        $righe[] = 'Modulo compilato il ' . ($dati['ricevuto'] ?? '');
    }
    $intestazioni = ['Content-Type: text/plain; charset=UTF-8'];
    $risposta = is_email($dati['email'] ?? '') ? $dati['email'] : ($pag['email_cliente'] ?? '');
    if (is_email($risposta)) {
        $intestazioni[] = 'Reply-To: ' . $risposta;
    }
    $oggetto = empty($dati['dominio'])
        ? 'Pagamento SitoComodo senza modulo: ' . ($dati['riferimento_stripe'] ?? '')
        : 'Ordine PAGATO SitoComodo: ' . $dati['dominio'] . (($dati['nome'] ?? '') !== '' ? ' (' . $dati['nome'] . ')' : '');
    $errore = '';
    $spia = function ($e) use (&$errore) { $errore = is_wp_error($e) ? $e->get_error_message() : ''; };
    add_action('wp_mail_failed', $spia);
    $ok = wp_mail(SC_DESTINATARIO, $oggetto, implode("\n", $righe), $intestazioni);
    remove_action('wp_mail_failed', $spia);
    return $ok ? 'sì' : 'NO: ' . $errore;
}

// controllo della firma Stripe (header Stripe-Signature: t=...,v1=...)
function sc_firma_stripe_valida($payload, $intestazione, $segreto) {
    $t = 0;
    $firme = [];
    foreach (explode(',', (string) $intestazione) as $pezzo) {
        $kv = explode('=', trim($pezzo), 2);
        if (count($kv) !== 2) continue;
        if ($kv[0] === 't') $t = (int) $kv[1];
        if ($kv[0] === 'v1') $firme[] = $kv[1];
    }
    if (!$t || !$firme || abs(time() - $t) > 600) {
        return false;
    }
    $atteso = hash_hmac('sha256', $t . '.' . $payload, $segreto);
    foreach ($firme as $f) {
        if (hash_equals($atteso, $f)) return true;
    }
    return false;
}

add_action('rest_api_init', function () {
    // Stripe chiama questo indirizzo quando un pagamento va a buon fine
    register_rest_route('sitocomodo/v1', '/stripe', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $r) {
            $segreto = (string) get_option('sc_stripe_whsec', '');
            if ($segreto === '') {
                return new WP_REST_Response(['errore' => 'collegamento Stripe non ancora configurato'], 503); // Stripe riprova per 3 giorni
            }
            $payload = $r->get_body();
            if (!sc_firma_stripe_valida($payload, $r->get_header('stripe_signature'), $segreto)) {
                return new WP_REST_Response(['errore' => 'firma non valida'], 400);
            }
            $ev = json_decode($payload, true);
            $tipo = $ev['type'] ?? '';
            if (!in_array($tipo, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
                return ['ok' => true, 'ignorato' => $tipo];
            }
            $s = $ev['data']['object'] ?? [];
            if (($s['payment_status'] ?? '') !== 'paid') {
                return ['ok' => true, 'attesa' => 'pagamento non ancora incassato'];
            }
            $sessione = (string) ($s['id'] ?? '');
            $rif = (string) ($s['client_reference_id'] ?? '');
            $ordini = sc_ordini_leggi();
            foreach ($ordini as $o) {
                if (($o['pagamento']['sessione'] ?? '') === $sessione) {
                    return ['ok' => true, 'gia_registrato' => true];
                }
            }
            $pag = [
                'sessione' => $sessione,
                'importo' => number_format(((int) ($s['amount_total'] ?? 0)) / 100, 2, ',', '.') . ' ' . strtoupper((string) ($s['currency'] ?? 'eur')),
                'email_cliente' => (string) ($s['customer_details']['email'] ?? ''),
                'nome_cliente' => (string) ($s['customer_details']['name'] ?? ''),
                'quando' => wp_date('d/m/Y H:i', (int) ($ev['created'] ?? time())),
            ];
            $indice = null;
            for ($i = count($ordini) - 1; $i >= 0; $i--) {
                if ($rif !== '' && ($ordini[$i]['riferimento_stripe'] ?? '') === $rif && ($ordini[$i]['stato'] ?? '') !== 'pagato') {
                    $indice = $i;
                    break;
                }
            }
            if ($indice === null) {
                $ordini[] = ['riferimento_stripe' => $rif, 'ricevuto' => $pag['quando'], 'stato' => 'pagato senza modulo'];
                $indice = count($ordini) - 1;
            } else {
                $ordini[$indice]['stato'] = 'pagato';
            }
            $ordini[$indice]['pagamento'] = $pag;
            if (empty($ordini[$indice]['intestatario'])) {   // dati arrivati prima del modulo, o senza modulo
                $sospesi = get_option('sc_intestatari_sospesi', []);
                if (is_array($sospesi) && !empty($sospesi[$rif])) {
                    $ordini[$indice]['intestatario'] = $sospesi[$rif];
                    unset($sospesi[$rif]);
                    update_option('sc_intestatari_sospesi', $sospesi, false);
                }
            }
            $ordini[$indice]['email_inviata'] = sc_manda_email_pagato($ordini[$indice], $pag);
            sc_ordini_scrivi($ordini);
            // acquisto del dominio su OVH: subito dopo, fuori dalla risposta a Stripe
            if (!empty($ordini[$indice]['dominio'])) {
                wp_schedule_single_event(time(), 'sc_compra_dominio', [$sessione]);
                spawn_cron();
            }
            return ['ok' => true];
        },
    ]);

    // solo per l'amministratore: ultimi ordini (serve per i controlli)
    register_rest_route('sitocomodo/v1', '/registro', [
        'methods' => 'GET',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback' => function () {
            $ordini = sc_ordini_leggi();
            return ['quanti' => count($ordini), 'ultimi' => array_slice($ordini, -5), 'stripe_collegato' => get_option('sc_stripe_whsec', '') !== ''];
        },
    ]);
});

// ---------------------------------------------------------------- 4. dominio: intestatario e acquisto automatico su OVH
// Il checkout manda i dati dell'intestatario (POST /wp-json/sitocomodo/v1/intestatario) prima di andare su Stripe.
// A pagamento confermato parte sc_compra_dominio: carrello OVH → prezzo (tetto 12 € IVA inclusa) → contatto intestatario
// → ordine → pagamento con il credito prepagato OVH. Esito sempre per email a info@. Le chiavi OVH le incolla Giuseppe
// nella pagina «Ordini SitoComodo»; finché l'acquisto non è acceso, l'email dice di comprarlo a mano.

function sc_intestatario_pulito($d) {
    $t = in_array(($d['tipo'] ?? ''), ['privato', 'ditta', 'societa'], true) ? $d['tipo'] : 'privato';
    $v = function ($k, $max = 120) use ($d) {
        return mb_substr(trim(sanitize_text_field((string) ($d[$k] ?? ''))), 0, $max, 'UTF-8');
    };
    $i = [
        'tipo' => $t, 'nome' => $v('nome', 80), 'cognome' => $v('cognome', 80), 'ragione' => $v('ragione', 150),
        'cf' => strtoupper(preg_replace('/\s+/', '', $v('cf', 20))), 'piva' => preg_replace('/\D/', '', $v('piva', 20)),
        'indirizzo' => $v('indirizzo', 150), 'cap' => preg_replace('/\D/', '', $v('cap', 10)), 'citta' => $v('citta', 80),
        'provincia' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $v('provincia', 5)), 0, 2)),
        'telefono' => preg_replace('/[^0-9+]/', '', $v('telefono', 30)), 'email' => sanitize_email((string) ($d['email'] ?? '')),
        'salvato' => wp_date('d/m/Y H:i'),
    ];
    if ($t === 'societa') { $i['nome'] = $i['nome'] ?: ''; $i['cf'] = ''; }
    if ($t === 'privato') { $i['ragione'] = ''; $i['piva'] = ''; }
    return $i;
}

// restituisce un messaggio d'errore o '' se i dati bastano per registrare il dominio
function sc_intestatario_errore($i) {
    if ($i['tipo'] !== 'societa' && ($i['nome'] === '' || $i['cognome'] === '')) return 'mancano nome e cognome';
    if ($i['tipo'] === 'societa' && $i['ragione'] === '') return 'manca la ragione sociale';
    if ($i['tipo'] !== 'societa' && !preg_match('/^[A-Z0-9]{16}$/', $i['cf'])) return 'codice fiscale non valido';
    if ($i['tipo'] !== 'privato' && !preg_match('/^\d{11}$/', $i['piva'])) return 'partita IVA non valida';
    if (mb_strlen($i['indirizzo']) < 5 || !preg_match('/^\d{5}$/', $i['cap']) || mb_strlen($i['citta']) < 2 || !preg_match('/^[A-Z]{2}$/', $i['provincia'])) return 'indirizzo incompleto';
    if (strlen(preg_replace('/\D/', '', $i['telefono'])) < 6) return 'telefono mancante';
    if (!is_email($i['email'])) return 'email non valida';
    return '';
}

function sc_intestatario_testo($i) {
    $tipi = ['privato' => 'privato', 'ditta' => 'ditta individuale / professionista', 'societa' => 'società o associazione'];
    $r = [];
    $r[] = trim(($i['ragione'] ?? '') !== '' ? $i['ragione'] . ' — ' : '') . trim(($i['nome'] ?? '') . ' ' . ($i['cognome'] ?? '')) . ' (' . ($tipi[$i['tipo'] ?? ''] ?? '') . ')';
    if (!empty($i['cf'])) $r[] = 'Codice fiscale: ' . $i['cf'];
    if (!empty($i['piva'])) $r[] = 'Partita IVA: ' . $i['piva'];
    $r[] = 'Indirizzo: ' . ($i['indirizzo'] ?? '') . ', ' . ($i['cap'] ?? '') . ' ' . ($i['citta'] ?? '') . ' (' . ($i['provincia'] ?? '') . ')';
    $r[] = 'Telefono: ' . ($i['telefono'] ?? '') . ' · Email: ' . ($i['email'] ?? '');
    return implode("\n", $r);
}

add_action('rest_api_init', function () {
    // il checkout manda qui i dati dell'intestatario (pubblico: si possono solo aggiungere a un ordine non ancora pagato)
    register_rest_route('sitocomodo/v1', '/intestatario', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $r) {
            $d = $r->get_json_params();
            if (!is_array($d)) return new WP_REST_Response(['ok' => false, 'errore' => 'Dati mancanti.'], 400);
            $rif = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) ($d['rif'] ?? '')));
            if ($rif === '') return new WP_REST_Response(['ok' => false, 'errore' => 'Manca il dominio: torna a «Dominio».'], 400);
            $i = sc_intestatario_pulito($d);
            $err = sc_intestatario_errore($i);
            if ($err !== '') return new WP_REST_Response(['ok' => false, 'errore' => 'Controlla i dati dell\'intestatario: ' . $err . '.'], 400);
            $ordini = sc_ordini_leggi();
            for ($k = count($ordini) - 1; $k >= 0; $k--) {
                if (($ordini[$k]['riferimento_stripe'] ?? '') === $rif && ($ordini[$k]['stato'] ?? '') === 'in attesa di pagamento') {
                    $ordini[$k]['intestatario'] = $i;
                    sc_ordini_scrivi($ordini);
                    return ['ok' => true];
                }
            }
            $sospesi = get_option('sc_intestatari_sospesi', []);
            if (!is_array($sospesi)) $sospesi = [];
            $sospesi[$rif] = $i;
            update_option('sc_intestatari_sospesi', array_slice($sospesi, -100, null, true), false);
            return ['ok' => true, 'sospeso' => true];
        },
    ]);
});

// chiamata firmata alle API di OVH (Europa)
function sc_ovh($metodo, $percorso, $corpo = null) {
    $ak = (string) get_option('sc_ovh_ak', '');
    $as = (string) get_option('sc_ovh_as', '');
    $ck = (string) get_option('sc_ovh_ck', '');
    if ($ak === '' || $as === '' || $ck === '') return new WP_Error('ovh', 'chiavi OVH non inserite');
    static $scarto = null;
    if ($scarto === null) {
        $t = wp_remote_get('https://eu.api.ovh.com/1.0/auth/time', ['timeout' => 10]);
        $ora = is_wp_error($t) ? 0 : (int) wp_remote_retrieve_body($t);
        $scarto = $ora > 0 ? $ora - time() : 0;
    }
    $url = 'https://eu.api.ovh.com/1.0' . $percorso;
    $body = $corpo === null ? '' : wp_json_encode($corpo);
    $ts = (string) (time() + $scarto);
    $firma = '$1$' . sha1($as . '+' . $ck . '+' . $metodo . '+' . $url . '+' . $body . '+' . $ts);
    $arg = ['method' => $metodo, 'timeout' => 40, 'headers' => [
        'X-Ovh-Application' => $ak, 'X-Ovh-Consumer' => $ck, 'X-Ovh-Timestamp' => $ts, 'X-Ovh-Signature' => $firma,
        'Content-Type' => 'application/json; charset=utf-8']];
    if ($body !== '') $arg['body'] = $body;
    $r = wp_remote_request($url, $arg);
    if (is_wp_error($r)) return new WP_Error('ovh', "$metodo $percorso: " . $r->get_error_message());
    $codice = (int) wp_remote_retrieve_response_code($r);
    $testo = (string) wp_remote_retrieve_body($r);
    $dati = json_decode($testo, true);
    if ($codice >= 300) {
        $msg = is_array($dati) && isset($dati['message']) ? $dati['message'] : $testo;
        if (is_array($dati) && !empty($dati['details'])) $msg .= ' ' . wp_json_encode($dati['details']);
        return new WP_Error('ovh', "$metodo $percorso → $codice: " . mb_substr((string) $msg, 0, 600, 'UTF-8'));
    }
    return $dati === null && $testo !== 'null' ? $testo : $dati;
}

// contatto intestatario nel formato di OVH (/domain/contact)
function sc_ovh_contatto($i) {
    $tel = preg_replace('/[^0-9+]/', '', $i['telefono']);
    $tel = preg_replace('/^(\+39|0039)/', '', $tel);
    $c = [
        'email' => $i['email'], 'phone' => '+39.' . ltrim($tel, '+'), 'language' => 'it_IT', 'nationality' => 'IT',
        'address' => ['line1' => $i['indirizzo'], 'zip' => $i['cap'], 'city' => $i['citta'], 'province' => $i['provincia'], 'country' => 'IT'],
    ];
    if ($i['tipo'] === 'privato') {
        $c += ['legalForm' => 'individual', 'firstName' => $i['nome'], 'lastName' => $i['cognome'], 'nationalIdentificationNumber' => $i['cf']];
    } elseif ($i['tipo'] === 'ditta') {
        $c += ['legalForm' => 'corporation', 'organisationName' => $i['ragione'] !== '' ? $i['ragione'] : trim($i['nome'] . ' ' . $i['cognome']),
               'firstName' => $i['nome'], 'lastName' => $i['cognome'], 'nationalIdentificationNumber' => $i['cf'], 'vat' => $i['piva']];
    } else {
        $c += ['legalForm' => 'corporation', 'organisationName' => $i['ragione'], 'vat' => $i['piva']];
        if ($i['nome'] !== '') $c['firstName'] = $i['nome'];
        if ($i['cognome'] !== '') $c['lastName'] = $i['cognome'];
    }
    return $c;
}

// compra (o, con $prova, controlla soltanto) il dominio dell'ordine. Restituisce ['ok', 'stato', 'dettaglio', 'passi'].
function sc_dominio_acquista($dominio, $intestatario, $prova = false) {
    $passi = [];
    $fine = function ($ok, $stato, $dettaglio) use (&$passi) { return ['ok' => $ok, 'stato' => $stato, 'dettaglio' => $dettaglio, 'passi' => $passi]; };
    $tetto = (float) get_option('sc_ovh_tetto', 12);
    if (!$prova && get_option('sc_ovh_attivo', '') !== '1') return $fine(false, 'da comprare a mano', 'acquisto automatico spento');
    if (!preg_match('/^[a-z0-9][a-z0-9\-]*(\.[a-z0-9\-]+)+$/', $dominio)) return $fine(false, 'da comprare a mano', 'dominio non valido');
    if (!$prova) {
        if (empty($intestatario)) return $fine(false, 'da comprare a mano', 'mancano i dati dell\'intestatario');
        $e = sc_intestatario_errore($intestatario);
        if ($e !== '') return $fine(false, 'da comprare a mano', 'dati intestatario: ' . $e);
    }

    $carrello = sc_ovh('POST', '/order/cart', ['ovhSubsidiary' => 'IT', 'description' => 'SitoComodo ' . $dominio]);
    if (is_wp_error($carrello)) return $fine(false, 'errore', $carrello->get_error_message());
    $cid = $carrello['cartId'] ?? '';
    $passi[] = 'carrello ' . $cid;
    $pulisci = function () use ($cid) { if ($cid) sc_ovh('DELETE', '/order/cart/' . rawurlencode($cid)); };

    $offerte = sc_ovh('GET', '/order/cart/' . rawurlencode($cid) . '/domain?domain=' . rawurlencode($dominio));
    if (is_wp_error($offerte)) { $pulisci(); return $fine(false, 'errore', $offerte->get_error_message()); }
    $offerta = null;
    foreach ((array) $offerte as $of) {
        if (($of['action'] ?? '') === 'create' && !empty($of['orderable'])) { $offerta = $of; break; }
    }
    if (!$offerta) { $pulisci(); return $fine(false, 'non disponibile', 'il dominio non si può registrare (già preso o non vendibile)'); }
    if (strpos((string) ($offerta['pricingMode'] ?? ''), 'premium') !== false) { $pulisci(); return $fine(false, 'non comprato', 'dominio premium: prezzo speciale'); }
    $listino = '';
    foreach (($offerta['prices'] ?? []) as $p) {
        if (($p['label'] ?? '') === 'TOTAL') $listino = $p['price']['text'] ?? (($p['price']['value'] ?? '') . ' €');
    }
    $passi[] = 'libero, prezzo di listino ' . $listino;

    $voce = sc_ovh('POST', '/order/cart/' . rawurlencode($cid) . '/domain', ['domain' => $dominio, 'duration' => 'P1Y']);
    if (is_wp_error($voce)) { $pulisci(); return $fine(false, 'errore', $voce->get_error_message()); }
    $iid = $voce['itemId'] ?? '';
    $a = sc_ovh('POST', '/order/cart/' . rawurlencode($cid) . '/assign');
    if (is_wp_error($a)) { $pulisci(); return $fine(false, 'errore', $a->get_error_message()); }

    if ($prova) {
        $me = sc_ovh('GET', '/me');
        $passi[] = is_wp_error($me) ? 'account: ' . $me->get_error_message() : 'account OVH: ' . ($me['nichandle'] ?? '?');
        $conti = sc_ovh('GET', '/me/ovhAccount');
        if (!is_wp_error($conti)) {
            foreach ((array) $conti as $id) {
                $c = sc_ovh('GET', '/me/ovhAccount/' . rawurlencode($id));
                if (!is_wp_error($c)) $passi[] = 'credito prepagato: ' . ($c['balance']['text'] ?? (($c['balance']['value'] ?? '?') . ' €'));
            }
        } else {
            $passi[] = 'credito prepagato: ' . $conti->get_error_message();
        }
        $req = sc_ovh('GET', '/order/cart/' . rawurlencode($cid) . '/item/' . rawurlencode($iid) . '/requiredConfiguration');
        if (!is_wp_error($req)) {
            $passi[] = 'configurazioni richieste: ' . implode(', ', array_map(function ($x) { return ($x['label'] ?? '?') . (!empty($x['required']) ? '*' : ''); }, (array) $req));
            foreach ((array) $req as $x) { $passi[] = 'CONF ' . wp_json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
        }
        $regole = sc_ovh('GET', '/domain/configurationRule?action=create&domain=' . rawurlencode($dominio));
        if (is_wp_error($regole)) $passi[] = 'REGOLE ' . $regole->get_error_message();
        else foreach ((array) ($regole['and'] ?? []) as $r) {
            if (($r['label'] ?? '') === 'OWNER_CONTACT') continue;
            $passi[] = 'REGOLA ' . ($r['label'] ?? '?') . ' | tipo ' . ($r['type'] ?? '?') . ' | vincoli ' . wp_json_encode($r['constraints'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' | ' . substr((string) ($r['description'] ?? ''), 0, 4000);
        }
        // TEMP: verifica a vuoto (GET checkout non crea ordini) di quali dichiarazioni .it servono
        if (preg_match('/\.it$/', $dominio)) {
            $prova_conf = function ($label, $valore) use ($cid, $iid, &$passi) {
                $c = sc_ovh('POST', '/order/cart/' . rawurlencode($cid) . '/item/' . rawurlencode($iid) . '/configuration', ['label' => $label, 'value' => $valore]);
                if (is_wp_error($c)) $passi[] = "TEST conf $label: " . $c->get_error_message();
            };
            $prova_check = function ($nome) use ($cid, &$passi) {
                $k = sc_ovh('GET', '/order/cart/' . rawurlencode($cid) . '/checkout');
                $passi[] = "TEST checkout $nome: " . (is_wp_error($k) ? 'ERRORE ' . $k->get_error_message() : 'ok ' . ($k['prices']['withTax']['text'] ?? ''));
            };
            $prova_check('senza niente');
            $prova_conf('OWNER_LEGAL_AGE', 'true');
            $prova_conf('IT_SECT3_INDIVIDUAL', 'Accept'); $prova_conf('IT_SECT4_INDIVIDUAL', 'true'); $prova_conf('IT_SECT5_INDIVIDUAL', 'Do not accept'); $prova_conf('IT_SECT6_INDIVIDUAL', 'Accept');
            $prova_check('con INDIVIDUAL');
            $prova_conf('IT_SECT3_OTHER', 'Accept'); $prova_conf('IT_SECT4_OTHER', 'true'); $prova_conf('IT_SECT5_OTHER', 'Accept');
            $prova_check('con INDIVIDUAL e OTHER');
        }
        $riepilogo = sc_ovh('GET', '/order/cart/' . rawurlencode($cid) . '/summary');
        if (!is_wp_error($riepilogo)) $passi[] = 'totale carrello IVA inclusa: ' . ($riepilogo['prices']['withTax']['text'] ?? '?');
        $pulisci();
        return $fine(true, 'prova riuscita', 'nessun acquisto fatto');
    }

    // intestatario: controllo delle regole del registro, poi creazione del contatto
    $contatto = sc_ovh_contatto($intestatario);
    $verifica = sc_ovh('POST', '/domain/configurationRule/check?action=create&domain=' . rawurlencode($dominio), ['owner' => $contatto]);
    if (is_wp_error($verifica)) { $pulisci(); return $fine(false, 'non comprato', 'dati intestatario rifiutati dal registro: ' . $verifica->get_error_message()); }
    $nuovo = sc_ovh('POST', '/domain/contact', $contatto);
    if (is_wp_error($nuovo) || empty($nuovo['id'])) { $pulisci(); return $fine(false, 'non comprato', 'contatto intestatario: ' . (is_wp_error($nuovo) ? $nuovo->get_error_message() : 'risposta senza id')); }
    $passi[] = 'contatto intestatario ' . $nuovo['id'];

    $req = sc_ovh('GET', '/order/cart/' . rawurlencode($cid) . '/item/' . rawurlencode($iid) . '/requiredConfiguration');
    if (is_wp_error($req)) { $pulisci(); return $fine(false, 'errore', $req->get_error_message()); }
    foreach ((array) $req as $conf) {
        $label = $conf['label'] ?? '';
        $valore = null;
        if ($label === 'OWNER_CONTACT') $valore = '/me/contact/' . $nuovo['id'];
        elseif ($label === 'OWNER_LEGAL_AGE' || $label === 'ACCEPT_CONDITIONS') $valore = 'true';
        elseif (in_array($label, ['ADMIN_ACCOUNT', 'TECH_ACCOUNT', 'DNS'], true)) continue;
        elseif (!empty($conf['required'])) { $pulisci(); return $fine(false, 'non comprato', 'il registro chiede un dato non previsto: ' . $label); }
        else continue;
        $c = sc_ovh('POST', '/order/cart/' . rawurlencode($cid) . '/item/' . rawurlencode($iid) . '/configuration', ['label' => $label, 'value' => $valore]);
        if (is_wp_error($c)) { $pulisci(); return $fine(false, 'non comprato', $c->get_error_message()); }
    }

    $controllo = sc_ovh('GET', '/order/cart/' . rawurlencode($cid) . '/checkout');
    if (is_wp_error($controllo)) { $pulisci(); return $fine(false, 'non comprato', $controllo->get_error_message()); }
    $prezzo = (float) ($controllo['prices']['withTax']['value'] ?? 999);
    $testo_prezzo = $controllo['prices']['withTax']['text'] ?? (number_format($prezzo, 2, ',', '.') . ' €');
    if ($prezzo > $tetto) { $pulisci(); return $fine(false, 'non comprato', "prezzo $testo_prezzo IVA inclusa, sopra il tetto di " . number_format($tetto, 2, ',', '.') . ' €'); }
    $passi[] = 'prezzo ' . $testo_prezzo . ' IVA inclusa';

    $ordine = sc_ovh('POST', '/order/cart/' . rawurlencode($cid) . '/checkout', ['autoPayWithPreferredPaymentMethod' => false, 'waiveRetractationPeriod' => true]);
    if (is_wp_error($ordine) || empty($ordine['orderId'])) return $fine(false, 'non comprato', 'ordine OVH: ' . (is_wp_error($ordine) ? $ordine->get_error_message() : 'nessun numero d\'ordine'));
    $oid = $ordine['orderId'];
    $link = $ordine['url'] ?? '';
    $passi[] = 'ordine OVH ' . $oid;

    $mezzi = sc_ovh('GET', '/me/order/' . rawurlencode($oid) . '/availableRegisteredPaymentMean');
    $prepagato = false;
    foreach ((array) (is_wp_error($mezzi) ? [] : $mezzi) as $m) {
        if (($m['paymentMean'] ?? '') === 'ovhAccount') $prepagato = true;
    }
    if (!$prepagato) return $fine(false, 'ordinato, da pagare', "ordine OVH $oid creato ma il credito prepagato non è disponibile: pagalo a mano $link");
    $paga = sc_ovh('POST', '/me/order/' . rawurlencode($oid) . '/payWithRegisteredPaymentMean', ['paymentMean' => 'ovhAccount']);
    if (is_wp_error($paga)) return $fine(false, 'ordinato, da pagare', "ordine OVH $oid creato ma non pagato (" . $paga->get_error_message() . "): pagalo a mano $link");
    $stato = sc_ovh('GET', '/me/order/' . rawurlencode($oid) . '/status');
    $passi[] = 'stato ordine: ' . (is_wp_error($stato) ? '?' : (is_string($stato) ? $stato : wp_json_encode($stato)));
    return $fine(true, 'comprato', "ordine OVH $oid, $testo_prezzo IVA inclusa, pagato con il credito prepagato");
}

add_action('sc_compra_dominio', 'sc_compra_dominio');
function sc_compra_dominio($sessione, $forza = false) {
    if (function_exists('set_time_limit')) @set_time_limit(180);
    $trova = function () use ($sessione) {
        $o = sc_ordini_leggi();
        foreach ($o as $k => $x) if (($x['pagamento']['sessione'] ?? '') === $sessione) return [$o, $k];
        return [$o, null];
    };
    [$ordini, $k] = $trova();
    if ($k === null || empty($ordini[$k]['dominio'])) return;
    $prima = $ordini[$k]['dominio_acquisto']['stato'] ?? '';
    if ($prima === 'comprato' || ($prima === 'in corso' && !$forza)) return;
    $ordini[$k]['dominio_acquisto'] = ['stato' => 'in corso', 'quando' => wp_date('d/m/Y H:i')];
    sc_ordini_scrivi($ordini);

    $esito = sc_dominio_acquista($ordini[$k]['dominio'], $ordini[$k]['intestatario'] ?? [], false);

    [$ordini, $k] = $trova();
    if ($k === null) return;
    $ordini[$k]['dominio_acquisto'] = ['stato' => $esito['stato'], 'dettaglio' => $esito['dettaglio'], 'passi' => $esito['passi'], 'quando' => wp_date('d/m/Y H:i')];
    sc_ordini_scrivi($ordini);

    $d = $ordini[$k]['dominio'];
    $oggetto = $esito['ok'] ? "Dominio COMPRATO: $d" : "ATTENZIONE dominio NON comprato: $d";
    $righe = [$esito['ok'] ? "Il dominio $d è stato comprato su OVH." : "Il dominio $d NON è stato comprato in automatico: va fatto a mano.",
              'Esito: ' . $esito['stato'] . ' — ' . $esito['dettaglio'], ''];
    if (!empty($ordini[$k]['intestatario'])) { $righe[] = 'Intestatario:'; $righe[] = sc_intestatario_testo($ordini[$k]['intestatario']); $righe[] = ''; }
    if ($esito['passi']) { $righe[] = 'Passaggi: ' . implode(' → ', $esito['passi']); }
    $righe[] = '';
    $righe[] = 'Ordine del cliente: ' . ($ordini[$k]['nome'] ?? '') . ' — pagato il ' . ($ordini[$k]['pagamento']['quando'] ?? '');
    wp_mail(SC_DESTINATARIO, $oggetto, implode("\n", $righe), ['Content-Type: text/plain; charset=UTF-8']);
}

// ---------------------------------------------------------------- 3. pagina «Ordini SitoComodo» nella bacheca
add_action('admin_menu', function () {
    add_menu_page('Ordini SitoComodo', 'Ordini SitoComodo', 'manage_options', 'sc-ordini', 'sc_pagina_ordini', 'dashicons-cart', 3);
});

function sc_pagina_ordini() {
    if (!current_user_can('manage_options')) return;
    $messaggio = '';
    if (isset($_POST['sc_whsec']) && check_admin_referer('sc_salva_stripe')) {
        $v = trim(wp_unslash((string) $_POST['sc_whsec']));
        if (strpos($v, 'whsec_') === 0) {
            update_option('sc_stripe_whsec', $v, false);
            $messaggio = 'Codice Stripe salvato: da ora gli ordini pagati arrivano via email.';
        } elseif ($v !== '') {
            $messaggio = 'Il codice deve iniziare con whsec_ (è il «Segreto di firma» del webhook su Stripe).';
        }
    }
    $prova = null;
    if (isset($_POST['sc_ovh_salva']) && check_admin_referer('sc_ovh')) {
        foreach (['ak' => 'sc_ovh_ak', 'as' => 'sc_ovh_as', 'ck' => 'sc_ovh_ck'] as $campo => $opzione) {
            $v = trim(wp_unslash((string) ($_POST['sc_' . $campo] ?? '')));
            if ($v !== '') update_option($opzione, preg_replace('/[^A-Za-z0-9]/', '', $v), false);
        }
        update_option('sc_ovh_attivo', !empty($_POST['sc_attivo']) ? '1' : '', false);
        $tetto = (float) str_replace(',', '.', (string) ($_POST['sc_tetto'] ?? '12'));
        update_option('sc_ovh_tetto', $tetto > 0 && $tetto <= 50 ? $tetto : 12, false);
        $messaggio = 'Impostazioni OVH salvate.';
    }
    if (isset($_POST['sc_ovh_prova']) && check_admin_referer('sc_ovh')) {
        $dom = strtolower(preg_replace('/[^a-zA-Z0-9.\-]/', '', (string) ($_POST['sc_dominio_prova'] ?? '')));
        $prova = ['dominio' => $dom, 'esito' => sc_dominio_acquista($dom, [], true)];
    }
    if (isset($_POST['sc_riprova']) && check_admin_referer('sc_ovh')) {
        sc_compra_dominio(sanitize_text_field(wp_unslash((string) $_POST['sc_riprova'])), true);
        $messaggio = 'Acquisto del dominio riprovato: l\'esito è nell\'ordine qui sotto e nell\'email a ' . SC_DESTINATARIO . '.';
    }
    $collegato = get_option('sc_stripe_whsec', '') !== '';
    $ordini = array_reverse(sc_ordini_leggi());
    echo '<div class="wrap"><h1>Ordini SitoComodo</h1>';
    if ($messaggio) echo '<div class="notice notice-info"><p>' . esc_html($messaggio) . '</p></div>';
    echo '<p>Pagati = Stripe ha confermato il pagamento e l\'email è partita a ' . esc_html(SC_DESTINATARIO) . '. In attesa = il cliente ha compilato il modulo ma non ha ancora pagato.</p>';
    echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Stato</th><th>Dominio</th><th>Attività</th><th>Contatti</th><th>Foto</th><th>Dettagli</th></tr></thead><tbody>';
    if (!$ordini) echo '<tr><td colspan="7">Ancora nessun ordine.</td></tr>';
    foreach ($ordini as $o) {
        $stato = $o['stato'] ?? 'in attesa di pagamento';
        $colore = strpos($stato, 'pagato') === 0 ? '#1a7f37' : '#9a6700';
        $foto = [];
        foreach (($o['file'] ?? []) as $f) {
            $foto[] = '<a href="' . esc_url(sc_url_foto($o['cartella'], $f['file'])) . '" target="_blank">' . esc_html($f['file']) . '</a>';
        }
        $det = '';
        foreach (sc_campi_ordine() as $k => $etichetta) {
            if (($o[$k] ?? '') === '') continue;
            $det .= '<p><strong>' . esc_html($etichetta) . ':</strong><br>' . nl2br(esc_html($o[$k])) . '</p>';
        }
        if (!empty($o['pagamento'])) {
            $p = $o['pagamento'];
            $det .= '<p><strong>Pagamento:</strong> ' . esc_html($p['importo'] . ' il ' . $p['quando'] . ' — ' . $p['email_cliente']) . '<br>Email a info@: ' . esc_html($o['email_inviata'] ?? '') . '</p>';
        }
        if (!empty($o['intestatario'])) {
            $det .= '<p><strong>Intestatario del dominio:</strong><br>' . nl2br(esc_html(sc_intestatario_testo($o['intestatario']))) . '</p>';
        }
        if (!empty($o['dominio_acquisto'])) {
            $da = $o['dominio_acquisto'];
            $det .= '<p><strong>Dominio su OVH:</strong> ' . esc_html(($da['stato'] ?? '') . ' — ' . ($da['dettaglio'] ?? '') . ' (' . ($da['quando'] ?? '') . ')') . '</p>';
        }
        if (!empty($o['pagamento']['sessione']) && !empty($o['dominio']) && (($o['dominio_acquisto']['stato'] ?? '') !== 'comprato')) {
            $det .= '<form method="post">' . wp_nonce_field('sc_ovh', '_wpnonce', true, false)
                  . '<input type="hidden" name="sc_riprova" value="' . esc_attr($o['pagamento']['sessione']) . '">'
                  . '<button class="button" onclick="return confirm(\'Riprovo a comprare ' . esc_js($o['dominio']) . ' su OVH col credito prepagato?\')">Riprova acquisto dominio</button></form>';
        }
        echo '<tr><td>' . esc_html($o['ricevuto'] ?? '') . '</td>'
            . '<td><strong style="color:' . $colore . '">' . esc_html($stato) . '</strong></td>'
            . '<td>' . esc_html($o['dominio'] ?? ($o['riferimento_stripe'] ?? '')) . '</td>'
            . '<td>' . esc_html($o['nome'] ?? '') . '</td>'
            . '<td>' . esc_html(trim(($o['telefono'] ?? '') . ' ' . ($o['email'] ?? ''))) . '</td>'
            . '<td>' . implode('<br>', $foto) . '</td>'
            . '<td><details><summary>Apri</summary>' . $det . '</details></td></tr>';
    }
    echo '</tbody></table>';
    echo '<h2 style="margin-top:2em">Collegamento con Stripe</h2>';
    echo '<p>Stato: <strong>' . ($collegato ? 'attivo ✔' : 'da completare') . '</strong></p>';
    echo '<p>Indirizzo del webhook da mettere su Stripe (evento <code>checkout.session.completed</code>): <code>' . esc_html(rest_url('sitocomodo/v1/stripe')) . '</code></p>';
    echo '<form method="post">';
    wp_nonce_field('sc_salva_stripe');
    echo '<p><label>Segreto di firma del webhook (inizia con whsec_)<br><input type="password" name="sc_whsec" class="regular-text" autocomplete="off" placeholder="' . ($collegato ? 'già salvato — incolla solo per cambiarlo' : 'whsec_...') . '"></label></p>';
    submit_button('Salva codice Stripe');
    echo '</form>';

    // acquisto automatico dei domini
    $chiavi = get_option('sc_ovh_ak', '') !== '' && get_option('sc_ovh_as', '') !== '' && get_option('sc_ovh_ck', '') !== '';
    $attivo = get_option('sc_ovh_attivo', '') === '1';
    $tetto = (float) get_option('sc_ovh_tetto', 12);
    echo '<h2 style="margin-top:2em">Acquisto automatico dei domini (OVH)</h2>';
    echo '<p>Stato: <strong>' . ($attivo && $chiavi ? 'acceso ✔' : ($chiavi ? 'chiavi inserite, acquisto spento' : 'mancano le chiavi OVH')) . '</strong>. '
        . 'Dopo ogni pagamento il dominio si compra su OVH, intestato al cliente, e si paga col credito prepagato. Se il prezzo supera il tetto, '
        . 'se manca qualcosa o se OVH dice di no, non si compra niente e arriva un\'email a ' . esc_html(SC_DESTINATARIO) . '.</p>';
    $crea = 'https://eu.api.ovh.com/createToken/?GET=/me&GET=/me/ovhAccount&GET=/me/ovhAccount/*&GET=/me/order/*&POST=/me/order/*'
          . '&GET=/order/*&POST=/order/*&DELETE=/order/*&POST=/domain/contact&GET=/domain/configurationRule&POST=/domain/configurationRule/check';
    echo '<p>Le chiavi si creano qui (entri col tuo account OVH, scegli «Validity: Unlimited» e crei): <a href="' . esc_url($crea) . '" target="_blank" rel="noopener">crea le chiavi OVH</a>.</p>';
    echo '<form method="post">';
    wp_nonce_field('sc_ovh');
    $segnaposto = $chiavi ? 'già salvata — incolla solo per cambiarla' : '';
    echo '<p><label>Application Key<br><input type="password" name="sc_ak" class="regular-text" autocomplete="off" placeholder="' . esc_attr($segnaposto) . '"></label></p>';
    echo '<p><label>Application Secret<br><input type="password" name="sc_as" class="regular-text" autocomplete="off" placeholder="' . esc_attr($segnaposto) . '"></label></p>';
    echo '<p><label>Consumer Key<br><input type="password" name="sc_ck" class="regular-text" autocomplete="off" placeholder="' . esc_attr($segnaposto) . '"></label></p>';
    echo '<p><label>Tetto per dominio, IVA inclusa (€)<br><input type="text" name="sc_tetto" value="' . esc_attr(number_format($tetto, 2, ',', '')) . '" style="width:6em"></label></p>';
    echo '<p><label><input type="checkbox" name="sc_attivo" value="1"' . checked($attivo, true, false) . '> Acquisto automatico acceso</label></p>';
    echo '<p><button class="button button-primary" name="sc_ovh_salva" value="1">Salva impostazioni OVH</button></p>';
    echo '<p style="margin-top:1.5em"><label>Prova senza comprare (controlla chiavi, prezzo e credito prepagato)<br>'
        . '<input type="text" name="sc_dominio_prova" class="regular-text" placeholder="es. pizzeriadimario-prova.it" value="' . esc_attr($prova['dominio'] ?? '') . '"></label> '
        . '<button class="button" name="sc_ovh_prova" value="1">Prova</button></p>';
    echo '</form>';
    if ($prova) {
        $e = $prova['esito'];
        echo '<div class="notice ' . ($e['ok'] ? 'notice-success' : 'notice-error') . '"><p><strong>' . esc_html($prova['dominio'] . ': ' . $e['stato']) . '</strong> — ' . esc_html($e['dettaglio']) . '</p>';
        if ($e['passi']) echo '<ul style="list-style:disc;margin-left:2em">' . implode('', array_map(function ($x) { return '<li>' . esc_html($x) . '</li>'; }, $e['passi'])) . '</ul>';
        echo '</div>';
    }
    echo '</div>';
}
