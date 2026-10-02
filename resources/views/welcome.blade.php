<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Protegey — Laravel example</title>
    <style>
        body { font-family: sans-serif; max-width: 640px; margin: 2rem auto; padding: 0 1rem; }
        pre { background: #f4f4f4; padding: 1rem; border-radius: 4px; white-space: pre-wrap; }
        button { margin-right: 0.5rem; }
    </style>
</head>
<body>
    <h1>Protegey — Laravel example</h1>
    <p>Demonstrates <code>protegey/sdk</code> from a Laravel backend: transaction reporting and starting a KYC session. See <code>app/Http/Controllers/ProtegeyDemoController.php</code>.</p>

    <div>
        <button id="report">Report a test transaction</button>
        <button id="kyc">Verify my identity</button>
    </div>

    <pre id="log">Click a button to call the backend.</pre>

    <script>
        const log = document.getElementById('log');
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        async function post(path) {
            const response = await fetch(path, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken } });
            return response.json();
        }

        document.getElementById('report').addEventListener('click', async () => {
            const result = await post('/protegey/report-transaction');
            log.textContent = `transactions->report() -> decision=${result.decision}, riskScore=${result.riskScore}`;
        });

        document.getElementById('kyc').addEventListener('click', async () => {
            const result = await post('/protegey/start-kyc');
            log.textContent = `kyc->startSession() -> ${result.url}`;
            window.open(result.url, '_blank', 'noopener,noreferrer');
        });
    </script>
</body>
</html>
