<?php
/*
 * Mountaineer IT - the Microsoft 365 app card at the top of Admin > Settings > Mail
 * (functions/mit_m365.php, handlers in admin/post/mit_m365.php).
 */

$mit_m365_app = mitM365App();
$mit_m365_csrf = $_SESSION['csrf_token'];
$mit_m365_mailbox_connected = $mit_m365_app && !empty($config_mail_oauth_refresh_token)
    && ($config_mail_oauth_client_id ?? '') === $mit_m365_app['client_id'];
$mit_m365_mail_uses_ms = ($config_smtp_provider ?? '') === 'microsoft_oauth' || ($config_imap_provider ?? '') === 'microsoft_oauth';
?>

<div class="card mb-3" id="mitM365">
    <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title mb-0"><i class="fab fa-fw fa-microsoft me-2"></i>Microsoft 365</h3>
        <?php if ($mit_m365_app) { ?>
            <span class="badge rounded-pill <?= $mit_m365_mailbox_connected ? 'text-bg-success' : 'text-bg-warning' ?> ms-auto"><?= $mit_m365_mailbox_connected ? 'Ready' : 'Mailbox not connected' ?></span>
        <?php } ?>
    </div>
    <div class="card-body">

        <?php if ($mit_m365_app) {
            $mit_m365_expires = $mit_m365_app['cert_expires'] ? strtotime($mit_m365_app['cert_expires']) : 0; ?>

            <div class="border rounded p-3 d-flex flex-wrap gap-3 align-items-start mb-3">
                <i class="fab fa-microsoft fa-lg text-primary mt-1"></i>
                <div class="flex-grow-1" style="min-width: 16rem;">
                    ITFlow's own app <strong><?= escapeHtml($mit_m365_app['name']) ?></strong> in your Microsoft 365,
                    set up <?= escapeHtml(date('M j, Y', strtotime($mit_m365_app['created_at']))) ?><?= $mit_m365_app['created_by'] !== '' ? ' by ' . escapeHtml($mit_m365_app['created_by']) : '' ?><?= $mit_m365_app['setup'] === 'manual' ? ' (by hand)' : '' ?>.
                    It signs in as the mailbox you connect below, and staff can connect their own Outlook calendars to it.
                    <div class="small text-body-secondary mt-1">
                        Certificate expires <?= $mit_m365_expires ? escapeHtml(date('M j, Y', $mit_m365_expires)) : 'unknown' ?> and is replaced automatically <?= MIT_M365_RENEW_DAYS ?> days before.
                        Application (client) ID <code><?= escapeHtml($mit_m365_app['client_id']) ?></code>
                    </div>
                    <?php if ($mit_m365_app['renew_error'] !== '') { ?>
                        <div class="small text-danger mt-1"><i class="fas fa-exclamation-triangle me-1"></i>Last automatic renewal failed: <?= escapeHtml($mit_m365_app['renew_error']) ?></div>
                    <?php } ?>
                </div>
                <div class="d-flex gap-2">
                    <form action="post.php" method="post" class="m-0">
                        <input type="hidden" name="csrf_token" value="<?= $mit_m365_csrf ?>">
                        <button type="submit" name="mit_m365_renew" class="btn btn-sm btn-outline-secondary">Replace certificate</button>
                    </form>
                    <a href="post.php?mit_m365_remove=1&csrf_token=<?= $mit_m365_csrf ?>" class="btn btn-sm btn-outline-danger confirm-link"
                        data-confirm-title="Stop using this Microsoft 365 app?" data-confirm-button="Remove"
                        data-confirm-text="ITFlow stops sending and receiving through it, and Outlook calendars stop syncing. The app stays in your Microsoft 365 until you delete it in the Entra admin center.">Remove</a>
                </div>
            </div>

            <?php if ($mit_m365_mailbox_connected) { ?>
                <div class="text-success"><i class="fas fa-check-circle me-2"></i>Mailbox connected. To use a different mailbox, click Connect Microsoft 365 below again.</div>
            <?php } else { ?>
                <div class="alert alert-info mb-0">
                    <strong>Next: connect the mailbox ITFlow should use.</strong>
                    <?php if (!$mit_m365_mail_uses_ms) { ?>
                        On the <a href="?tab=sending">Sending</a> and/or <a href="?tab=receiving">Receiving</a> tab, set the provider to <em>Microsoft 365 (OAuth)</em> and save. Then
                    <?php } ?>
                    click <strong>Connect Microsoft 365</strong> on the <a href="?tab=oauth">OAuth</a> tab and sign in as that mailbox (for example service@).
                </div>
            <?php } ?>

        <?php } else { ?>

            <p class="mb-3">
                ITFlow can create its own app in your Microsoft 365 for email and Outlook calendars - with the permissions it needs, approved
                for your organisation, and a certificate it renews itself. You sign in once as a Global Administrator; nothing else to copy or paste.
            </p>
            <button type="button" class="btn btn-primary" id="mitM365Start"><i class="fab fa-microsoft me-2"></i>Set up automatically</button>
            <button type="button" class="btn btn-link" data-bs-toggle="collapse" data-bs-target="#mitM365Manual">Set up by hand instead</button>

            <div class="border rounded p-3 mt-3" id="mitM365Flow" hidden>
                <div class="js-step-code" hidden>
                    <div class="mb-2">
                        1. Go to <a class="js-verify fw-semibold" href="https://microsoft.com/devicelogin" target="_blank" rel="noopener">microsoft.com/devicelogin</a>
                        and enter this code:
                    </div>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <code class="fs-3 fw-bold js-code"></code>
                        <button type="button" class="btn btn-sm btn-outline-secondary js-copy"><i class="far fa-copy me-1"></i>Copy</button>
                    </div>
                    <div class="mb-2">2. Sign in as a <strong>Global Administrator</strong> and accept the permissions ("Microsoft Graph Command Line Tools").</div>
                    <div class="text-body-secondary"><span class="spinner-border spinner-border-sm me-2"></span><span class="js-status">Waiting for you to sign in...</span></div>
                </div>
                <div class="text-danger js-error" hidden></div>
            </div>

            <div class="collapse mt-3" id="mitM365Manual">
                <div class="border rounded p-3">
                    <p class="mb-2">If your organisation blocks the code sign-in, create the app by hand in the
                        <a href="https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank" rel="noopener">Entra admin center</a>:</p>
                    <ol class="mb-3">
                        <li><strong>App registrations &gt; New registration</strong>: name it <em><?= escapeHtml(MIT_M365_APP_NAME) ?></em>, single tenant,
                            Web redirect URI <code><?= escapeHtml(mitM365RedirectUris()[0]) ?></code>. Then under <strong>Authentication</strong> add a second Web redirect URI
                            <code><?= escapeHtml(mitM365RedirectUris()[1]) ?></code>.</li>
                        <li><strong>API permissions &gt; Add a permission</strong>, all <em>Delegated</em>:
                            Microsoft Graph <code><?= escapeHtml(implode(', ', MIT_M365_GRAPH_SCOPES)) ?></code>;
                            Office 365 Exchange Online (APIs my organization uses) <code><?= escapeHtml(implode(', ', MIT_M365_EXCHANGE_SCOPES)) ?></code>.
                            Then <strong>Grant admin consent</strong>.</li>
                        <li><strong>Certificates &amp; secrets &gt; Certificates &gt; Upload certificate</strong>:
                            <a href="post.php?mit_m365_certificate=1&csrf_token=<?= $mit_m365_csrf ?>">download ITFlow's certificate</a> and upload it.</li>
                        <li>Copy the <strong>Directory (tenant) ID</strong>, <strong>Application (client) ID</strong> and <strong>Object ID</strong> from the app's Overview here:</li>
                    </ol>
                    <form action="post.php" method="post" autocomplete="off" class="row g-2">
                        <input type="hidden" name="csrf_token" value="<?= $mit_m365_csrf ?>">
                        <div class="col-md-4"><input type="text" class="form-control" name="tenant_id" placeholder="Directory (tenant) ID" required></div>
                        <div class="col-md-4"><input type="text" class="form-control" name="client_id" placeholder="Application (client) ID" required></div>
                        <div class="col-md-4"><input type="text" class="form-control" name="object_id" placeholder="Object ID" required></div>
                        <div class="col-12"><button type="submit" name="mit_m365_manual" class="btn btn-primary">Use this app</button></div>
                    </form>
                </div>
            </div>

            <script>
            (function () {
                const start = document.getElementById('mitM365Start');
                const flow = document.getElementById('mitM365Flow');
                if (!start || !flow) {
                    return;
                }
                const csrf = <?= json_encode($mit_m365_csrf) ?>;
                const step = flow.querySelector('.js-step-code');
                const errorBox = flow.querySelector('.js-error');
                const statusText = flow.querySelector('.js-status');

                function post(action) {
                    const body = new URLSearchParams({csrf_token: csrf});
                    body.set(action, '1');
                    return fetch('post.php', {method: 'POST', credentials: 'same-origin', body: body})
                        .then(function (res) { return res.json(); });
                }
                function fail(message, blocked) {
                    step.hidden = true;
                    errorBox.hidden = false;
                    errorBox.textContent = message + (blocked ? ' Your organisation blocks this kind of sign-in - use "Set up by hand instead" below.' : '');
                    start.disabled = false;
                    if (blocked) {
                        document.getElementById('mitM365Manual').classList.add('show');
                    }
                }

                start.addEventListener('click', function () {
                    start.disabled = true;
                    flow.hidden = false;
                    errorBox.hidden = true;
                    post('mit_m365_start').then(function (json) {
                        if (!json.user_code) {
                            fail(json.error || 'Could not start the Microsoft sign-in.');
                            return;
                        }
                        step.hidden = false;
                        flow.querySelector('.js-code').textContent = json.user_code;
                        flow.querySelector('.js-verify').href = json.verification_uri;
                        flow.querySelector('.js-copy').onclick = function () {
                            navigator.clipboard && navigator.clipboard.writeText(json.user_code);
                        };
                        const poll = function () {
                            post('mit_m365_poll').then(function (result) {
                                if (result.pending) {
                                    setTimeout(poll, json.interval * 1000);
                                } else if (result.ok) {
                                    statusText.textContent = 'Done - reloading...';
                                    window.location.href = 'settings_mail.php?tab=oauth';
                                } else {
                                    fail(result.error || 'Setting up the app failed.', result.blocked);
                                }
                            }).catch(function () {
                                setTimeout(poll, json.interval * 1000);
                            });
                        };
                        setTimeout(function () {
                            statusText.textContent = 'Waiting for you to sign in... (once you have, creating the app takes up to a minute)';
                            poll();
                        }, json.interval * 1000);
                    }).catch(function () {
                        fail('Could not reach the server - check the connection and try again.');
                    });
                });
            })();
            </script>

        <?php } ?>
    </div>
</div>
