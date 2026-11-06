document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('api-tester-form');
    if (!form) return;

    const providerSelect = document.getElementById('tester-provider');
    const endpointInput = document.getElementById('tester-endpoint');
    const hintsContainer = document.getElementById('tester-hints');
    const sendBtn = document.getElementById('tester-send-btn');
    const resultCard = document.getElementById('tester-result-card');
    const statusBadge = document.getElementById('tester-status-badge');
    const durationSpan = document.getElementById('tester-duration');
    const headersPre = document.getElementById('tester-headers');
    const bodyPre = document.getElementById('tester-body');
    const runsTableBody = document.getElementById('tester-runs-body');

    const providerHints = window.API_TESTER_HINTS || {};

    function updateHints() {
        const p = providerSelect.value;
        const hints = providerHints[p] || [];
        if (!hintsContainer) return;

        if (hints.length === 0) {
            hintsContainer.innerHTML = '<span class="muted small">Tidak ada endpoint whitelist atau provider tidak mendukung.</span>';
            return;
        }

        let html = '<span class="muted small">Contoh endpoint yang diizinkan (klik untuk isi):</span><div class="pill-row wrap mt-1">';
        hints.forEach(pattern => {
            // Strip regex symbols to show clean example path
            const clean = pattern.replace(/^#\^?/, '').replace(/\$?#$/, '').replace(/\[\^\/\]\+/g, '123');
            html += `<button type="button" class="badge badge-soft hint-pill" data-path="${clean}">${clean}</button>`;
        });
        html += '</div>';
        hintsContainer.innerHTML = html;

        hintsContainer.querySelectorAll('.hint-pill').forEach(btn => {
            btn.addEventListener('click', () => {
                endpointInput.value = btn.getAttribute('data-path');
            });
        });
    }

    if (providerSelect) {
        providerSelect.addEventListener('change', updateHints);
        updateHints();
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const provider = providerSelect.value;
        const method = document.getElementById('tester-method').value;
        const endpoint = endpointInput.value.trim();
        const body = document.getElementById('tester-body-input').value;
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        sendBtn.disabled = true;
        sendBtn.textContent = 'Mengirim...';
        resultCard.style.display = 'none';

        try {
            const resp = await fetch('/admin/api-tester/send', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({
                    provider,
                    method,
                    endpoint,
                    body: body || null,
                }),
            });

            const data = await resp.json();

            resultCard.style.display = 'block';

            if (!resp.ok) {
                statusBadge.textContent = 'Status: ' + resp.status + ' Error';
                statusBadge.className = 'badge badge-danger';
                durationSpan.textContent = '-';
                headersPre.textContent = 'None';
                bodyPre.textContent = data.message || 'Error occurred';
            } else {
                const is2xx = data.status >= 200 && data.status < 300;
                statusBadge.textContent = 'HTTP ' + data.status;
                statusBadge.className = 'badge badge-' + (is2xx ? 'success' : (data.status >= 500 ? 'danger' : 'warning'));
                durationSpan.textContent = data.duration_ms + ' ms';

                headersPre.textContent = JSON.stringify(data.headers, null, 2);
                try {
                    const parsedJson = JSON.parse(data.body);
                    bodyPre.textContent = JSON.stringify(parsedJson, null, 2);
                } catch {
                    bodyPre.textContent = data.body || '(empty body)';
                }
            }

            // Refresh runs
            loadRuns();
        } catch (err) {
            resultCard.style.display = 'block';
            statusBadge.textContent = 'Network/Client Error';
            statusBadge.className = 'badge badge-danger';
            durationSpan.textContent = '-';
            headersPre.textContent = 'None';
            bodyPre.textContent = err.message;
        } finally {
            sendBtn.disabled = false;
            sendBtn.textContent = 'Send Request';
        }
    });

    async function loadRuns() {
        if (!runsTableBody) return;
        try {
            const resp = await fetch('/admin/api-tester/runs', {
                headers: { 'Accept': 'application/json' },
            });
            if (!resp.ok) return;
            const data = await resp.json();
            const runs = data.runs || [];

            if (runs.length === 0) {
                runsTableBody.innerHTML = '<tr><td colspan="7" class="muted">Belum ada riwayat API tester.</td></tr>';
                return;
            }

            runsTableBody.innerHTML = runs.map(r => {
                const statusBadgeClass = (r.response_status >= 200 && r.response_status < 300)
                    ? 'success'
                    : (r.response_status ? 'warning' : 'danger');
                const statusText = r.response_status ? r.response_status : (r.error ? 'ERR' : '-');
                const dateStr = new Date(r.created_at * 1000).toLocaleString('id-ID');
                const creator = r.creator_username || (r.created_by ? '#' + r.created_by : 'system');

                return `<tr>
                    <td class="mono small">${r.id}</td>
                    <td><strong>${escapeHtml(r.provider)}</strong></td>
                    <td><span class="badge badge-soft">${escapeHtml(r.method)}</span></td>
                    <td class="mono small break">${escapeHtml(r.endpoint)}</td>
                    <td><span class="badge badge-${statusBadgeClass}">${statusText}</span></td>
                    <td class="small">${r.duration_ms ? r.duration_ms + ' ms' : '-'}</td>
                    <td class="small">${escapeHtml(creator)}<br><span class="muted small">${dateStr}</span></td>
                </tr>`;
            }).join('');
        } catch (e) {
            // silent ignore
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
});
