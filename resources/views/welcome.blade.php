<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Protegey — Laravel example</title>
    <style>
        body { font-family: sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; }
        pre { background: #f4f4f4; padding: 1rem; border-radius: 4px; white-space: pre-wrap; max-height: 24rem; overflow: auto; }
        button { margin: 0 0.5rem 0.5rem 0; }
        fieldset { margin-bottom: 1rem; }
        input { margin-right: 0.5rem; }
    </style>
</head>
<body>
    <h1>Protegey — Laravel example</h1>
    <p>Demonstrates <code>protegey/sdk</code> from a Laravel backend, entirely server-to-server. See <code>app/Http/Controllers/ProtegeyDemoController.php</code>.</p>

    <fieldset>
        <legend>Transactions &amp; alerts</legend>
        <button data-post="/protegey/report-transaction">Report a test transaction</button>
        <button data-get="/protegey/transactions">My transactions</button>
        <button data-get="/protegey/alerts">Open alerts</button>
    </fieldset>

    <fieldset>
        <legend>Cash deposits</legend>
        <button data-post="/protegey/report-cash-deposit">Record a cash deposit</button>
        <button data-get="/protegey/cash-deposits">My cash deposits</button>
    </fieldset>

    <fieldset>
        <legend>KYC</legend>
        <button id="kyc">Verify my identity</button>
        <button data-get="/protegey/kyc-sessions">My KYC sessions (recommendation + photos)</button>
        <form id="kyc-lookup">
            <input name="externalUserId" placeholder="externalUserId (exact)" required>
            <button>Look up</button>
        </form>
    </fieldset>

    <fieldset>
        <legend>KYB invite (email and/or phone)</legend>
        <form id="kyb">
            <input name="contactName" placeholder="Contact name" required>
            <input name="contactEmail" type="email" placeholder="Email">
            <input name="contactPhone" placeholder="Phone">
            <button>Invite</button>
        </form>
    </fieldset>

    <fieldset>
        <legend>Screening</legend>
        <form id="sanctions">
            <input name="name" placeholder="Name to screen" required>
            <button>Sanctions screen</button>
        </form>
        <form id="shared-signal">
            <input name="phoneNumber" placeholder="+221771234567" required>
            <button>Shared signal check</button>
        </form>
    </fieldset>

    <pre id="log">Click a button to call the backend.</pre>

    <script>
        const log = document.getElementById('log');
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        async function call(method, path, body) {
            const response = await fetch(path, {
                method,
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: body ? JSON.stringify(body) : undefined,
            });
            const json = await response.json();
            log.textContent = `${method} ${path} -> ${response.status}\n` + JSON.stringify(json, null, 2);
            return json;
        }

        document.querySelectorAll('[data-post]').forEach((b) => b.addEventListener('click', () => call('POST', b.dataset.post)));
        document.querySelectorAll('[data-get]').forEach((b) => b.addEventListener('click', () => call('GET', b.dataset.get)));

        document.getElementById('kyc').addEventListener('click', async () => {
            const result = await call('POST', '/protegey/start-kyc');
            if (result.url) window.open(result.url, '_blank', 'noopener,noreferrer');
        });

        document.getElementById('kyc-lookup').addEventListener('submit', (e) => {
            e.preventDefault();
            const id = new FormData(e.target).get('externalUserId');
            call('GET', '/protegey/kyc-sessions?externalUserId=' + encodeURIComponent(id));
        });

        const forms = { kyb: '/protegey/kyb-invite', sanctions: '/protegey/sanctions-screen', 'shared-signal': '/protegey/shared-signal-check' };
        Object.entries(forms).forEach(([id, path]) => document.getElementById(id).addEventListener('submit', (e) => {
            e.preventDefault();
            const body = Object.fromEntries([...new FormData(e.target)].filter(([, v]) => v !== ''));
            call('POST', path, body);
        }));
    </script>
</body>
</html>
