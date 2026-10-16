<?php

/*
 * Mountaineer IT - Microsoft 365 connection.
 *
 * ITFlow can send and receive mail through Microsoft 365, but needs an app registration made
 * by hand with a client secret pasted in. This creates that app in the tenant instead:
 *
 *  1. An admin signs in once with a code (device code flow, using Microsoft's own public
 *     "Microsoft Graph Command Line Tools" client - a self-hosted server has no app of its own
 *     to sign in with yet). That sign-in is used only to create the app and is then dropped.
 *  2. The app is created with this server's sign-in addresses, the delegated permissions for
 *     the mailbox (IMAP/SMTP) and each person's Outlook calendar, admin consent for the whole
 *     organisation, and a certificate (no client secret).
 *  3. ITFlow's own mail settings are pointed at it. The client secret field holds a marker
 *     (MIT_M365_CERT_SECRET) so ITFlow's checks pass; every token request to Microsoft goes
 *     through mitMicrosoftTokenFields(), which swaps the marker for a signed certificate
 *     assertion.
 *  4. Mail goes through Microsoft Graph rather than SMTP/IMAP (no "Authenticated SMTP" switch
 *     needed on the mailbox): cron/mail_queue.php hands each message to mitM365GraphSendMail(),
 *     and cron/ticket_email_parser.php reads the mailbox through mitM365GraphMailbox()
 *     (classes in functions/mit_m365_mailbox.php). Without the Mail permissions it stays on SMTP/IMAP.
 *  5. The certificate lasts a year and is replaced 30 days before it runs out - the app adds
 *     its new key itself (Graph addKey, which needs no extra permission).
 *
 * The app only gets delegated permissions: it can reach the mailbox that is connected and the
 * calendars of people who connect their own - never everyone's mail or calendars.
 *
 * Settings live in mit_settings (database update M003), prefixed m365_.
 */

defined('MIT_M365_LOGIN') || define('MIT_M365_LOGIN', 'https://login.microsoftonline.com');
defined('MIT_M365_GRAPH') || define('MIT_M365_GRAPH', 'https://graph.microsoft.com/v1.0');

/** Microsoft's public "Microsoft Graph Command Line Tools" client - used only for the one-time setup sign-in. */
const MIT_M365_BOOTSTRAP_CLIENT = '14d82eec-204b-4c2f-b7e8-296a70dab67e';
const MIT_M365_BOOTSTRAP_SCOPES = 'https://graph.microsoft.com/Application.ReadWrite.All https://graph.microsoft.com/DelegatedPermissionGrant.ReadWrite.All https://graph.microsoft.com/User.Read offline_access openid profile';

/** What the client secret field holds when the app signs in with its certificate. */
const MIT_M365_CERT_SECRET = 'mit-m365-certificate';

const MIT_M365_APP_NAME = 'Mountaineer IT ITFlow email and calendars';
const MIT_M365_GRAPH_APP_ID = '00000003-0000-0000-c000-000000000000';
const MIT_M365_EXCHANGE_APP_ID = '00000002-0000-0ff1-ce00-000000000000';

/** Delegated permissions the app is given and consented for, per resource. */
const MIT_M365_GRAPH_SCOPES = ['User.Read', 'Calendars.ReadWrite', 'Mail.ReadWrite', 'Mail.ReadWrite.Shared', 'Mail.Send', 'Mail.Send.Shared',
    'offline_access', 'openid', 'profile', 'email'];
const MIT_M365_EXCHANGE_SCOPES = ['IMAP.AccessAsUser.All', 'SMTP.Send'];

/** Bumped when the permissions above change - an app set up with fewer is offered "Update permissions". */
const MIT_M365_PERMISSIONS_VERSION = 2;

const MIT_M365_CERT_DAYS = 365;
const MIT_M365_RENEW_DAYS = 30;

/*
 * ---------- our settings (mit_settings) ----------
 */

function mitSetting($name, $default = null) {
    global $mysqli;
    $name = mysqli_real_escape_string($mysqli, (string) $name);
    $result = mysqli_query($mysqli, "SELECT setting_value FROM mit_settings WHERE setting_name = '$name' LIMIT 1");
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return $row ? $row['setting_value'] : $default;
}

function mitSetSetting($name, $value) {
    global $mysqli;
    $name = mysqli_real_escape_string($mysqli, (string) $name);
    $value = mysqli_real_escape_string($mysqli, (string) $value);
    mysqli_query($mysqli, "INSERT INTO mit_settings SET setting_name = '$name', setting_value = '$value' ON DUPLICATE KEY UPDATE setting_value = '$value'");
}

function mitDeleteSettings($prefix) {
    global $mysqli;
    $prefix = mysqli_real_escape_string($mysqli, addcslashes((string) $prefix, '%_\\'));
    mysqli_query($mysqli, "DELETE FROM mit_settings WHERE setting_name LIKE '$prefix%'");
}

/** The app as set up, or null: tenant_id, client_id, object_id, name, created_at, created_by, cert_expires, ... */
function mitM365App() {
    $client_id = mitSetting('m365_client_id');
    if (empty($client_id)) {
        return null;
    }
    $app = ['client_id' => $client_id];
    foreach (['tenant_id', 'object_id', 'name', 'created_at', 'created_by', 'cert_expires', 'cert_thumbprint', 'setup', 'renew_error', 'renewed_at', 'permissions_version'] as $key) {
        $app[$key] = mitSetting("m365_$key", '');
    }
    return $app;
}

/*
 * ---------- HTTP ----------
 */

/** A request to Microsoft. $body: array = form fields, string = JSON. Returns [status, json, body, error]. */
function mitHttp($method, $url, array $headers = [], $body = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    if (is_array($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body, '', '&'));
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    } elseif (is_string($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        if (!preg_grep('/^Content-Type:/i', $headers)) {
            $headers[] = 'Content-Type: application/json';
        }
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return ['status' => $status, 'json' => is_array($json) ? $json : [], 'body' => (string) $raw, 'error' => $error];
}

/** A Graph call with a bearer token. $body array is sent as JSON. */
function mitGraph($method, $path, $token, $body = null) {
    return mitHttp($method, MIT_M365_GRAPH . $path, ['Authorization: Bearer ' . $token, 'Accept: application/json'], $body === null ? null : json_encode($body));
}

/** Microsoft's own explanation of a failed call, for the screen. */
function mitM365Error(array $response, $what) {
    $json = $response['json'];
    $message = $json['error_description'] ?? ($json['error']['message'] ?? ($json['error'] ?? ''));
    if (is_array($message)) {
        $message = json_encode($message);
    }
    if ($message === '' && $response['error'] !== '') {
        $message = $response['error'];
    }
    return trim("$what failed" . ($response['status'] ? " (HTTP {$response['status']})" : '') . ($message !== '' ? ": $message" : ''));
}

/*
 * ---------- certificate and signed assertions ----------
 */

function mitBase64Url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** A new self-signed certificate and key: [cert_pem, key_pem, der_base64, thumbprint (x5t), expires 'Y-m-d H:i:s']. */
function mitM365NewCertificate() {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if (!$key) {
        throw new RuntimeException('Could not create a key: ' . openssl_error_string());
    }
    // A minimal OpenSSL config, so the system one's sample defaults (Some-State, Internet Widgits...) stay out of it
    $config_file = tempnam(sys_get_temp_dir(), 'mitcert');
    file_put_contents($config_file, "[req]\ndistinguished_name = dn\nprompt = no\n[dn]\nCN = ITFlow Mountaineer IT\n");
    $config = ['digest_alg' => 'sha256', 'config' => $config_file];
    $csr = openssl_csr_new(['commonName' => 'ITFlow Mountaineer IT', 'organizationName' => 'Mountaineer IT'], $key, $config);
    $serial = random_int(1, PHP_INT_MAX);
    $cert = $csr ? openssl_csr_sign($csr, null, $key, MIT_M365_CERT_DAYS, $config, $serial) : false;
    @unlink($config_file);
    if (!$cert) {
        throw new RuntimeException('Could not create a certificate: ' . openssl_error_string());
    }
    openssl_x509_export($cert, $cert_pem);
    openssl_pkey_export($key, $key_pem);
    $der = base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $cert_pem));
    $info = openssl_x509_parse($cert);
    return [
        'cert_pem' => $cert_pem,
        'key_pem' => $key_pem,
        'der_base64' => base64_encode($der),
        'thumbprint' => mitBase64Url(sha1($der, true)),
        'expires' => date('Y-m-d H:i:s', $info['validTo_time_t']),
    ];
}

/** A JWT signed (RS256) with the app's current certificate key. */
function mitM365SignedJwt(array $claims, $key_pem = null, $thumbprint = null) {
    $key_pem = $key_pem ?? mitSetting('m365_key_pem', '');
    $thumbprint = $thumbprint ?? mitSetting('m365_cert_thumbprint', '');
    $header = ['alg' => 'RS256', 'typ' => 'JWT', 'x5t' => $thumbprint];
    $input = mitBase64Url(json_encode($header)) . '.' . mitBase64Url(json_encode($claims));
    $key = openssl_pkey_get_private($key_pem);
    if (!$key || !openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Could not sign with the Microsoft 365 certificate.');
    }
    return $input . '.' . mitBase64Url($signature);
}

/** The client assertion that stands in for a client secret on a token request to $token_url. */
function mitM365ClientAssertion($token_url, $client_id) {
    $now = time();
    return mitM365SignedJwt([
        'aud' => $token_url,
        'iss' => $client_id,
        'sub' => $client_id,
        'jti' => bin2hex(random_bytes(16)),
        'nbf' => $now - 60,
        'iat' => $now - 60,
        'exp' => $now + 600,
    ]);
}

/**
 * Hook for every token request ITFlow makes to Microsoft (functions/request.php httpFormPost,
 * admin/oauth_microsoft_mail_callback.php, admin/post/settings_mail.php): when the client secret
 * is our marker and the client is our app, sign in with the certificate instead.
 */
function mitMicrosoftTokenFields($url, array $fields) {
    if (($fields['client_secret'] ?? null) !== MIT_M365_CERT_SECRET) {
        return $fields;
    }
    $login_host = parse_url(MIT_M365_LOGIN, PHP_URL_HOST);
    $client_id = (string) ($fields['client_id'] ?? '');
    if (parse_url((string) $url, PHP_URL_HOST) !== $login_host || $client_id === '' || $client_id !== (string) mitSetting('m365_client_id', '')) {
        return $fields;
    }

    // While here: replace the certificate if it is close to running out
    mitM365MaybeRenewCertificate();

    unset($fields['client_secret']);
    $fields['client_assertion_type'] = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
    $fields['client_assertion'] = mitM365ClientAssertion((string) $url, $client_id);
    return $fields;
}

/*
 * ---------- one-time setup: admin signs in with a code ----------
 */

/** Start the sign-in: [user_code, verification_uri, device_code, interval, expires_in] or ['error' => ...]. */
function mitM365DeviceCodeStart() {
    $response = mitHttp('POST', MIT_M365_LOGIN . '/organizations/oauth2/v2.0/devicecode', [], [
        'client_id' => MIT_M365_BOOTSTRAP_CLIENT,
        'scope' => MIT_M365_BOOTSTRAP_SCOPES,
    ]);
    if ($response['status'] !== 200 || empty($response['json']['device_code'])) {
        return ['error' => mitM365Error($response, 'Starting the Microsoft sign-in')];
    }
    return $response['json'];
}

/** Has the admin finished signing in? ['pending' => true], ['token' => ...] or ['error' => ..., 'blocked' => bool]. */
function mitM365DeviceCodePoll($device_code) {
    $response = mitHttp('POST', MIT_M365_LOGIN . '/organizations/oauth2/v2.0/token', [], [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        'client_id' => MIT_M365_BOOTSTRAP_CLIENT,
        'device_code' => $device_code,
    ]);
    $error = $response['json']['error'] ?? '';
    if ($error === 'authorization_pending' || $error === 'slow_down') {
        return ['pending' => true];
    }
    if (!empty($response['json']['access_token'])) {
        return ['token' => $response['json']['access_token']];
    }
    $description = (string) ($response['json']['error_description'] ?? '');
    // AADSTS53003: blocked by Conditional Access (Microsoft's "block device code flow" policy among others)
    $blocked = strpos($description, 'AADSTS53003') !== false || strpos($description, 'AADSTS50199') !== false;
    return ['error' => mitM365Error($response, 'Signing in'), 'blocked' => $blocked];
}

/** The tenant id from an access token. */
function mitM365TokenTenant($token) {
    $parts = explode('.', (string) $token);
    $claims = isset($parts[1]) ? json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
    return is_array($claims) ? (string) ($claims['tid'] ?? '') : '';
}

/** The addresses Microsoft may send people back to on this server. */
function mitM365RedirectUris() {
    global $config_base_url;
    $base = (defined('BASE_URL') && !empty(BASE_URL)) ? rtrim((string) BASE_URL, '/') : 'https://' . rtrim((string) $config_base_url, '/');
    return [
        $base . '/admin/oauth_microsoft_mail_callback.php',  // ITFlow's mailbox Connect
        $base . '/agent/user/mit_outlook_callback.php',      // each person's Outlook calendar (Phase 4)
    ];
}

/** A resource's service principal id and its delegated permission ids by name. */
function mitM365ResourceScopes($token, $app_id) {
    $response = mitGraph('GET', "/servicePrincipals?\$filter=" . rawurlencode("appId eq '$app_id'") . "&\$select=id,appId,oauth2PermissionScopes", $token);
    $sp = $response['json']['value'][0] ?? null;
    if ($response['status'] !== 200 || !$sp) {
        throw new RuntimeException(mitM365Error($response, "Looking up Microsoft's permissions"));
    }
    $scopes = [];
    foreach ($sp['oauth2PermissionScopes'] ?? [] as $scope) {
        $scopes[$scope['value']] = $scope['id'];
    }
    return ['id' => $sp['id'], 'scopes' => $scopes];
}

/**
 * Create the app in the tenant with the admin's setup token, and point ITFlow's mail settings
 * at it. Returns the app (mitM365App()) or throws with what went wrong.
 */
function mitM365Provision($token, $created_by) {
    global $mysqli;

    $tenant_id = mitM365TokenTenant($token);
    if ($tenant_id === '') {
        throw new RuntimeException('Could not tell which Microsoft 365 organisation that sign-in belongs to.');
    }

    [$access, $graph, $exchange] = mitM365RequiredAccess($token);

    $cert = mitM365NewCertificate();

    // The app itself
    $created = mitGraph('POST', '/applications', $token, [
        'displayName' => MIT_M365_APP_NAME,
        'signInAudience' => 'AzureADMyOrg',
        'notes' => 'Created by ITFlow (Mountaineer IT) for email and Outlook calendars. Signs in with a certificate that ITFlow replaces before it expires.',
        'web' => ['redirectUris' => mitM365RedirectUris()],
        'requiredResourceAccess' => $access,
        'keyCredentials' => [[
            'type' => 'AsymmetricX509Cert',
            'usage' => 'Verify',
            'key' => $cert['der_base64'],
            'displayName' => 'ITFlow certificate ' . date('Y-m-d'),
        ]],
    ]);
    if ($created['status'] !== 201 || empty($created['json']['appId'])) {
        throw new RuntimeException(mitM365Error($created, 'Creating the app'));
    }
    $client_id = $created['json']['appId'];
    $object_id = $created['json']['id'];

    // Its service principal - a newly created app can take a moment to be visible
    $sp = null;
    for ($attempt = 0; $attempt < 5 && !$sp; $attempt++) {
        if ($attempt) {
            sleep(3);
        }
        $response = mitGraph('POST', '/servicePrincipals', $token, ['appId' => $client_id]);
        if ($response['status'] === 201) {
            $sp = $response['json']['id'];
        }
    }
    if (!$sp) {
        throw new RuntimeException(mitM365Error($response, 'Creating the app\'s service principal'));
    }

    mitM365GrantConsent($token, $sp, $graph, $exchange);

    mitM365SaveApp($tenant_id, $client_id, $object_id, $cert, $created_by, 'automatic');
    mitSetSetting('m365_permissions_version', MIT_M365_PERMISSIONS_VERSION);
    return mitM365App();
}

/** The app's requiredResourceAccess for MIT_M365_*_SCOPES: [access, graph resource, exchange resource]. */
function mitM365RequiredAccess($token) {
    $graph = mitM365ResourceScopes($token, MIT_M365_GRAPH_APP_ID);
    $exchange = mitM365ResourceScopes($token, MIT_M365_EXCHANGE_APP_ID);
    $access = [];
    foreach ([[MIT_M365_GRAPH_APP_ID, $graph, MIT_M365_GRAPH_SCOPES], [MIT_M365_EXCHANGE_APP_ID, $exchange, MIT_M365_EXCHANGE_SCOPES]] as [$app_id, $resource, $wanted]) {
        $entries = [];
        foreach ($wanted as $scope) {
            if (empty($resource['scopes'][$scope])) {
                throw new RuntimeException("Microsoft did not list the $scope permission in this organisation.");
            }
            $entries[] = ['id' => $resource['scopes'][$scope], 'type' => 'Scope'];
        }
        $access[] = ['resourceAppId' => $app_id, 'resourceAccess' => $entries];
    }
    return [$access, $graph, $exchange];
}

/** Admin consent for the whole organisation, so nobody is asked to approve it - adds to or replaces earlier consent. */
function mitM365GrantConsent($token, $sp, array $graph, array $exchange) {
    foreach ([[$graph['id'], MIT_M365_GRAPH_SCOPES], [$exchange['id'], MIT_M365_EXCHANGE_SCOPES]] as [$resource_id, $scopes]) {
        $existing = mitGraph('GET', '/oauth2PermissionGrants?$filter=' . rawurlencode("clientId eq '$sp' and resourceId eq '$resource_id' and consentType eq 'AllPrincipals'"), $token);
        $grant_id = $existing['json']['value'][0]['id'] ?? '';
        if ($grant_id !== '') {
            $grant = mitGraph('PATCH', '/oauth2PermissionGrants/' . rawurlencode($grant_id), $token, ['scope' => implode(' ', $scopes)]);
            $ok = $grant['status'] === 204 || $grant['status'] === 200;
        } else {
            $grant = mitGraph('POST', '/oauth2PermissionGrants', $token, [
                'clientId' => $sp,
                'consentType' => 'AllPrincipals',
                'resourceId' => $resource_id,
                'scope' => implode(' ', $scopes),
            ]);
            $ok = $grant['status'] === 201;
        }
        if (!$ok) {
            throw new RuntimeException(mitM365Error($grant, 'Approving the app\'s permissions'));
        }
    }
}

/**
 * Bring an app set up earlier up to today's permissions (MIT_M365_PERMISSIONS_VERSION), with an
 * admin's sign-in token: the app's permission list and the organisation-wide consent.
 */
function mitM365UpdatePermissions($token) {
    $app = mitM365App();
    if (!$app || $app['object_id'] === '') {
        throw new RuntimeException('There is no Microsoft 365 app to update.');
    }
    if (mitM365TokenTenant($token) !== $app['tenant_id']) {
        throw new RuntimeException('That sign-in belongs to a different Microsoft 365 organisation than the app.');
    }
    [$access, $graph, $exchange] = mitM365RequiredAccess($token);

    $patched = mitGraph('PATCH', '/applications/' . rawurlencode($app['object_id']), $token, ['requiredResourceAccess' => $access]);
    if ($patched['status'] !== 204 && $patched['status'] !== 200) {
        throw new RuntimeException(mitM365Error($patched, 'Updating the app\'s permissions'));
    }
    $found = mitGraph('GET', '/servicePrincipals?$filter=' . rawurlencode("appId eq '{$app['client_id']}'") . '&$select=id', $token);
    $sp = $found['json']['value'][0]['id'] ?? '';
    if ($sp === '') {
        throw new RuntimeException(mitM365Error($found, 'Finding the app in your organisation'));
    }
    mitM365GrantConsent($token, $sp, $graph, $exchange);

    mitSetSetting('m365_permissions_version', MIT_M365_PERMISSIONS_VERSION);
    mitDeleteSettings('m365_mail_graph_');  // the next mail token picks up the new permissions
    return mitM365App();
}

/** Keep the app's details and point ITFlow's Microsoft mail settings at it. */
function mitM365SaveApp($tenant_id, $client_id, $object_id, array $cert, $created_by, $setup) {
    global $mysqli;
    mitSetSetting('m365_tenant_id', $tenant_id);
    mitSetSetting('m365_client_id', $client_id);
    mitSetSetting('m365_object_id', $object_id);
    mitSetSetting('m365_name', MIT_M365_APP_NAME);
    mitSetSetting('m365_created_at', date('Y-m-d H:i:s'));
    mitSetSetting('m365_created_by', $created_by);
    mitSetSetting('m365_setup', $setup);
    mitM365StoreCertificate($cert);

    $client_id_sql = mysqli_real_escape_string($mysqli, $client_id);
    $tenant_id_sql = mysqli_real_escape_string($mysqli, $tenant_id);
    $marker = mysqli_real_escape_string($mysqli, MIT_M365_CERT_SECRET);
    // A new app means a new mailbox connection - the old refresh token belonged to another app
    mysqli_query($mysqli, "UPDATE settings SET config_mail_oauth_client_id = '$client_id_sql', config_mail_oauth_tenant_id = '$tenant_id_sql',
        config_mail_oauth_client_secret = '$marker', config_mail_oauth_refresh_token = NULL, config_mail_oauth_access_token = NULL,
        config_mail_oauth_access_token_expires_at = NULL WHERE company_id = 1");
}

function mitM365StoreCertificate(array $cert) {
    mitSetSetting('m365_cert_pem', $cert['cert_pem']);
    mitSetSetting('m365_key_pem', $cert['key_pem']);
    mitSetSetting('m365_cert_thumbprint', $cert['thumbprint']);
    mitSetSetting('m365_cert_expires', $cert['expires']);
}

/*
 * ---------- manual setup (when the tenant blocks the code sign-in) ----------
 */

/** A certificate waiting to be uploaded to a hand-made app registration - made once, kept until used. */
function mitM365PendingCertificate() {
    $pending = json_decode((string) mitSetting('m365_pending_cert', ''), true);
    if (!is_array($pending) || empty($pending['cert_pem'])) {
        $pending = mitM365NewCertificate();
        mitSetSetting('m365_pending_cert', json_encode($pending));
    }
    return $pending;
}

/**
 * Use a hand-made app registration (its tenant, client and object ids) with the pending certificate.
 * The object id is needed to replace the certificate later, and the app may not look it up itself.
 */
function mitM365UseManualApp($tenant_id, $client_id, $object_id, $created_by) {
    $guid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    if (!preg_match($guid, $tenant_id) || !preg_match($guid, $client_id) || !preg_match($guid, $object_id)) {
        throw new RuntimeException('The tenant ID, application (client) ID and object ID are all GUIDs like 00000000-0000-0000-0000-000000000000.');
    }
    $cert = mitM365PendingCertificate();

    // Prove the certificate is on that app before switching ITFlow over to it
    $token_url = MIT_M365_LOGIN . '/' . rawurlencode($tenant_id) . '/oauth2/v2.0/token';
    $assertion = mitM365SignedJwt(['aud' => $token_url, 'iss' => $client_id, 'sub' => $client_id, 'jti' => bin2hex(random_bytes(16)),
        'nbf' => time() - 60, 'iat' => time() - 60, 'exp' => time() + 600], $cert['key_pem'], $cert['thumbprint']);
    $response = mitHttp('POST', $token_url, [], [
        'client_id' => $client_id, 'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials',
        'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer', 'client_assertion' => $assertion,
    ]);
    if (empty($response['json']['access_token'])) {
        throw new RuntimeException(mitM365Error($response, 'Signing in with the certificate on that app'));
    }
    mitM365SaveApp($tenant_id, $client_id, $object_id, $cert, $created_by, 'manual');
    mitSetSetting('m365_permissions_version', MIT_M365_PERMISSIONS_VERSION);
    mitDeleteSettings('m365_pending_cert');
    return mitM365App();
}

/*
 * ---------- certificate renewal ----------
 */

/** Replace the certificate when it has MIT_M365_RENEW_DAYS left - tried at most every six hours. */
function mitM365MaybeRenewCertificate() {
    $expires = mitSetting('m365_cert_expires', '');
    if ($expires === '' || strtotime($expires) - time() > MIT_M365_RENEW_DAYS * 86400) {
        return;
    }
    $last_try = mitSetting('m365_renew_tried_at', '');
    if ($last_try !== '' && time() - strtotime($last_try) < 6 * 3600) {
        return;
    }
    mitSetSetting('m365_renew_tried_at', date('Y-m-d H:i:s'));
    try {
        mitM365RenewCertificate();
    } catch (Throwable $e) {
        mitSetSetting('m365_renew_error', $e->getMessage());
        error_log('Mountaineer IT: Microsoft 365 certificate renewal failed: ' . $e->getMessage());
    }
}

/**
 * Give the app a new certificate. The app adds the key itself (Graph application addKey, which an
 * app may always do for its own keys, proven with a token signed by its current key).
 */
function mitM365RenewCertificate() {
    $app = mitM365App();
    if (!$app || $app['object_id'] === '') {
        throw new RuntimeException('There is no Microsoft 365 app to renew.');
    }
    $token_url = MIT_M365_LOGIN . '/' . rawurlencode($app['tenant_id']) . '/oauth2/v2.0/token';
    $response = mitHttp('POST', $token_url, [], [
        'client_id' => $app['client_id'], 'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials',
        'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        'client_assertion' => mitM365ClientAssertion($token_url, $app['client_id']),
    ]);
    if (empty($response['json']['access_token'])) {
        throw new RuntimeException(mitM365Error($response, 'Signing in to renew the certificate'));
    }

    $cert = mitM365NewCertificate();
    $proof = mitM365SignedJwt(['aud' => '00000002-0000-0000-c000-000000000000', 'iss' => $app['object_id'],
        'nbf' => time() - 60, 'exp' => time() + 600]);
    $added = mitGraph('POST', '/applications/' . rawurlencode($app['object_id']) . '/addKey', $response['json']['access_token'], [
        'keyCredential' => ['type' => 'AsymmetricX509Cert', 'usage' => 'Verify', 'key' => $cert['der_base64'], 'displayName' => 'ITFlow certificate ' . date('Y-m-d')],
        'passwordCredential' => null,
        'proof' => $proof,
    ]);
    if ($added['status'] !== 200) {
        throw new RuntimeException(mitM365Error($added, 'Adding the new certificate'));
    }
    mitM365StoreCertificate($cert);
    mitSetSetting('m365_renewed_at', date('Y-m-d H:i:s'));
    mitDeleteSettings('m365_renew_error');
}

/** Forget the app here, and stop ITFlow's mail using it. The app registration itself stays in the tenant. */
function mitM365Forget() {
    global $mysqli;
    $client_id = mysqli_real_escape_string($mysqli, (string) mitSetting('m365_client_id', ''));
    mysqli_query($mysqli, "UPDATE settings SET config_mail_oauth_client_secret = NULL, config_mail_oauth_refresh_token = NULL,
        config_mail_oauth_access_token = NULL, config_mail_oauth_access_token_expires_at = NULL
        WHERE company_id = 1 AND config_mail_oauth_client_id = '$client_id'");
    mitDeleteSettings('m365_');
}

/*
 * ---------- mail through Microsoft Graph ----------
 */

/**
 * A Graph token for the connected mailbox that carries $permission (Mail.Send, Mail.ReadWrite), or
 * null when ITFlow's mail is not on our app or the app does not have that permission yet - the
 * caller then stays on SMTP/IMAP. Throws when the mailbox is on our app but Microsoft refuses.
 */
function mitM365MailGraphToken($permission) {
    global $mysqli;
    $app = mitM365App();
    if (!$app) {
        return null;
    }
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_mail_oauth_client_id, config_mail_oauth_client_secret, config_mail_oauth_tenant_id,
        config_mail_oauth_refresh_token FROM settings WHERE company_id = 1"));
    if (!$row || $row['config_mail_oauth_client_id'] !== $app['client_id'] || $row['config_mail_oauth_client_secret'] !== MIT_M365_CERT_SECRET
        || empty($row['config_mail_oauth_refresh_token'])) {
        return null;
    }

    $token = mitSetting('m365_mail_graph_token', '');
    $expires = mitSetting('m365_mail_graph_expires', '');
    // A token for the refresh token on file - a newly connected mailbox gets a new one
    $for = sha1($row['config_mail_oauth_refresh_token']);
    if ($token === '' || $expires === '' || strtotime($expires) - 120 <= time() || mitSetting('m365_mail_graph_for', '') !== $for) {
        // The mailbox's refresh token is good for Graph as well as Exchange
        $token_url = MIT_M365_LOGIN . '/' . rawurlencode($row['config_mail_oauth_tenant_id']) . '/oauth2/v2.0/token';
        $response = mitHttp('POST', $token_url, [], mitMicrosoftTokenFields($token_url, [
            'client_id' => $app['client_id'],
            'client_secret' => MIT_M365_CERT_SECRET,
            'grant_type' => 'refresh_token',
            'refresh_token' => $row['config_mail_oauth_refresh_token'],
            'scope' => 'https://graph.microsoft.com/.default offline_access',
        ]));
        if (empty($response['json']['access_token'])) {
            throw new RuntimeException(mitM365Error($response, 'Signing in to Microsoft 365 as the mailbox'));
        }
        $token = $response['json']['access_token'];
        mitSetSetting('m365_mail_graph_token', $token);
        mitSetSetting('m365_mail_graph_expires', date('Y-m-d H:i:s', time() + intval($response['json']['expires_in'] ?? 3600)));
        mitSetSetting('m365_mail_graph_scopes', (string) ($response['json']['scope'] ?? ''));
        if (!empty($response['json']['refresh_token'])) {
            $for = sha1($response['json']['refresh_token']);
            $refresh = mysqli_real_escape_string($mysqli, $response['json']['refresh_token']);
            mysqli_query($mysqli, "UPDATE settings SET config_mail_oauth_refresh_token = '$refresh' WHERE company_id = 1");
        }
        mitSetSetting('m365_mail_graph_for', $for);
    }

    $scopes = mitM365MailGraphScopes();
    if (in_array('Mail.Send', $scopes, true) && in_array('Mail.ReadWrite', $scopes, true)) {
        // Permissions added by hand in Entra count as an update too
        mitSetSetting('m365_permissions_version', MIT_M365_PERMISSIONS_VERSION);
    }
    return in_array($permission, $scopes, true) ? $token : null;
}

/** The Graph permissions on the mailbox's last token, without the resource prefix. */
function mitM365MailGraphScopes() {
    $scopes = [];
    foreach (preg_split('/\s+/', trim((string) mitSetting('m365_mail_graph_scopes', ''))) as $scope) {
        if ($scope !== '') {
            $scopes[] = preg_replace('#^https://graph\.microsoft\.com/#i', '', $scope);
        }
    }
    return $scopes;
}

/**
 * Hook in cron/mail_queue.php: send a message PHPMailer has put together through Graph. False when
 * mail is not on our app (PHPMailer then sends by SMTP as usual); throws PHPMailer's exception on failure.
 */
function mitM365GraphSendMail($mail) {
    try {
        $token = mitM365MailGraphToken('Mail.Send');
    } catch (Throwable $e) {
        throw new PHPMailer\PHPMailer\Exception($e->getMessage());
    }
    if (!$token) {
        return false;
    }
    $mail->preSend();
    // Graph takes the finished message as base64 MIME - attachments and invites as built
    $response = mitHttp('POST', MIT_M365_GRAPH . '/me/sendMail', ['Authorization: Bearer ' . $token, 'Content-Type: text/plain'],
        base64_encode($mail->getSentMIMEMessage()));
    if ($response['status'] !== 202) {
        throw new PHPMailer\PHPMailer\Exception(mitM365Error($response, 'Sending through Microsoft 365'));
    }
    return true;
}

/**
 * Hook in cron/ticket_email_parser.php: the mailbox as an ImapEngine-like object read through Graph,
 * or null when it stays on IMAP. $username is the IMAP username - the signed-in mailbox or a shared one.
 */
function mitM365GraphMailbox($provider, $username) {
    if ($provider !== 'microsoft_oauth') {
        return null;
    }
    try {
        $token = mitM365MailGraphToken('Mail.ReadWrite');
    } catch (Throwable $e) {
        logApp("Cron-Email-Parser", "error", "Microsoft 365: " . $e->getMessage());
        cronJobStop('', 1);
    }
    if (!$token) {
        return null;
    }
    require_once __DIR__ . '/mit_m365_mailbox.php';
    return new MitGraphMailbox($token, (string) $username);
}
