/* Billas v4 — public/customer/admin interactions. No dependencies. */
(function () {
    'use strict';

    var meta = function (name, fallback) {
        var el = document.querySelector('meta[name="' + name + '"]');
        return el ? el.getAttribute('content') : (fallback || '');
    };

    var csrf = function () {
        return meta('csrf-token', '');
    };

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

    var errorBox = function (id) {
        return document.getElementById(id) || document.getElementById('wifi-error') || document.getElementById('buy-error');
    };

    var showError = function (message, id) {
        var box = errorBox(id);
        if (!box) {
            window.alert(message);
            return;
        }
        box.textContent = message;
        box.classList.remove('hidden');
        box.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    var hideError = function (id) {
        var box = errorBox(id);
        if (box) {
            box.textContent = '';
            box.classList.add('hidden');
        }
    };

    var postForm = function (endpoint, data) {
        var body = new FormData();
        Object.keys(data).forEach(function (key) {
            if (data[key] !== null && data[key] !== undefined) {
                body.append(key, data[key]);
            }
        });
        return fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrf()
            },
            body: body
        }).then(function (response) {
            return response.json().catch(function () {
                return { ok: false, error: 'Respons server tidak valid (' + response.status + ').' };
            }).then(function (json) {
                if (!response.ok || json.ok === false) {
                    throw new Error(json.error || 'Permintaan gagal (' + response.status + ').');
                }
                return json;
            });
        });
    };

    /* Buy hotspot voucher */
    document.querySelectorAll('[data-buy-package]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (button.disabled) {
                return;
            }
            var endpoint = button.getAttribute('data-endpoint');
            var packageId = button.getAttribute('data-buy-package');
            var original = button.textContent;
            hideError('buy-error');
            button.disabled = true;
            button.textContent = 'Menyiapkan pembayaran…';

            postForm(endpoint, { package_id: packageId }).then(function (data) {
                if (data.redirect_url) {
                    window.location.href = appUrl(data.redirect_url, endpoint);
                    return;
                }
                if (data.checkout_url) {
                    window.location.href = data.checkout_url;
                    return;
                }
                throw new Error('Gateway tidak mengembalikan halaman pembayaran.');
            }).catch(function (error) {
                button.disabled = false;
                button.textContent = original;
                showError(error.message, 'buy-error');
            });
        });
    });

    /* Countdowns */
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
    var tickCountdowns = function () {
        var now = Math.floor(Date.now() / 1000);
        countdowns.forEach(function (el) {
            var end = parseInt(el.getAttribute('data-countdown'), 10);
            if (!end) {
                return;
            }
            var seconds = end - now;
            el.textContent = seconds > 0 ? 'Berlaku ' + formatRemaining(seconds) : 'Kedaluwarsa';
            if (seconds <= 0) {
                el.classList.add('expired');
            }
        });
    };
    if (countdowns.length) {
        tickCountdowns();
        window.setInterval(tickCountdowns, 1000);
    }

    /* Polling for hotspot QRIS page */
    var pollBox = document.querySelector('[data-qris-poll]');
    if (pollBox) {
        var statusUrl = pollBox.getAttribute('data-status-url');
        var redirectUrl = pollBox.getAttribute('data-redirect-url');
        var expiresAt = parseInt(pollBox.getAttribute('data-expires-at'), 10) || 0;
        var message = document.querySelector('[data-poll-message]');
        var statusBadge = document.querySelector('[data-order-status]');
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
                    if (statusBadge) {
                        statusBadge.textContent = 'paid';
                    }
                    setMessage('Pembayaran diterima. Mengalihkan ke voucher…', true);
                    window.location.href = appUrl(redirectUrl || data.voucher_url, statusUrl);
                    return;
                }
                if (data.order && data.order.expired) {
                    stopped = true;
                    if (statusBadge) {
                        statusBadge.textContent = 'expired';
                    }
                    setMessage('Pembayaran kedaluwarsa. Silakan buat order baru.', false);
                    return;
                }
                setMessage('Menunggu pembayaran dikonfirmasi…', true);
            }).catch(function () {
                setMessage('Koneksi terputus. Mencoba lagi…', true);
            });
        };

        if (expiresAt && Math.floor(Date.now() / 1000) >= expiresAt) {
            setMessage('Pembayaran kedaluwarsa. Silakan buat order baru.', false);
        } else {
            check();
            window.setInterval(check, 4000);
        }
    }

    /* Copy buttons */
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
        if (el.closest('button')) {
            return;
        }
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

    /* Dismiss flash messages */
    document.querySelectorAll('[data-dismiss="alert"]').forEach(function (button) {
        button.addEventListener('click', function () {
            var alert = button.closest('.alert');
            if (alert) {
                alert.remove();
            }
        });
    });

    /* Admin mobile navigation */
    var navToggle = document.querySelector('[data-nav-toggle]');
    var sidebar = document.querySelector('.sidebar');
    if (navToggle && sidebar) {
        navToggle.addEventListener('click', function () {
            sidebar.classList.toggle('open');
        });
        document.addEventListener('click', function (event) {
            if (window.innerWidth <= 860 && sidebar.classList.contains('open') && !sidebar.contains(event.target) && !navToggle.contains(event.target)) {
                sidebar.classList.remove('open');
            }
        });
    }

    /* Admin gateway configuration probe */
    var probe = document.querySelector('[data-test-gateway]');
    if (probe) {
        var probeButton = probe.querySelector('[data-test-gateway-button]');
        var probeSelect = probe.querySelector('[data-test-gateway-name]');
        var probeResult = probe.querySelector('[data-test-gateway-result]');
        if (probeButton && probeSelect && probeResult) {
            probeButton.addEventListener('click', function () {
                probeButton.disabled = true;
                probeResult.className = 'muted small';
                probeResult.textContent = 'Memeriksa…';
                postForm(probe.getAttribute('data-test-gateway'), { gateway: probeSelect.value }).then(function (data) {
                    probeResult.textContent = data.selected_configured
                        ? data.selected.toUpperCase() + ' terkonfigurasi'
                        : data.selected.toUpperCase() + ' belum terkonfigurasi';
                    probeResult.className = 'small ' + (data.selected_configured ? 'ok' : 'bad');
                }).catch(function (error) {
                    probeResult.textContent = error.message;
                    probeResult.className = 'small bad';
                }).finally(function () {
                    probeButton.disabled = false;
                });
            });
        }
    }

    /* Auto-submit package type/duration helpers in admin */
    var packageType = document.querySelector('[data-package-type]');
    var packageForm = document.querySelector('[data-package-form]');
    if (packageType && packageForm) {
        var applyPackageHints = function () {
            var isPppoe = packageType.value === 'pppoe';
            packageForm.querySelectorAll('[name="available_on_captive"]').forEach(function (input) {
                input.disabled = isPppoe;
                if (isPppoe) {
                    input.checked = false;
                }
            });
        };
        packageType.addEventListener('change', applyPackageHints);
        applyPackageHints();
    }

    window.Billas = { appUrl: appUrl, postForm: postForm };
})();
