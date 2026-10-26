/* Billas v4 — captive portal RTRWNET: buy, poll, copy, countdown. */
(function () {
    'use strict';

    var appUrl = function (path, endpoint) {
        if (!path) {
            return window.location.href;
        }
        if (/^https?:\/\//i.test(path)) {
            return path;
        }
        try {
            var base = new URL(endpoint || window.location.href, window.location.href);
            var prefix = base.pathname.split('/api/')[0] || '';
            return base.origin + prefix + (path.charAt(0) === '/' ? path : '/' + path);
        } catch (e) {
            return path;
        }
    };

    var showError = function (message) {
        var box = document.getElementById('wifi-error');
        if (!box) {
            window.alert(message);
            return;
        }
        box.textContent = message;
        box.classList.remove('hidden');
        box.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    var setBusy = function (button, busy, label) {
        if (!button) {
            return;
        }
        if (!button.dataset.originalLabel) {
            button.dataset.originalLabel = button.textContent;
        }
        button.disabled = busy;
        button.textContent = busy ? label : button.dataset.originalLabel;
    };

    document.querySelectorAll('[data-wifi-buy]').forEach(function (button) {
        button.addEventListener('click', function () {
            var endpoint = button.getAttribute('data-endpoint');
            var packageId = button.getAttribute('data-wifi-buy');
            var token = button.getAttribute('data-session-token');
            var mac = button.getAttribute('data-client-mac') || '';
            var box = document.getElementById('wifi-error');
            if (box) {
                box.classList.add('hidden');
                box.textContent = '';
            }
            setBusy(button, true, 'Menyiapkan QRIS…');

            var body = new FormData();
            body.append('package_id', packageId);
            body.append('session_token', token);
            if (mac) {
                body.append('client_mac', mac);
            }

            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body
            }).then(function (response) {
                return response.json().catch(function () {
                    return { ok: false, error: 'Respons server tidak valid (' + response.status + ').' };
                }).then(function (json) {
                    if (!response.ok || json.ok === false) {
                        throw new Error(json.error || 'Gagal membuat pembayaran.');
                    }
                    return json;
                });
            }).then(function (data) {
                if (data.voucher_url) {
                    window.location.href = appUrl(data.voucher_url, endpoint);
                    return;
                }
                if (data.checkout_url) {
                    window.location.href = data.checkout_url;
                    return;
                }
                throw new Error('Gateway tidak mengembalikan halaman pembayaran.');
            }).catch(function (error) {
                setBusy(button, false, '');
                showError(error.message);
            });
        });
    });

    var formatRemaining = function (seconds) {
        if (seconds <= 0) {
            return 'Kedaluwarsa';
        }
        var days = Math.floor(seconds / 86400);
        var hours = Math.floor((seconds % 86400) / 3600);
        var minutes = Math.floor((seconds % 3600) / 60);
        var secs = seconds % 60;
        if (days > 0) {
            return days + ' hari ' + hours + ' jam';
        }
        return String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
    };

    var countdowns = Array.prototype.slice.call(document.querySelectorAll('[data-countdown]'));
    var tick = function () {
        var now = Math.floor(Date.now() / 1000);
        countdowns.forEach(function (el) {
            var end = parseInt(el.getAttribute('data-countdown'), 10);
            if (!end) {
                return;
            }
            var seconds = end - now;
            el.textContent = seconds > 0 ? formatRemaining(seconds) : 'Kedaluwarsa';
        });
    };
    if (countdowns.length) {
        tick();
        window.setInterval(tick, 1000);
    }

    /* Poll the captive order and redirect when the voucher is ready. */
    var qr = document.querySelector('[data-wifi-qris]');
    if (qr) {
        var statusUrl = qr.getAttribute('data-status-url');
        var voucherUrl = qr.getAttribute('data-voucher-url');
        var expiresAt = parseInt(qr.getAttribute('data-expires-at'), 10) || 0;
        var message = document.querySelector('[data-wifi-message]');
        var stopped = false;

        var setMessage = function (text, busy) {
            if (!message) {
                return;
            }
            message.innerHTML = '';
            if (busy) {
                var spinner = document.createElement('span');
                spinner.className = 'spinner';
                spinner.setAttribute('aria-hidden', 'true');
                message.appendChild(spinner);
            }
            message.appendChild(document.createTextNode(text));
        };

        var check = function () {
            if (stopped) {
                return;
            }
            fetch(statusUrl, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                return response.json();
            }).then(function (data) {
                if (data.paid || data.voucher_ready) {
                    stopped = true;
                    setMessage('Pembayaran diterima. Menyiapkan password WiFi…', true);
                    window.location.href = appUrl(voucherUrl || data.voucher_url, statusUrl);
                    return;
                }
                if (data.order && data.order.expired) {
                    stopped = true;
                    setMessage('Pembayaran kedaluwarsa. Silakan pilih paket lagi.', false);
                    return;
                }
                setMessage('Menunggu pembayaran QRIS…', true);
            }).catch(function () {
                setMessage('Koneksi terputus. Mencoba lagi…', true);
            });
        };

        if (expiresAt && Math.floor(Date.now() / 1000) >= expiresAt) {
            setMessage('Pembayaran kedaluwarsa. Silakan pilih paket lagi.', false);
        } else {
            check();
            window.setInterval(check, 4000);
        }
    }

    var copyText = function (text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var area = document.createElement('textarea');
            area.value = text;
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.focus();
            area.select();
            try {
                document.execCommand('copy') ? resolve() : reject(new Error('copy failed'));
            } catch (error) {
                reject(error);
            } finally {
                document.body.removeChild(area);
            }
        });
    };

    document.querySelectorAll('[data-copy-button]').forEach(function (button) {
        button.addEventListener('click', function () {
            var text = button.getAttribute('data-copy-button');
            var original = button.textContent;
            copyText(text).then(function () {
                button.textContent = 'Tersalin ✓';
                window.setTimeout(function () { button.textContent = original; }, 1800);
            }).catch(function () {
                button.textContent = 'Gagal menyalin';
                window.setTimeout(function () { button.textContent = original; }, 1800);
            });
        });
    });

    document.querySelectorAll('[data-copy]').forEach(function (el) {
        el.style.cursor = 'copy';
        el.title = 'Klik untuk menyalin';
        el.addEventListener('click', function () {
            copyText(el.getAttribute('data-copy')).then(function () {
                var old = el.textContent;
                el.textContent = 'Tersalin ✓';
                window.setTimeout(function () { el.textContent = old; }, 1200);
            });
        });
    });

    document.querySelectorAll('[data-dismiss="alert"]').forEach(function (button) {
        button.addEventListener('click', function () {
            var alert = button.closest('.alert');
            if (alert) {
                alert.remove();
            }
        });
    });
})();
