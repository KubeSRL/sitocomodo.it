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
    if ($percorso === '/' . SC_INDEXNOW_KEY . '.txt') {
        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo SC_INDEXNOW_KEY;
        exit;
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
    // pagine: aggiunge il contatore anonimo della pagina «SitoComodo Live» (non per chi è collegato a WordPress)
    if ($est === 'html' && !is_user_logged_in()) {
        $html = (string) file_get_contents($file);
        $pos = strripos($html, '</body>');
        if ($pos !== false) $html = substr($html, 0, $pos) . sc_live_script() . substr($html, $pos);
        header('Content-Length: ' . strlen($html));
        echo $html;
        exit;
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
            $indexnow = null;
            if ($est === 'html' && stripos((string) file_get_contents($dest), 'noindex') === false) {
                $indexnow = sc_indexnow([home_url('/' . preg_replace('#index\.html$#', '', $percorso))]);
            }
            return ['ok' => true, 'percorso' => $percorso, 'byte' => filesize($dest), 'indexnow' => $indexnow];
        },
    ]);
    // invio manuale a IndexNow (solo amministratore)
    register_rest_route('sitocomodo/v1', '/indexnow', [
        'methods' => 'POST',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback' => function (WP_REST_Request $r) {
            $urls = array_values(array_filter(array_map('esc_url_raw', (array) $r->get_param('urls')), function ($u) {
                return strpos($u, home_url('/')) === 0;
            }));
            if (!$urls) return new WP_Error('urls', 'nessun indirizzo del sito', ['status' => 400]);
            return sc_indexnow($urls);
        },
    ]);
});

// IndexNow: avvisa subito Bing (e gli altri motori che lo usano) quando una pagina pubblica cambia.
// La chiave è pubblica per costruzione: il file https://sitocomodo.it/<chiave>.txt la mostra.
define('SC_INDEXNOW_KEY', '4175b0a1b7e6e255692cbce39b823ca4');
function sc_indexnow(array $urls) {
    $host = (string) parse_url(home_url('/'), PHP_URL_HOST);
    $r = wp_remote_post('https://api.indexnow.org/indexnow', [
        'timeout' => 10,
        'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
        'body' => wp_json_encode(['host' => $host, 'key' => SC_INDEXNOW_KEY, 'keyLocation' => home_url('/' . SC_INDEXNOW_KEY . '.txt'), 'urlList' => array_values($urls)]),
    ]);
    $esito = ['quando' => wp_date('d/m/Y H:i'), 'urls' => array_values($urls),
              'codice' => is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r),
              'errore' => is_wp_error($r) ? $r->get_error_message() : substr((string) wp_remote_retrieve_body($r), 0, 300)];
    update_option('sc_indexnow_ultimo', $esito, false);
    return $esito;
}

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
        // 23/11/2026, «Rifai il tuo sito»: dove ha comprato dominio/hosting e con cosa è fatto il sito di adesso
        'hosting_attuale' => 'Dove ha comprato il dominio', 'fatto_con' => 'Con cosa è fatto il sito di adesso',
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
    $dati['offerta'] = sc_campo('offerta', 10) === 'rifai' ? 'rifai' : 'nuovo';   // «rifai» = rifacimento a 79 €, dominio del cliente
    $dati['dns_rilevati'] = sc_campo('dns_rilevati', 300);
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
    $rifai = ($dati['offerta'] ?? '') === 'rifai';
    $righe = ['NUOVO ORDINE SITOCOMODO — PAGATO ✔' . ($rifai ? ' — RIFAI IL TUO SITO' : ''),
              'Pagamento: ' . $pag['importo'] . ' il ' . $pag['quando'] . ' (riferimento Stripe: ' . ($dati['riferimento_stripe'] ?? '') . ')'];
    if (!empty($pag['da_controllare'])) {
        $righe[] = '';
        $righe[] = 'ATTENZIONE: l\'importo pagato (' . $pag['importo'] . ') non corrisponde a questo tipo d\'ordine (' . ($rifai ? 'rifacimento, 79 €' : 'sito nuovo, 99 €') . ').';
        $righe[] = 'Non ho comprato il dominio e non ho avviato la costruzione: controlla il pagamento su Stripe e il cliente.';
    }
    if (!empty($pag['email_cliente'])) {
        $righe[] = 'Email di chi ha pagato: ' . $pag['email_cliente'] . (!empty($pag['nome_cliente']) ? ' (' . $pag['nome_cliente'] . ')' : '');
    }
    $righe[] = '';
    if (empty($dati['dominio'])) {
        $righe[] = 'ATTENZIONE: è arrivato un pagamento ma non c\'è il modulo con testi e foto collegato a questo riferimento.';
        $righe[] = 'Contatta il cliente all\'email qui sopra per farti mandare i dati.';
    } else {
        $righe[] = $rifai ? 'RIFAI IL TUO SITO (79 €): il dominio ' . $dati['dominio'] . ' resta al cliente, NON si compra.'
                          : 'Dominio: ' . $dati['dominio'];
        foreach (sc_campi_ordine() as $k => $etichetta) {
            if (($dati[$k] ?? '') === '') continue;
            $righe[] = (strpos($dati[$k], "\n") !== false) ? "$etichetta:\n{$dati[$k]}" : "$etichetta: {$dati[$k]}";
        }
        $righe[] = 'Diritti su foto e testi confermati: ' . ($dati['diritti'] ?? '');
        $righe[] = '';
        if ($rifai) {
            if (!empty($dati['dns_rilevati'])) $righe[] = 'DNS di adesso: ' . $dati['dns_rilevati'];
            $righe[] = 'La fabbrica legge il sito vecchio, prepara il nuovo su un indirizzo provvisorio e manda al cliente la guida per collegare il dominio (senza toccare l\'email).';
        } else {
            $righe[] = 'INTESTATARIO DEL DOMINIO:';
            $righe[] = empty($dati['intestatario']) ? 'non ancora arrivato: il cliente lo inserisce nella pagina di ringraziamento (se tra 20 minuti manca ancora, ti avviso)'
                                                    : sc_intestatario_testo($dati['intestatario']);
            $righe[] = 'Il dominio viene comprato in automatico su OVH appena ci sono i dati: ti arriva un\'altra email con l\'esito.';
        }
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
        : (!empty($pag['da_controllare']) ? 'ATTENZIONE importo da controllare — ' : '')
          . 'Ordine PAGATO SitoComodo' . ($rifai ? ' (rifai il sito)' : '') . ': ' . $dati['dominio'] . (($dati['nome'] ?? '') !== '' ? ' (' . $dati['nome'] . ')' : '');
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
            // 23/11/2026: l'importo (prima di eventuali sconti) deve corrispondere al tipo d'ordine: 99 € sito nuovo, 79 € rifacimento.
            // Chi paga 79 € su un ordine «sito nuovo» non fa comprare il dominio né partire la fabbrica.
            $rifai = ($ordini[$indice]['offerta'] ?? '') === 'rifai';
            $subtotale = (int) ($s['amount_subtotal'] ?? ($s['amount_total'] ?? 0));
            if (!empty($ordini[$indice]['dominio']) && $subtotale < ($rifai ? 7900 : 9900)) {
                $pag['da_controllare'] = true;
                $ordini[$indice]['stato'] = 'pagato: importo da controllare';
            }
            $ordini[$indice]['pagamento'] = $pag;
            if (empty($ordini[$indice]['intestatario'])) {   // dati arrivati prima del webhook (pagina grazie) o prima del modulo
                $sospesi = get_option('sc_intestatari_sospesi', []);
                foreach (['sess:' . $sessione, $rif] as $chiave) {
                    if ($chiave !== '' && $chiave !== 'sess:' && is_array($sospesi) && !empty($sospesi[$chiave])) {
                        $ordini[$indice]['intestatario'] = $sospesi[$chiave];
                        if (!is_email($ordini[$indice]['intestatario']['email'] ?? '')) {
                            $ordini[$indice]['intestatario']['email'] = sanitize_email($pag['email_cliente'] ?: ($ordini[$indice]['email'] ?? ''));
                        }
                        unset($sospesi[$chiave]);
                        update_option('sc_intestatari_sospesi', $sospesi, false);
                        break;
                    }
                }
            }
            $ordini[$indice]['email_inviata'] = sc_manda_email_pagato($ordini[$indice], $pag);
            sc_ordini_scrivi($ordini);
            // acquisto del dominio su OVH (sito nuovo) o costruzione diretta (rifacimento): subito dopo, fuori dalla risposta a Stripe.
            // 24/11/2026: l'intestatario si chiede dopo il pagamento (pagina grazie). Se non c'è ancora, il dominio si compra
            // appena arriva (/intestatario); il sito parte comunque tra 15 minuti sull'indirizzo provvisorio, e dopo 20 minuti
            // senza dati parte il promemoria (a info@ e, se le email ai clienti sono accese, al cliente).
            if (!empty($ordini[$indice]['dominio']) && empty($pag['da_controllare'])) {
                if ($rifai) {
                    wp_schedule_single_event(time(), 'sc_fabbrica_avvia_evento', [$sessione]);
                } elseif (!empty($ordini[$indice]['intestatario'])) {
                    wp_schedule_single_event(time(), 'sc_compra_dominio', [$sessione]);
                } else {
                    wp_schedule_single_event(time() + 15 * MINUTE_IN_SECONDS, 'sc_fabbrica_avvia_evento', [$sessione]);
                    wp_schedule_single_event(time() + 20 * MINUTE_IN_SECONDS, 'sc_intestatario_promemoria', [$sessione]);
                }
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
// Dal 24/11/2026 i dati dell'intestatario si chiedono DOPO il pagamento: la pagina /grazie/ li manda con la sessione Stripe
// (POST /wp-json/sitocomodo/v1/intestatario con «sessione»). Prima li mandava il checkout con il solo «rif»: resta accettato.
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
        'registro_it' => !empty($d['registro_it']), 'whois' => ($t === 'privato' && !empty($d['whois'])),
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
    if (!empty($i['registro_it'])) $r[] = 'Regole del Registro .it: accettate' . (($i['tipo'] ?? '') === 'privato' ? ' · dati visibili nel Whois: ' . (!empty($i['whois']) ? 'sì' : 'no') : '');
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
            $sessione = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($d['sessione'] ?? ''));
            if ($sessione !== '') return sc_intestatario_dopo_pagamento($sessione, $d);
            if ($rif === '') return new WP_REST_Response(['ok' => false, 'errore' => 'Manca il dominio: torna a «Dominio».'], 400);
            $i = sc_intestatario_pulito($d);
            $err = sc_intestatario_errore($i);
            if ($err !== '') return new WP_REST_Response(['ok' => false, 'errore' => 'Controlla i dati dell\'intestatario: ' . $err . '.'], 400);
            if (substr($rif, -3) === '_it' && empty($i['registro_it'])) return new WP_REST_Response(['ok' => false, 'errore' => 'Per un dominio .it devi accettare le regole del Registro .it.'], 400);
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

// 24/11/2026: intestatario mandato dalla pagina grazie, con la sessione Stripe (la conosce solo chi ha pagato)
function sc_intestatario_dopo_pagamento($sessione, $d) {
    $ordini = sc_ordini_leggi();
    $k = null;
    for ($x = count($ordini) - 1; $x >= 0; $x--) {
        if (($ordini[$x]['pagamento']['sessione'] ?? '') === $sessione) { $k = $x; break; }
    }
    $i = sc_intestatario_pulito($d);
    if ($k !== null && !is_email($i['email'])) {
        $i['email'] = sanitize_email((string) ($ordini[$k]['pagamento']['email_cliente'] ?? '')) ?: sanitize_email((string) ($ordini[$k]['email'] ?? ''));
    }
    $err = sc_intestatario_errore($i);
    if ($err !== '' && !($k === null && $err === 'email non valida')) {   // senza ordine l'email la mette il webhook
        return new WP_REST_Response(['ok' => false, 'errore' => 'Controlla i dati dell\'intestatario: ' . $err . '.'], 400);
    }
    $dominio = $k !== null ? (string) ($ordini[$k]['dominio'] ?? '') : str_replace('_', '.', preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) ($d['rif'] ?? ''))));
    if (preg_match('/\.it$/', $dominio) && empty($i['registro_it'])) {
        return new WP_REST_Response(['ok' => false, 'errore' => 'Per un dominio .it devi accettare le regole del Registro .it.'], 400);
    }
    if ($k === null) {   // il webhook di Stripe non è ancora arrivato: li tiene da parte, li attacca il webhook
        $sospesi = get_option('sc_intestatari_sospesi', []);
        if (!is_array($sospesi)) $sospesi = [];
        $sospesi['sess:' . $sessione] = $i;
        update_option('sc_intestatari_sospesi', array_slice($sospesi, -100, null, true), false);
        return ['ok' => true, 'sospeso' => true];
    }
    if (($ordini[$k]['offerta'] ?? '') === 'rifai') return ['ok' => true, 'non_serve' => true];
    $stato = $ordini[$k]['dominio_acquisto']['stato'] ?? '';
    if (in_array($stato, ['comprato', 'in corso', 'ordinato, da pagare'], true)) return ['ok' => true, 'gia_fatto' => true];
    $ordini[$k]['intestatario'] = $i;
    sc_ordini_scrivi($ordini);
    if (!empty($ordini[$k]['pagamento']['da_controllare'])) return ['ok' => true];   // importo da controllare: decide Giuseppe
    wp_schedule_single_event(time(), 'sc_compra_dominio', [$sessione]);
    spawn_cron();
    return ['ok' => true];
}

// 24/11/2026: 20 minuti dopo il pagamento, se mancano ancora i dati dell'intestatario
add_action('sc_intestatario_promemoria', function ($sessione) {
    $ordini = sc_ordini_leggi();
    foreach ($ordini as $o) {
        if (($o['pagamento']['sessione'] ?? '') !== $sessione) continue;
        if (!empty($o['intestatario']) || ($o['offerta'] ?? '') === 'rifai' || empty($o['dominio'])) return;
        $link = add_query_arg(['session_id' => $sessione, 'dominio' => $o['dominio']], home_url('/grazie/'));
        $cliente = sanitize_email((string) ($o['pagamento']['email_cliente'] ?? '')) ?: sanitize_email((string) ($o['email'] ?? ''));
        $al_cliente = '';
        if ($cliente && get_option('sc_email_cliente', '') === '1') {
            $nome = trim((string) ($o['pagamento']['nome_cliente'] ?? ''));
            $righe_c = [
                'Buongiorno' . ($nome !== '' ? ' ' . strtok($nome, ' ') : '') . ',',
                '',
                'grazie per il tuo ordine. Il sito di ' . ($o['nome'] ?? 'la tua attività') . ' è in preparazione.',
                'Per registrare il dominio ' . $o['dominio'] . ' a tuo nome ci mancano i dati dell\'intestatario, richiesti dal registro dei domini.',
                'Puoi inserirli qui, ci vogliono due minuti:',
                $link,
                '',
                'Appena li riceviamo registriamo il dominio e il sito passa dall\'indirizzo provvisorio al tuo dominio.',
                'Per qualsiasi dubbio puoi rispondere a questa email.',
                '',
                'Il team di SitoComodo',
                SC_DESTINATARIO,
            ];
            $al_cliente = wp_mail($cliente, 'Manca un ultimo dato per il tuo dominio – ' . $o['dominio'], implode("\n", $righe_c),
                ['Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . SC_DESTINATARIO]) ? 'sì' : 'non partita';
        }
        $righe = ['Il cliente ha pagato ma non ha ancora mandato i dati dell\'intestatario: il dominio ' . $o['dominio'] . ' NON è ancora comprato.',
                  'Il sito si costruisce lo stesso sull\'indirizzo provvisorio. Appena il cliente manda i dati, il dominio si compra da solo.',
                  '',
                  'Cliente: ' . ($o['nome'] ?? '') . ' · telefono ' . ($o['telefono'] ?? '') . ' · email ' . $cliente,
                  'Link da mandargli per inserire i dati: ' . $link,
                  'Promemoria al cliente: ' . ($al_cliente !== '' ? $al_cliente : 'no (email ai clienti spente): mandaglielo tu')];
        wp_mail(SC_DESTINATARIO, 'Mancano i dati dell\'intestatario: ' . $o['dominio'], implode("\n", $righe), ['Content-Type: text/plain; charset=UTF-8']);
        return;
    }
});

// risposte alle sezioni del modulo del Registro .it (il cliente le accetta al checkout; il Whois pubblico solo se lo sceglie)
function sc_dichiarazioni_it($persona, $whois) {
    if ($persona) return ['IT_SECT3_INDIVIDUAL' => 'Accept', 'IT_SECT4_INDIVIDUAL' => 'true', 'IT_SECT5_INDIVIDUAL' => $whois ? 'Accept' : 'Do not accept', 'IT_SECT6_INDIVIDUAL' => 'Accept'];
    return ['IT_SECT3_OTHER' => 'Accept', 'IT_SECT4_OTHER' => 'true', 'IT_SECT5_OTHER' => 'Accept'];
}

// somma del credito prepagato OVH in euro, null se non si legge
function sc_ovh_credito() {
    $conti = sc_ovh('GET', '/me/ovhAccount');
    if (is_wp_error($conti)) return null;
    $tot = 0.0;
    foreach ((array) $conti as $id) {
        $c = sc_ovh('GET', '/me/ovhAccount/' . rawurlencode($id));
        if (is_wp_error($c)) return null;
        $tot += (float) ($c['balance']['value'] ?? 0);
    }
    return $tot;
}

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
        if (preg_match('/\.it$/', $dominio) && empty($intestatario['registro_it'])) return $fine(false, 'da comprare a mano', 'il cliente non ha accettato le regole del Registro .it');
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
        }
        // verifica a vuoto (GET checkout non crea ordini) con le dichiarazioni .it di un privato
        if (preg_match('/\.it$/', $dominio)) {
            $err_conf = '';
            sc_ovh('POST', '/order/cart/' . rawurlencode($cid) . '/item/' . rawurlencode($iid) . '/configuration', ['label' => 'OWNER_LEGAL_AGE', 'value' => 'true']);
            foreach (sc_dichiarazioni_it(true, false) as $label => $valore) {
                $c = sc_ovh('POST', '/order/cart/' . rawurlencode($cid) . '/item/' . rawurlencode($iid) . '/configuration', ['label' => $label, 'value' => $valore]);
                if (is_wp_error($c)) $err_conf .= ' ' . $label . ': ' . $c->get_error_message();
            }
            $k = sc_ovh('GET', '/order/cart/' . rawurlencode($cid) . '/checkout');
            $passi[] = 'dichiarazioni Registro .it (verifica a vuoto): ' . (is_wp_error($k) ? 'ERRORE ' . $k->get_error_message() : 'accettate da OVH') . $err_conf;
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
    // dichiarazioni del Registro .it accettate dal cliente al checkout: OVH controlla solo la serie del tipo di intestatario
    $persona = ($contatto['legalForm'] ?? '') === 'individual';
    $dichiarazioni_it = sc_dichiarazioni_it($persona, !empty($intestatario['whois']));
    foreach ((array) $req as $conf) {
        $label = $conf['label'] ?? '';
        $valore = null;
        if (strpos($label, 'IT_SECT') === 0) {
            if (strpos($label, $persona ? '_INDIVIDUAL' : '_OTHER') === false) continue;
            if (!isset($dichiarazioni_it[$label])) { $pulisci(); return $fine(false, 'non comprato', 'il registro chiede un dato non previsto: ' . $label); }
            $valore = $dichiarazioni_it[$label];
        }
        elseif ($label === 'OWNER_CONTACT') $valore = '/me/contact/' . $nuovo['id'];
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
    $credito = sc_ovh_credito();
    if ($credito === null) { $pulisci(); return $fine(false, 'non comprato', 'non riesco a leggere il credito prepagato OVH'); }
    if ($credito + 0.001 < $prezzo) { $pulisci(); return $fine(false, 'non comprato', 'credito prepagato OVH insufficiente: ' . number_format($credito, 2, ',', '.') . ' €, ne servono ' . $testo_prezzo . '. Ricarica il credito e clicca «Riprova acquisto dominio».'); }
    $passi[] = 'credito prepagato prima dell\'acquisto: ' . number_format($credito, 2, ',', '.') . ' €';

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
    // il dominio è a posto (o è stato segnalato): ora la fabbrica costruisce il sito
    if ($esito['ok'] && !empty($ordini[$k]['sito']['avviata'])) {
        // 24/11/2026: sito già costruito sull'indirizzo provvisorio prima dei dati dell'intestatario: si riprova a collegare il dominio
        $ordini[$k]['sito']['tentativi'] = 0;
        sc_ordini_scrivi($ordini);
        if (!wp_next_scheduled('sc_fabbrica_ricollega', [$sessione])) {
            wp_schedule_single_event(time() + 10 * MINUTE_IN_SECONDS, 'sc_fabbrica_ricollega', [$sessione]);
        }
        return;
    }
    sc_fabbrica_avvia($sessione);
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
    if (isset($_POST['sc_fabbrica_salva']) && check_admin_referer('sc_fabbrica')) {
        $v = trim(wp_unslash((string) ($_POST['sc_gh_token'] ?? '')));
        if ($v !== '') update_option('sc_gh_token', preg_replace('/[^A-Za-z0-9_]/', '', $v), false);
        $repo = trim(wp_unslash((string) ($_POST['sc_gh_repo'] ?? '')));
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) update_option('sc_gh_repo', $repo, false);
        update_option('sc_fabbrica_attiva', !empty($_POST['sc_fabbrica_attiva']) ? '1' : '', false);
        update_option('sc_email_cliente', !empty($_POST['sc_email_cliente']) ? '1' : '', false);
        $messaggio = 'Impostazioni della costruzione automatica salvate.';
    }
    if (isset($_POST['sc_fabbrica_prova']) && check_admin_referer('sc_fabbrica')) {
        $e = sc_fabbrica_dispatch('ordine-pagato', sc_fabbrica_ordine_di_prova());
        $messaggio = is_wp_error($e) ? 'Prova NON partita: ' . $e->get_error_message()
                                     : 'Prova partita: tra qualche minuto arriva l\'esito per email a ' . SC_DESTINATARIO . '.';
    }
    if (isset($_POST['sc_fabbrica_riprova']) && check_admin_referer('sc_fabbrica')) {
        $e = sc_fabbrica_avvia(sanitize_text_field(wp_unslash((string) $_POST['sc_fabbrica_riprova'])), 'ordine-pagato', true);
        $messaggio = is_wp_error($e) ? 'Costruzione NON partita: ' . $e->get_error_message() : 'Costruzione del sito ripartita.';
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
        $det = (($o['offerta'] ?? '') === 'rifai') ? '<p><strong>RIFAI IL TUO SITO (79 €)</strong>: il dominio resta al cliente.'
               . (!empty($o['dns_rilevati']) ? '<br>DNS di adesso: ' . esc_html($o['dns_rilevati']) : '') . '</p>' : '';
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
        if (!empty($o['sito'])) {
            $s = $o['sito'];
            $det .= '<p><strong>Sito:</strong> ' . esc_html(($s['stato'] ?? '') . (!empty($s['aggiornato']) ? ' (' . $s['aggiornato'] . ')' : ''));
            if (!empty($s['url_finale'])) $det .= '<br><a href="' . esc_url($s['url_finale']) . '" target="_blank" rel="noopener">' . esc_html($s['url_finale']) . '</a>';
            if (!empty($s['url_anteprima'])) $det .= '<br>Anteprima: <a href="' . esc_url($s['url_anteprima']) . '" target="_blank" rel="noopener">' . esc_html($s['url_anteprima']) . '</a>';
            foreach (array_merge($s['note_per_giuseppe'] ?? [], $s['note'] ?? []) as $nota) $det .= '<br>· ' . esc_html($nota);
            if (!empty($s['run'])) $det .= '<br><a href="' . esc_url($s['run']) . '" target="_blank" rel="noopener">Dettagli su GitHub</a>';
            $det .= '</p>';
        }
        if (!empty($o['pagamento']['sessione'])) {
            $det .= '<form method="post">' . wp_nonce_field('sc_fabbrica', '_wpnonce', true, false)
                  . '<input type="hidden" name="sc_fabbrica_riprova" value="' . esc_attr($o['pagamento']['sessione']) . '">'
                  . '<button class="button" onclick="return confirm(\'Ricostruisco e ripubblico il sito di ' . esc_js($o['dominio'] ?? '') . '?\')">Ricostruisci il sito</button></form>';
        }
        if (!empty($o['pagamento']['sessione']) && !empty($o['dominio']) && (($o['offerta'] ?? '') !== 'rifai') && (($o['dominio_acquisto']['stato'] ?? '') !== 'comprato')) {
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

    // costruzione automatica dei siti (GitHub)
    $segreto = sc_fabbrica_segreto();
    $gh = get_option('sc_gh_token', '') !== '';
    $fab = get_option('sc_fabbrica_attiva', '') === '1';
    echo '<h2 style="margin-top:2em">Costruzione automatica dei siti (GitHub)</h2>';
    echo '<p>Stato: <strong>' . ($fab && $gh ? 'accesa ✔' : ($gh ? 'chiave inserita, costruzione spenta' : 'manca la chiave GitHub')) . '</strong>. '
        . 'Dopo il pagamento (e l\'acquisto del dominio) il sito si costruisce su GitHub, va online sull\'hosting OVH dei clienti e l\'esito arriva per email a ' . esc_html(SC_DESTINATARIO) . '.</p>';
    echo '<p>Segreto da copiare su GitHub, nei segreti del repository, con il nome <code>SC_SEGRETO</code>: <code style="user-select:all">' . esc_html($segreto) . '</code></p>';
    echo '<form method="post">';
    wp_nonce_field('sc_fabbrica');
    echo '<p><label>Chiave GitHub (token con permesso «Contents: read and write» sul repository)<br><input type="password" name="sc_gh_token" class="regular-text" autocomplete="off" placeholder="' . ($gh ? 'già salvata — incolla solo per cambiarla' : 'github_pat_...') . '"></label></p>';
    echo '<p><label>Repository<br><input type="text" name="sc_gh_repo" class="regular-text" value="' . esc_attr(get_option('sc_gh_repo', 'KubeSRL/sitocomodo-fabbrica')) . '"></label></p>';
    echo '<p><label><input type="checkbox" name="sc_fabbrica_attiva" value="1"' . checked($fab, true, false) . '> Costruzione automatica accesa</label></p>';
    echo '<p><label><input type="checkbox" name="sc_email_cliente" value="1"' . checked(get_option('sc_email_cliente', '') === '1', true, false) . '> Quando il sito è online, manda l\'email al cliente</label></p>';
    echo '<p><button class="button button-primary" name="sc_fabbrica_salva" value="1">Salva</button> ';
    echo '<button class="button" name="sc_fabbrica_prova" value="1">Fai una prova</button> <span class="description">(sito finto in una cartella di prova: nessun dominio, nessun cliente)</span></p>';
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


// ---------------------------------------------------------------- 5. «SitoComodo Live»: chi c'è sul sito adesso, carrelli abbandonati, ordini
// Conteggio anonimo: niente cookie, niente dati salvati sul telefono del visitatore, niente IP. Ogni pagina aperta
// genera un numero a caso che vive solo finché la pagina è aperta; il server tiene pagina, passo e provenienza generica.
function sc_live_script() {
    return '<script>(function(){try{if(navigator.webdriver)return;'
        . 'var id=Math.random().toString(36).slice(2)+Date.now().toString(36),u="/wp-json/sitocomodo/v1/live",'
        . 'q=new URLSearchParams(location.search),s=q.get("utm_source")||(q.has("fbclid")?"meta-ads":"");'
        . 'if(!s&&document.referrer){try{var h=new URL(document.referrer).hostname;if(h!==location.hostname)s=h;}catch(e){}}'
        . 'function m(e){var b=JSON.stringify({id:id,p:location.pathname,e:e,s:e==="vista"?s:""});'
        . 'if(e==="via"&&navigator.sendBeacon){navigator.sendBeacon(u,new Blob([b],{type:"application/json"}));return;}'
        . 'fetch(u,{method:"POST",headers:{"Content-Type":"application/json"},body:b,keepalive:true}).catch(function(){});}'
        . 'm("vista");setInterval(function(){if(document.visibilityState==="visible")m("ping");},20000);'
        . 'document.addEventListener("visibilitychange",function(){m(document.visibilityState==="visible"?"ping":"via");});'
        . 'addEventListener("pagehide",function(){m("via");});'
        // i quattro passaggi dentro /ordine/: li chiama la pagina del modulo, ognuno al massimo una volta per pagina
        . 'var fatte={};window.scTappa=function(t){if(fatte[t])return;fatte[t]=1;'
        . 'var b=JSON.stringify({id:id,p:location.pathname,e:"tappa",t:t,s:""});'
        . 'if(navigator.sendBeacon&&navigator.sendBeacon(u,new Blob([b],{type:"application/json"})))return;'
        . 'fetch(u,{method:"POST",headers:{"Content-Type":"application/json"},body:b,keepalive:true}).catch(function(){});};'
        . '}catch(e){}})();</script>';
}

function sc_live_passi() {
    return ['home' => 'Home', 'esempi' => 'Guarda gli esempi', 'rifai' => 'Pagina «Rifai il tuo sito»', 'dominio' => 'Sceglie il modello', 'modulo' => 'Apre il modulo',
            'dati' => 'Ha compilato i dati', 'liberi' => 'Vede i domini liberi', 'scelto' => 'Ha scelto il dominio',
            'paga' => 'Clicca «Vai al pagamento»',
            'pagamento' => 'Pagina di pagamento', 'pagato' => 'Ha pagato (pagina grazie)', 'altro' => 'Altre pagine'];
}

// passaggi dentro la pagina /ordine/ (passo 2 e 3), mandati dalla pagina con window.scTappa
function sc_live_tappe() {
    return ['dati', 'liberi', 'scelto', 'paga'];
}

function sc_live_passo($percorso) {
    $p = '/' . trim(strtolower((string) $percorso), '/') . '/';
    if ($p === '//' ) return 'home';
    if (strpos($p, '/esempi/') === 0) return 'esempi';
    $mappa = ['/rifai-il-tuo-sito/' => 'rifai', '/ordina/' => 'dominio', '/ordine/' => 'modulo', '/checkout/' => 'pagamento', '/grazie/' => 'pagato'];
    return $mappa[$p] ?? 'altro';
}

function sc_live_fonte($s) {
    $s = strtolower(trim((string) $s));
    if ($s === '') return 'Diretto';
    if ($s === 'meta-ads') return 'Inserzioni Meta';
    if ($s === 'an') return 'Audience Network';
    if (strpos($s, 'instagram') !== false || $s === 'ig') return 'Instagram';
    if (strpos($s, 'facebook') !== false || $s === 'fb') return 'Facebook';
    if (strpos($s, 'google') !== false) return 'Google';
    if (strpos($s, 'bing') !== false) return 'Bing';
    if (strpos($s, 'whatsapp') !== false || strpos($s, 'wa.me') !== false) return 'WhatsApp';
    if (strpos($s, 'tiktok') !== false) return 'TikTok';
    return mb_substr(preg_replace('/^www\./', '', preg_replace('/[^a-z0-9.\-]/', '', $s)), 0, 40) ?: 'Altro';
}

// data/ora salvate negli ordini («19/11/2026 18:40» o con i secondi) → timestamp
function sc_ts($testo) {
    foreach (['d/m/Y H:i:s', 'd/m/Y H:i'] as $f) {
        $d = DateTime::createFromFormat($f, (string) $testo, wp_timezone());
        if ($d) return $d->getTimestamp();
    }
    return 0;
}

add_action('rest_api_init', function () {
    // pubblico: riceve «vista», «ping» (ogni 20 secondi), «via» e «tappa» (passaggi dentro il modulo) dalle pagine del sito
    register_rest_route('sitocomodo/v1', '/live', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $r) {
            if (preg_match('/bot|crawl|spider|slurp|facebookexternalhit|lighthouse|headless|preview/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) return ['ok' => true];
            $d = $r->get_json_params();
            if (!is_array($d)) $d = json_decode((string) $r->get_body(), true);
            if (!is_array($d)) return new WP_REST_Response(['ok' => false], 400);
            $id = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($d['id'] ?? '')));
            $e = (string) ($d['e'] ?? '');
            if ($id === '' || strlen($id) > 40 || !in_array($e, ['vista', 'ping', 'via', 'tappa'], true)) return new WP_REST_Response(['ok' => false], 400);
            $passo = sc_live_passo(substr((string) ($d['p'] ?? '/'), 0, 200));
            if ($e === 'tappa') {
                $t = (string) ($d['t'] ?? '');
                if ($passo !== 'modulo' || !in_array($t, sc_live_tappe(), true)) return new WP_REST_Response(['ok' => false], 400);
                $passo = $t;
            }
            $ora = time();
            $attivi = get_option('sc_live_attivi', []);
            if (!is_array($attivi)) $attivi = [];
            foreach ($attivi as $k => $a) if ($ora - (int) ($a['t'] ?? 0) > 60) unset($attivi[$k]);
            if ($e === 'via') {
                unset($attivi[$id]);
            } elseif (isset($attivi[$id]) || count($attivi) < 500) {
                $prima = $attivi[$id] ?? [];
                // nel modulo il «ping» non deve far tornare indietro chi è già a un passaggio successivo
                if ($e === 'ping' && $passo === 'modulo' && in_array($prima['passo'] ?? '', sc_live_tappe(), true)) $passo = $prima['passo'];
                $attivi[$id] = ['passo' => $passo, 't' => $ora, 'da' => (int) ($prima['da'] ?? $ora),
                                'fonte' => $e === 'vista' ? sc_live_fonte($d['s'] ?? '') : ($prima['fonte'] ?? 'Diretto')];
            }
            update_option('sc_live_attivi', $attivi, false);
            if ($e === 'tappa') {
                $giorni = get_option('sc_live_giorni', []);
                if (!is_array($giorni)) $giorni = [];
                $g = wp_date('Y-m-d');
                $giorni[$g]['passi'][$passo] = (int) ($giorni[$g]['passi'][$passo] ?? 0) + 1;
                ksort($giorni);
                update_option('sc_live_giorni', array_slice($giorni, -60, null, true), false);
            }
            if ($e === 'vista') {
                $giorni = get_option('sc_live_giorni', []);
                if (!is_array($giorni)) $giorni = [];
                $g = wp_date('Y-m-d');
                $f = sc_live_fonte($d['s'] ?? '');
                if ($f === 'Audience Network') {
                    // tenute a parte: Audience Network è escluso dalle inserzioni, non entra nei passi né nelle provenienze
                    $giorni[$g]['an'][$passo] = (int) ($giorni[$g]['an'][$passo] ?? 0) + 1;
                } else {
                    $giorni[$g]['passi'][$passo] = (int) ($giorni[$g]['passi'][$passo] ?? 0) + 1;
                    if ($passo === 'home' || $f !== 'Diretto') $giorni[$g]['fonti'][$f] = (int) ($giorni[$g]['fonti'][$f] ?? 0) + 1;
                }
                ksort($giorni);
                update_option('sc_live_giorni', array_slice($giorni, -60, null, true), false);
            }
            return ['ok' => true];
        },
    ]);
    // solo amministratore: i dati della pagina «SitoComodo Live»
    register_rest_route('sitocomodo/v1', '/live-dati', [
        'methods' => 'GET',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback' => function () { return sc_live_dati(); },
    ]);
});

function sc_live_dati() {
    $ora = time();
    $passi = sc_live_passi();
    $attivi = get_option('sc_live_attivi', []);
    $adesso = array_fill_keys(array_keys($passi), 0);
    $persone = [];
    $an_adesso = 0;
    foreach ((is_array($attivi) ? $attivi : []) as $a) {
        if ($ora - (int) ($a['t'] ?? 0) > 45) continue;
        if (($a['fonte'] ?? '') === 'Audience Network') { $an_adesso++; continue; }
        $p = $a['passo'] ?? 'altro';
        $adesso[$p] = ($adesso[$p] ?? 0) + 1;
        $persone[] = ['passo' => $passi[$p] ?? $p, 'chiave' => $p, 'minuti' => max(0, (int) floor(($ora - (int) ($a['da'] ?? $ora)) / 60)), 'fonte' => $a['fonte'] ?? ''];
    }
    $ordine_passi = array_flip(array_keys($passi));
    usort($persone, function ($x, $y) use ($ordine_passi) { return ($ordine_passi[$y['chiave']] ?? 0) <=> ($ordine_passi[$x['chiave']] ?? 0); });

    $giorni = get_option('sc_live_giorni', []);
    $giorni = is_array($giorni) ? $giorni : [];
    $oggi = wp_date('Y-m-d');
    // Audience Network: aperture tenute a parte. Quelle di prima della separazione (23/11) erano salvate come
    // provenienza «an» e sono comprese anche nei passi: le contiamo qui e le segnaliamo come «vecchie».
    $an = function ($gg) {
        $nuove = array_sum(array_map('intval', is_array($gg['an'] ?? null) ? $gg['an'] : []));
        $vecchie = (int) ($gg['fonti']['an'] ?? 0);
        return ['totale' => $nuove + $vecchie, 'vecchie' => $vecchie];
    };
    $settimana = [];
    for ($i = 6; $i >= 0; $i--) {
        $g = wp_date('Y-m-d', $ora - $i * DAY_IN_SECONDS);
        $settimana[] = ['giorno' => wp_date('D j', $ora - $i * DAY_IN_SECONDS), 'passi' => $giorni[$g]['passi'] ?? new stdClass(), 'an' => $an($giorni[$g] ?? [])];
    }
    $fonti_oggi = $giorni[$oggi]['fonti'] ?? [];
    unset($fonti_oggi['an']);

    $carrelli = [];
    $pagati = [];
    $pagati_oggi = 0;
    $inizio_oggi = (new DateTime('today', wp_timezone()))->getTimestamp();
    foreach (array_reverse(sc_ordini_leggi()) as $o) {
        $stato = $o['stato'] ?? 'in attesa di pagamento';
        $t = sc_ts($o['ricevuto'] ?? '');
        if (strpos($stato, 'pagato') === 0) {
            $tp = sc_ts($o['pagamento']['quando'] ?? '') ?: $t;
            if ($tp >= $inizio_oggi) $pagati_oggi++;
            if (count($pagati) < 10) $pagati[] = ['quando' => $o['pagamento']['quando'] ?? ($o['ricevuto'] ?? ''), 'nome' => $o['nome'] ?? '', 'dominio' => $o['dominio'] ?? '',
                'importo' => $o['pagamento']['importo'] ?? '', 'dominio_ovh' => $o['dominio_acquisto']['stato'] ?? ''];
            continue;
        }
        if ($t && $ora - $t > 14 * DAY_IN_SECONDS) continue;
        if (count($carrelli) >= 30) continue;
        $tint = sc_ts($o['intestatario']['salvato'] ?? '');
        $carrelli[] = [
            'quando' => $o['ricevuto'] ?? '', 'minuti' => $t ? (int) floor(($ora - $t) / 60) : null,
            'nome' => $o['nome'] ?? '', 'tipo' => $o['tipo'] ?? '', 'dominio' => $o['dominio'] ?? '',
            'telefono' => $o['telefono'] ?? '', 'whatsapp' => $o['whatsapp'] ?? '', 'email' => $o['email'] ?? ($o['intestatario']['email'] ?? ''),
            'su_stripe' => $tint > 0, 'stripe_minuti' => $tint ? (int) floor(($ora - $tint) / 60) : null,
        ];
    }
    usort($carrelli, function ($x, $y) { return ($x['minuti'] ?? PHP_INT_MAX) <=> ($y['minuti'] ?? PHP_INT_MAX); });
    $su_stripe_ora = count(array_filter($carrelli, function ($c) { return $c['su_stripe'] && $c['stripe_minuti'] !== null && $c['stripe_minuti'] <= 30; }));

    return [
        'ora' => wp_date('H:i:s'),
        'adesso' => ['totale' => count($persone), 'passi' => $adesso, 'persone' => $persone, 'su_stripe' => $su_stripe_ora, 'an' => $an_adesso],
        'nomi_passi' => $passi,
        'oggi' => ['passi' => $giorni[$oggi]['passi'] ?? new stdClass(), 'fonti' => $fonti_oggi ?: new stdClass(), 'pagati' => $pagati_oggi, 'an' => $an($giorni[$oggi] ?? [])],
        'settimana' => $settimana,
        'carrelli' => $carrelli,
        'pagati' => $pagati,
    ];
}

add_action('admin_menu', function () {
    add_menu_page('SitoComodo Live', 'SitoComodo Live', 'manage_options', 'sc-live', 'sc_pagina_live', 'dashicons-chart-line', 2);
});

function sc_pagina_live() {
    $nonce = wp_create_nonce('wp_rest');
    $url = esc_url_raw(rest_url('sitocomodo/v1/live-dati'));
    $html = <<<'SC_LIVE_HTML'
<div class="wrap sc-live">
<style>
.sc-live{max-width:980px}
.sc-live h1{display:flex;align-items:center;gap:10px}
.sc-live .agg{font-size:13px;color:#646970;font-weight:400}
.sc-live .riga{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:16px 0}
.sc-live .box{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px}
.sc-live .num{font-size:40px;font-weight:600;line-height:1.1;font-variant-numeric:tabular-nums}
.sc-live .lab{font-size:13px;color:#50575e;margin-top:4px}
.sc-live .vivo{display:inline-block;width:10px;height:10px;border-radius:50%;background:#00a32a;margin-right:6px;animation:sc-pulsa 2s infinite}
@keyframes sc-pulsa{0%{box-shadow:0 0 0 0 rgba(0,163,42,.5)}70%{box-shadow:0 0 0 10px rgba(0,163,42,0)}100%{box-shadow:0 0 0 0 rgba(0,163,42,0)}}
@media (prefers-reduced-motion:reduce){.sc-live .vivo{animation:none}}
.sc-live h2{margin:28px 0 10px;font-size:17px}
.sc-live .imbuto div{display:grid;grid-template-columns:200px 1fr 48px;align-items:center;gap:10px;margin:6px 0;font-size:14px}
.sc-live .barra{height:14px;background:#f0f0f1;border-radius:3px;overflow:hidden}
.sc-live .barra i{display:block;height:100%;background:#2271b1}
.sc-live .imbuto b{text-align:right;font-variant-numeric:tabular-nums}
.sc-live .tab{overflow-x:auto;-webkit-overflow-scrolling:touch}
.sc-live .tab th{white-space:nowrap}
.sc-live .stato{display:inline-block;white-space:nowrap;margin-top:3px;font-size:12px;padding:1px 6px;border-radius:3px;background:#f0f0f1;color:#50575e}
.sc-live .stato.stripe{background:#fcf0d9;color:#8a5a00;font-weight:600}
.sc-live table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #dcdcde}
.sc-live th,.sc-live td{text-align:left;padding:9px 10px;border-bottom:1px solid #f0f0f1;font-size:14px;vertical-align:top}
.sc-live th{font-weight:600;background:#f6f7f7}
.sc-live td.n{text-align:right;font-variant-numeric:tabular-nums}
.sc-live .vuoto{color:#646970;padding:12px;background:#fff;border:1px dashed #c3c4c7;border-radius:6px}
.sc-live .persona{display:flex;justify-content:space-between;gap:10px;padding:8px 12px;background:#fff;border:1px solid #dcdcde;border-radius:6px;margin:6px 0;font-size:14px}
.sc-live .persona.caldo{border-color:#00a32a;background:#f0faf2}
@media (max-width:600px){.sc-live .imbuto div{grid-template-columns:140px 1fr 36px}.sc-live .num{font-size:32px}.sc-live .nasc{display:none}}
</style>
<h1><span class="vivo" aria-hidden="true"></span>SitoComodo Live <span class="agg" id="sc-agg">collegamento…</span></h1>

<div class="riga">
  <div class="box"><div class="num" id="sc-ora">–</div><div class="lab">sul sito adesso</div></div>
  <div class="box"><div class="num" id="sc-pag-ora">–</div><div class="lab">sulla pagina di pagamento adesso</div></div>
  <div class="box"><div class="num" id="sc-stripe">–</div><div class="lab">andati a pagare su Stripe (ultimi 30 min), non ancora pagato</div></div>
  <div class="box"><div class="num" id="sc-pagati">–</div><div class="lab">ordini pagati oggi</div></div>
</div>

<h2>Chi c'è adesso</h2>
<div id="sc-persone"></div>

<h2>Oggi, passo per passo</h2>
<div class="box imbuto" id="sc-imbuto"></div>
<p class="agg" id="sc-fonti"></p>
<p class="agg" id="sc-an"></p>

<h2>Carrelli abbandonati</h2>
<p class="agg">Hanno compilato il modulo ma non hanno pagato (ultimi 14 giorni). «Fermo su Stripe» vuol dire che sono arrivati alla pagina di pagamento di Stripe e non hanno finito.</p>
<div id="sc-carrelli"></div>

<h2>Ultimi ordini pagati</h2>
<div id="sc-ordini"></div>

<h2>Ultimi 7 giorni</h2>
<div id="sc-settimana"></div>
<p class="agg" id="sc-an-nota"></p>
<p class="agg">Il conteggio è anonimo: niente cookie e niente dati personali dei visitatori. Le tue visite da collegato a WordPress non contano. La pagina si aggiorna da sola ogni 5 secondi.</p>
</div>
<script>
(function(){
  var URL_DATI = %%URL%%, NONCE = %%NONCE%%;
  var $ = function(id){ return document.getElementById(id); };
  var esc = function(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
  var tempo = function(m){ if (m == null) return ''; if (m < 1) return 'adesso'; if (m < 60) return m + ' min fa'; if (m < 1440) return Math.floor(m/60) + ' h fa'; return Math.floor(m/1440) + ' g fa'; };
  var ORDINE = ['home','esempi','rifai','dominio','modulo','dati','liberi','scelto','paga','pagamento','pagato','altro'];
  var COLONNE = [['home','Home'],['rifai','Rifai'],['dominio','Modello'],['modulo','Modulo'],['dati','Dati'],['liberi','Domini liberi'],['scelto','Dominio scelto'],['paga','Vai al pagamento'],['pagamento','Pagamento'],['pagato','Pagato']];
  function disegna(d){
    $('sc-agg').textContent = 'aggiornato alle ' + d.ora;
    $('sc-ora').textContent = d.adesso.totale;
    $('sc-pag-ora').textContent = d.adesso.passi.pagamento || 0;
    $('sc-stripe').textContent = d.adesso.su_stripe;
    $('sc-pagati').textContent = d.oggi.pagati;
    $('sc-persone').innerHTML = d.adesso.persone.length ? d.adesso.persone.map(function(p){
      var caldo = ['modulo','dati','liberi','scelto','paga','pagamento'].indexOf(p.chiave) >= 0;
      return '<div class="persona' + (caldo ? ' caldo' : '') + '"><span><strong>' + esc(p.passo) + '</strong> · da ' + esc(p.fonte || 'Diretto') + '</span><span>' + (p.minuti < 1 ? 'appena arrivato' : 'sul sito da ' + p.minuti + ' min') + '</span></div>';
    }).join('') : '<div class="vuoto">Nessuno sul sito in questo momento.</div>';
    var anOra = d.adesso.an || 0;
    if (anOra) $('sc-persone').innerHTML += '<p class="agg">Più ' + anOra + ' da Audience Network, ' + (anOra === 1 ? 'tenuto' : 'tenuti') + ' a parte.</p>';
    var oggi = d.oggi.passi || {}, max = 1;
    ORDINE.forEach(function(k){ max = Math.max(max, oggi[k] || 0); });
    $('sc-imbuto').innerHTML = ORDINE.map(function(k){
      var v = oggi[k] || 0;
      return '<div><span>' + esc(d.nomi_passi[k]) + '</span><span class="barra"><i style="width:' + Math.round(v / max * 100) + '%"></i></span><b>' + v + '</b></div>';
    }).join('');
    var f = d.oggi.fonti || {}, chiavi = Object.keys(f).sort(function(a,b){ return f[b] - f[a]; });
    $('sc-fonti').textContent = chiavi.length ? 'Da dove arrivano oggi: ' + chiavi.map(function(k){ return k + ' ' + f[k]; }).join(' · ') : '';
    var an = d.oggi.an || {totale: 0, vecchie: 0}, nuove = an.totale - an.vecchie;
    $('sc-an').textContent = !an.totale ? '' : 'Audience Network, tenuto a parte: ' + an.totale + (an.totale === 1 ? ' apertura' : ' aperture') + ' oggi. '
      + (!an.vecchie ? 'Non sono nei numeri qui sopra.' : !nuove ? 'Sono tutte di prima della separazione e restano anche nei passi qui sopra.' : an.vecchie + ' sono di prima della separazione e restano anche nei passi qui sopra; le altre ' + nuove + ' no.');
    $('sc-carrelli').innerHTML = d.carrelli.length ? '<div class="tab"><table><thead><tr><th>Quando</th><th>Attività</th><th>Dominio</th><th>Contatti</th></tr></thead><tbody>' + d.carrelli.map(function(c){
      var tel = c.telefono || c.whatsapp, cont = [];
      if (tel) cont.push('<a href="tel:' + esc(tel.replace(/[^0-9+]/g, '')) + '">' + esc(tel) + '</a>');
      if (c.email) cont.push('<a href="mailto:' + esc(c.email) + '">' + esc(c.email) + '</a>');
      var fermo = c.su_stripe ? '<span class="stato stripe">Fermo su Stripe · ' + esc(tempo(c.stripe_minuti)) + '</span>' : '<span class="stato">Fermo dopo il modulo</span>';
      return '<tr><td>' + esc(tempo(c.minuti)) + '</td><td><strong>' + esc(c.nome) + '</strong>' + (c.tipo ? '<br>' + esc(c.tipo) : '') + '<br>' + fermo + '</td><td>' + esc(c.dominio) + '</td><td>' + (cont.join('<br>') || '—') + '</td></tr>';
    }).join('') + '</tbody></table></div>' : '<div class="vuoto">Nessun carrello abbandonato.</div>';
    $('sc-ordini').innerHTML = d.pagati.length ? '<div class="tab"><table><thead><tr><th>Pagato</th><th>Attività</th><th>Dominio</th><th class="n">Importo</th><th class="nasc">Dominio su OVH</th></tr></thead><tbody>' + d.pagati.map(function(o){
      return '<tr><td>' + esc(o.quando) + '</td><td><strong>' + esc(o.nome) + '</strong></td><td>' + esc(o.dominio) + '</td><td class="n">' + esc(o.importo) + '</td><td class="nasc">' + esc(o.dominio_ovh || '—') + '</td></tr>';
    }).join('') + '</tbody></table></div>' : '<div class="vuoto">Ancora nessun ordine pagato.</div>';
    $('sc-settimana').innerHTML = '<div class="tab"><table><thead><tr><th>Giorno</th>' + COLONNE.map(function(c){ return '<th class="n">' + esc(c[1]) + '</th>'; }).join('') + '<th class="n">Audience Network</th></tr></thead><tbody>' + d.settimana.map(function(g){
      var p = g.passi || {}, a = g.an || {totale: 0, vecchie: 0};
      return '<tr><td>' + esc(g.giorno) + '</td>' + COLONNE.map(function(c){ return '<td class="n">' + (p[c[0]]||0) + '</td>'; }).join('') + '<td class="n">' + (a.totale||0) + (a.vecchie ? '*' : '') + '</td></tr>';
    }).join('') + '</tbody></table></div>';
    var conVecchie = d.settimana.some(function(g){ return g.an && g.an.vecchie; });
    $('sc-an-nota').textContent = conVecchie ? '* Prima della separazione (23/11) le aperture da Audience Network erano contate anche nei passi di quel giorno.' : '';
  }
  function carica(){
    fetch(URL_DATI, {headers: {'X-WP-Nonce': NONCE}, credentials: 'same-origin'})
      .then(function(r){ if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(disegna)
      .catch(function(e){ $('sc-agg').textContent = e.message === '403' || e.message === '401' ? 'sessione scaduta: ricarica la pagina' : 'connessione assente, riprovo…'; });
  }
  carica();
  setInterval(function(){ if (document.visibilityState === 'visible') carica(); }, 5000);
  document.addEventListener('visibilitychange', function(){ if (document.visibilityState === 'visible') carica(); });
})();
</script>
SC_LIVE_HTML;
    echo str_replace(['%%URL%%', '%%NONCE%%'], [wp_json_encode($url), wp_json_encode($nonce)], $html);
}

// ---------------------------------------------------------------- 6. Fabbrica: il sito del cliente si costruisce da solo
// Dopo il pagamento WordPress avvia su GitHub il workflow «Ordine SitoComodo» (repository_dispatch). Là Claude Code
// costruisce e controlla il sito, che va online sull'hosting OVH dei clienti; l'esito torna qui su /esito, protetto
// dal segreto condiviso. Se il dominio non è ancora pronto, WordPress richiede il collegamento ogni 15 minuti (max 12 ore).

function sc_fabbrica_segreto() {
    $s = (string) get_option('sc_fabbrica_segreto', '');
    if ($s === '') {
        $s = wp_generate_password(40, false, false);
        update_option('sc_fabbrica_segreto', $s, false);
    }
    return $s;
}

function sc_fabbrica_dati($o) {
    $file = [];
    foreach (($o['file'] ?? []) as $f) {
        if (!empty($o['cartella']) && !empty($f['file'])) {
            $file[] = ['campo' => $f['campo'] ?? '', 'file' => $f['file'], 'url' => sc_url_foto($o['cartella'], $f['file'])];
        }
    }
    $i = $o['intestatario'] ?? [];
    $d = ['sessione' => $o['pagamento']['sessione'] ?? '', 'prova' => false, 'dominio' => $o['dominio'] ?? ''];
    foreach (['nome', 'tipo', 'chi', 'servizi', 'telefono', 'whatsapp', 'email', 'indirizzo', 'orari', 'colori', 'social', 'google'] as $k) {
        $d[$k] = (string) ($o[$k] ?? '');
    }
    $d['modello'] = (string) ($o['modello'] ?? 'nostro');
    $d['carattere'] = (string) ($o['carattere'] ?? 'nostro');
    $d['piva'] = (string) ($i['piva'] ?? '');
    $d['ragione'] = (string) ($i['ragione'] ?? '');
    $d['citta_intestatario'] = (string) ($i['citta'] ?? '');
    $d['provincia_intestatario'] = (string) ($i['provincia'] ?? '');
    $d['dominio_acquisto'] = (string) ($o['dominio_acquisto']['stato'] ?? '');
    $d['offerta'] = ($o['offerta'] ?? '') === 'rifai' ? 'rifai' : 'nuovo';
    foreach (['hosting_attuale', 'fatto_con', 'dns_rilevati'] as $k) $d[$k] = (string) ($o[$k] ?? '');
    $d['file'] = $file;
    return $d;
}

function sc_fabbrica_ordine_di_prova() {
    return [
        'sessione' => 'prova_' . time(), 'prova' => true, 'dominio' => 'prova-fabbrica.it',
        'nome' => 'Fiori di Lu', 'tipo' => 'fiorista', 'chi' => '', 'servizi' => '', 'telefono' => '333 000 0000',
        'whatsapp' => '', 'email' => SC_DESTINATARIO, 'indirizzo' => '', 'orari' => '', 'colori' => '', 'social' => '',
        'google' => '', 'modello' => 'nostro', 'carattere' => 'nostro', 'piva' => '', 'ragione' => '',
        'citta_intestatario' => 'Lecce', 'provincia_intestatario' => 'LE', 'dominio_acquisto' => 'prova',
        'file' => [['campo' => 'logo', 'file' => 'logo.png', 'url' => home_url('/logo.png')]],
    ];
}

function sc_fabbrica_dispatch($evento, $ordine) {
    $token = (string) get_option('sc_gh_token', '');
    $repo = (string) get_option('sc_gh_repo', 'KubeSRL/sitocomodo-fabbrica');
    if ($token === '') return new WP_Error('token', 'manca la chiave GitHub');
    $r = wp_remote_post('https://api.github.com/repos/' . $repo . '/dispatches', [
        'timeout' => 20,
        'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/vnd.github+json',
                      'X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'SitoComodo', 'Content-Type' => 'application/json'],
        'body' => wp_json_encode(['event_type' => $evento, 'client_payload' => ['ordine' => $ordine]]),
    ]);
    if (is_wp_error($r)) return $r;
    $codice = (int) wp_remote_retrieve_response_code($r);
    if ($codice !== 204) return new WP_Error('github', 'GitHub ha risposto ' . $codice . ': ' . mb_substr((string) wp_remote_retrieve_body($r), 0, 300));
    return true;
}

function sc_fabbrica_trova($ordini, $sessione) {
    foreach ($ordini as $k => $x) if ($sessione !== '' && ($x['pagamento']['sessione'] ?? '') === $sessione) return $k;
    return null;
}

function sc_fabbrica_avvia($sessione, $evento = 'ordine-pagato', $forza = false) {
    if (!$forza && get_option('sc_fabbrica_attiva', '') !== '1') return new WP_Error('spenta', 'costruzione automatica spenta');
    $ordini = sc_ordini_leggi();
    $k = sc_fabbrica_trova($ordini, $sessione);
    if ($k === null) return new WP_Error('ordine', 'ordine non trovato');
    if ($evento === 'ordine-pagato' && !$forza && !empty($ordini[$k]['sito']['avviata'])) return true;
    $esito = sc_fabbrica_dispatch($evento, sc_fabbrica_dati($ordini[$k]));
    $s = $ordini[$k]['sito'] ?? [];
    $s['aggiornato'] = wp_date('d/m/Y H:i');
    if ($evento === 'ordine-pagato') {
        $s['avviata'] = $s['aggiornato'];
        $s['tentativi'] = 0;
        $s['stato'] = is_wp_error($esito) ? 'costruzione non partita' : 'in costruzione';
    } elseif (is_wp_error($esito)) {
        $s['stato'] = 'collegamento del dominio non partito';
    }
    if (is_wp_error($esito)) $s['note'] = [$esito->get_error_message()];
    $ordini[$k]['sito'] = $s;
    sc_ordini_scrivi($ordini);
    if (is_wp_error($esito)) {
        wp_mail(SC_DESTINATARIO, 'ATTENZIONE: sito NON in costruzione — ' . ($ordini[$k]['dominio'] ?? ''),
            "La costruzione automatica del sito non è partita.\n\nMotivo: " . $esito->get_error_message()
            . "\n\nDalla pagina «Ordini SitoComodo» puoi premere «Ricostruisci il sito».", ['Content-Type: text/plain; charset=UTF-8']);
    }
    return $esito;
}

add_action('sc_fabbrica_avvia_evento', function ($sessione) {   // «rifai»: niente dominio da comprare, si costruisce subito
    sc_fabbrica_avvia($sessione);
});

add_action('sc_fabbrica_ricollega', function ($sessione) {
    sc_fabbrica_avvia($sessione, 'collega-dominio', true);
});

// ---------------------------------------------------------------- «Rifai il tuo sito» (23/11/2026): guida per collegare il dominio
// Il dominio resta dal fornitore del cliente: lui (o Giuseppe con i suoi accessi) cambia solo il record A, www e aggiunge il TXT
// ovhcontrol che OVH chiede per i domini esterni. MX e il resto non si toccano: l'email del cliente continua a funzionare.
function sc_rifai_guida_testo($o, $dns) {
    $d = (string) ($o['dominio'] ?? '');
    $f = trim((string) ($o['hosting_attuale'] ?? ''));
    $dove = ($f === '' || $f === 'Non lo so' || $f === 'Altro') ? 'del tuo fornitore' : 'di ' . $f;
    $cf = stripos($f . ' ' . ($o['dns_rilevati'] ?? ''), 'cloudflare') !== false;
    $r = ["Nel pannello $dove cerca la sezione dei DNS (di solito si chiama «Gestione DNS», «Zona DNS» o «DNS e nameserver») e fai queste tre modifiche:", '',
          "1. Record A di $d (nome vuoto oppure @). Valore nuovo: " . $dns['ip'],
          !empty($dns['ipv6']) ? "   Se c'è anche un record AAAA per $d, cancellalo oppure metti come valore: " . $dns['ipv6']
                               : "   Se c'è anche un record AAAA per $d, cancellalo.",
          "2. Record CNAME con nome www che punta a: $d",
          "   Se per www c'è già un record A, cambialo con il valore " . $dns['ip'] . " (e cancella un eventuale AAAA per www).",
          '3. Record TXT nuovo, con nome ovhcontrol. Valore: ' . $dns['token']];
    if ($cf) $r[] = 'Su Cloudflare metti i record A e www su «Solo DNS» (la nuvola grigia).';
    $r[] = '';
    $r[] = 'Non toccare gli altri record, in particolare quelli MX: sono quelli della tua email, che continua a funzionare come prima.';
    return $r;
}

function sc_rifai_email_guida($o, $dns, $anteprima, $promemoria = false) {
    $a = sanitize_email((string) ($o['pagamento']['email_cliente'] ?? '')) ?: sanitize_email((string) ($o['email'] ?? ''));
    if (!$a) return false;
    $nome = trim((string) ($o['pagamento']['nome_cliente'] ?? ''));
    $d = (string) ($o['dominio'] ?? '');
    $attivita = (string) ($o['nome'] ?? '') !== '' ? $o['nome'] : 'la tua attività';
    $righe = array_merge([
        'Buongiorno' . ($nome !== '' ? ' ' . strtok($nome, ' ') : '') . ',', '',
        $promemoria ? "il sito nuovo di $attivita è pronto e aspetta solo di essere collegato al tuo dominio $d. Lo trovi sempre qui:"
                    : "il sito nuovo di $attivita è pronto. Puoi guardarlo qui, con calma:",
        $anteprima, '',
        "Quando ti piace, per metterlo sul tuo dominio $d servono tre impostazioni.", '',
    ], sc_rifai_guida_testo($o, $dns), [
        '',
        'Fatto questo, il sito nuovo compare sul tuo dominio di solito entro qualche ora, con il lucchetto https: ti scriviamo noi quando è online. Fino ad allora resta online il sito vecchio.',
        '',
        'Se non ti va o non ci riesci, rispondi a questa email: lo facciamo noi. Ci serve solo l\'accesso al pannello del tuo dominio.',
        'Se il sito nuovo non ti convince, puoi chiederci di rifarlo gratuitamente entro 48 ore.',
        '',
        'Il team di SitoComodo',
        SC_DESTINATARIO,
    ]);
    return wp_mail($a, ($promemoria ? 'Promemoria: ' : '') . 'Il tuo sito nuovo è pronto – ' . $d, implode("\n", $righe),
        ['Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . SC_DESTINATARIO]);
}

function sc_fabbrica_email_cliente($o, $url) {
    $a = sanitize_email((string) ($o['pagamento']['email_cliente'] ?? '')) ?: sanitize_email((string) ($o['email'] ?? ''));
    if (!$a) return false;
    $nome = trim((string) ($o['pagamento']['nome_cliente'] ?? ''));
    $righe = [
        'Buongiorno' . ($nome !== '' ? ' ' . strtok($nome, ' ') : '') . ',',
        '',
        'il sito di ' . ($o['nome'] ?? 'la tua attività') . ' è online ed è raggiungibile all\'indirizzo:',
        $url,
        '',
        'Ti consigliamo di controllarlo anche da smartphone. Se desideri modificare un testo, una foto o un orario, rispondi a questa email:',
        'le piccole modifiche sono gratuite nei primi 14 giorni dalla consegna. Se il sito non ti soddisfa, puoi chiederci di rifarlo gratuitamente entro 48 ore.',
        '',
        ...((($o['offerta'] ?? '') === 'rifai') ? ['Se paghi ancora un hosting solo per il sito vecchio, ora puoi disdirlo. Se nello stesso pacchetto hai anche dominio o email, non disdire: scrivici e ti diciamo cosa tenere.', ''] : []),
        'Grazie per aver scelto SitoComodo.',
        '',
        'Il team di SitoComodo',
        SC_DESTINATARIO,
    ];
    return wp_mail($a, 'Il tuo sito è online – ' . ($o['dominio'] ?? ''), implode("\n", $righe),
        ['Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . SC_DESTINATARIO]);
}

add_action('rest_api_init', function () {
    // la fabbrica su GitHub manda qui l'esito (solo con il segreto condiviso)
    register_rest_route('sitocomodo/v1', '/esito', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $r) {
            $segreto = (string) get_option('sc_fabbrica_segreto', '');
            if ($segreto === '' || !hash_equals($segreto, (string) $r->get_header('x_sc_segreto'))) {
                return new WP_REST_Response(['errore' => 'non autorizzato'], 401);
            }
            $d = $r->get_json_params();
            if (!is_array($d)) return new WP_REST_Response(['errore' => 'dati mancanti'], 400);
            $stati = ['pubblicato', 'dominio_in_attesa', 'prova_pubblicata', 'da_controllare', 'errore'];
            $stato = in_array(($d['stato'] ?? ''), $stati, true) ? $d['stato'] : 'errore';
            $testi = function ($v) { return array_slice(array_map(function ($x) { return mb_substr(sanitize_text_field((string) $x), 0, 400); }, is_array($v) ? $v : []), 0, 30); };
            $s = [
                'stato' => $stato, 'aggiornato' => wp_date('d/m/Y H:i'),
                'url_anteprima' => esc_url_raw((string) ($d['url_anteprima'] ?? '')), 'url_finale' => esc_url_raw((string) ($d['url_finale'] ?? '')),
                'note' => $testi($d['note'] ?? []), 'note_per_giuseppe' => $testi($d['note_per_giuseppe'] ?? []),
                'avvisi' => $testi($d['avvisi'] ?? []), 'run' => esc_url_raw((string) ($d['run'] ?? '')),
            ];
            $dominio = sanitize_text_field((string) ($d['dominio'] ?? ''));
            $righe = ['Stato: ' . $stato];
            if ($s['url_finale']) $righe[] = 'Sito: ' . $s['url_finale'];
            if ($s['url_anteprima']) $righe[] = 'Anteprima: ' . $s['url_anteprima'];
            foreach (['note_per_giuseppe' => 'Da ricontrollare', 'avvisi' => 'Avvisi del generatore', 'note' => 'Pubblicazione'] as $campo => $titolo) {
                if ($s[$campo]) { $righe[] = ''; $righe[] = $titolo . ':'; foreach ($s[$campo] as $x) $righe[] = '- ' . $x; }
            }
            if ($s['run']) { $righe[] = ''; $righe[] = 'Dettagli e provini: ' . $s['run']; }
            $intestazioni = ['Content-Type: text/plain; charset=UTF-8'];

            if (!empty($d['prova'])) {
                wp_mail(SC_DESTINATARIO, 'Prova della fabbrica: ' . $stato, implode("\n", $righe), $intestazioni);
                return ['ok' => true, 'prova' => true];
            }
            $ordini = sc_ordini_leggi();
            $k = sc_fabbrica_trova($ordini, sanitize_text_field((string) ($d['sessione'] ?? '')));
            if ($k === null) {
                wp_mail(SC_DESTINATARIO, 'ATTENZIONE: esito della fabbrica senza ordine — ' . $dominio, implode("\n", $righe), $intestazioni);
                return new WP_REST_Response(['errore' => 'ordine non trovato'], 404);
            }
            $prima = $ordini[$k]['sito'] ?? [];
            $rifai = ($ordini[$k]['offerta'] ?? '') === 'rifai';
            $dd = is_array($d['dns'] ?? null) ? $d['dns'] : [];
            $dns = ['ip' => (string) (filter_var($dd['ip'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ?: ''),
                    'ipv6' => (string) (filter_var($dd['ipv6'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ?: ''),
                    'token' => mb_substr(preg_replace('#[^A-Za-z0-9._/+=-]#', '', (string) ($dd['token'] ?? '')), 0, 100)]; // il token OVH è in base64: / + = vanno tenuti
            $s['dns'] = ($dns['ip'] && $dns['token']) ? $dns : ($prima['dns'] ?? []);
            $s['email_guida'] = $prima['email_guida'] ?? '';
            $s['promemoria'] = $prima['promemoria'] ?? '';
            $s['avviata'] = $prima['avviata'] ?? '';
            $s['tentativi'] = (int) ($prima['tentativi'] ?? 0);
            $s['email_cliente'] = $prima['email_cliente'] ?? '';
            if (!$s['url_anteprima']) $s['url_anteprima'] = $prima['url_anteprima'] ?? '';
            $sessione_k = $ordini[$k]['pagamento']['sessione'] ?? '';
            if ($stato === 'dominio_in_attesa' && $rifai) {
                // il dominio lo collega il cliente: guida subito, promemoria dopo 24 ore, Giuseppe avvisato dopo 2 giorni
                $s['tentativi']++;
                $n = $s['tentativi'];
                $guida_ok = !empty($s['dns']['ip']) && !empty($s['dns']['token']);
                $email_on = get_option('sc_email_cliente', '') === '1';
                if ($s['email_guida'] === '' || ($s['email_guida'] === 'mancano i dati' && $guida_ok)) {
                    if (!$guida_ok) {
                        $righe[] = ''; $righe[] = 'ATTENZIONE: mancano l\'indirizzo IP o il token ovhcontrol: la guida al cliente NON è partita.';
                        $s['email_guida'] = 'mancano i dati';
                    } elseif ($email_on && $s['url_anteprima']) {
                        $s['email_guida'] = sc_rifai_email_guida($ordini[$k], $s['dns'], $s['url_anteprima']) ? wp_date('d/m/Y H:i') : 'non partita';
                        $righe[] = ''; $righe[] = 'Guida per collegare il dominio mandata al cliente: ' . $s['email_guida'];
                    } else {
                        $righe[] = ''; $righe[] = 'Le email ai clienti sono spente: manda tu al cliente l\'anteprima (' . $s['url_anteprima'] . ') e questa guida:';
                        $righe = array_merge($righe, sc_rifai_guida_testo($ordini[$k], $s['dns']));
                        $s['email_guida'] = 'da mandare a mano';
                    }
                }
                if ($n === 48 && $s['promemoria'] === '' && $guida_ok && $email_on && $s['url_anteprima']) {
                    $s['promemoria'] = sc_rifai_email_guida($ordini[$k], $s['dns'], $s['url_anteprima'], true) ? wp_date('d/m/Y H:i') : 'non partito';
                }
                if ($n >= 96) {
                    $stato = 'dominio del cliente da collegare a mano';
                    $s['stato'] = $stato;
                    if ($n === 96) {
                        $righe[] = ''; $righe[] = 'Dopo 2 giorni il dominio del cliente non è ancora collegato: chiamalo. Se ti dà l\'accesso al pannello del suo dominio, fai tu queste modifiche:';
                        if ($guida_ok) $righe = array_merge($righe, sc_rifai_guida_testo($ordini[$k], $s['dns']));
                    }
                }
                if ($n < 96) {
                    wp_schedule_single_event(time() + 30 * MINUTE_IN_SECONDS, 'sc_fabbrica_ricollega', [$sessione_k]);
                } elseif ($n < 144) {
                    wp_schedule_single_event(time() + 6 * HOUR_IN_SECONDS, 'sc_fabbrica_ricollega', [$sessione_k]);
                } else {
                    $righe[] = ''; $righe[] = 'Dopo 14 giorni smetto di controllare: il dominio va collegato a mano, poi premi «Ricostruisci il sito».';
                    $stato = 'dominio da controllare a mano';
                    $s['stato'] = $stato;
                }
            } elseif ($stato === 'dominio_in_attesa') {
                $s['tentativi']++;
                if ($s['tentativi'] <= 48) {
                    wp_schedule_single_event(time() + 15 * MINUTE_IN_SECONDS, 'sc_fabbrica_ricollega', [$ordini[$k]['pagamento']['sessione']]);
                } elseif ($s['tentativi'] === 49) {
                    $righe[] = '';
                    $righe[] = 'Dopo 12 ore il dominio non è ancora collegato: smetto di riprovare, va guardato a mano.';
                    $stato = 'dominio da controllare a mano';
                    $s['stato'] = $stato;
                }
            }
            if ($stato === 'pubblicato' && $s['email_cliente'] === '' && get_option('sc_email_cliente', '') === '1') {
                $s['email_cliente'] = sc_fabbrica_email_cliente($ordini[$k], $s['url_finale']) ? wp_date('d/m/Y H:i') : 'non partita';
                $righe[] = '';
                $righe[] = 'Email al cliente: ' . $s['email_cliente'];
            }
            $ordini[$k]['sito'] = $s;
            sc_ordini_scrivi($ordini);
            if (($prima['stato'] ?? '') !== $s['stato']) {
                wp_mail(SC_DESTINATARIO, 'Sito ' . $dominio . ': ' . $s['stato'], implode("\n", $righe), $intestazioni);
            }
            return ['ok' => true];
        },
    ]);
});
