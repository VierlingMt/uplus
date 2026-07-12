// Unternehmen Plus – Passkeys / WebAuthn (Login per Fingerabdruck/Face-ID/PIN)
// Wird auf der Login-Seite (Anmelden) und im Profil (Einrichten/Verwalten) genutzt.
(function () {
  'use strict';

  function supported() {
    return !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.create);
  }

  // ---- base64url <-> ArrayBuffer -------------------------------------------
  function b64urlToBuf(s) {
    s = String(s).replace(/-/g, '+').replace(/_/g, '/');
    var pad = s.length % 4;
    if (pad) s += '===='.slice(pad);
    var bin = atob(s);
    var buf = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
    return buf.buffer;
  }
  function bufToB64url(buf) {
    var bytes = new Uint8Array(buf), bin = '';
    for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  // ---- JSON-Endpunkt (?r=passkey&action=…) ---------------------------------
  function api(endpoint, action, csrf, body) {
    var url = endpoint + (endpoint.indexOf('?') < 0 ? '?' : '&') + 'action=' + encodeURIComponent(action);
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'fetch' },
      body: body ? JSON.stringify(body) : '{}'
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Serverfehler (' + r.status + ')' }; })
        .then(function (j) {
          if (!r.ok || !j.ok) throw new Error(j && j.error ? j.error : 'Fehler');
          return j;
        });
    });
  }

  function setBusy(btn, busy, busyLabel) {
    if (!btn) return;
    if (busy) {
      btn.dataset._orig = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner"></span> ' + (busyLabel || 'Bitte bestätigen …');
    } else {
      btn.disabled = false;
      if (btn.dataset._orig != null) { btn.innerHTML = btn.dataset._orig; delete btn.dataset._orig; }
    }
  }

  function isCancel(err) {
    // Nutzer hat den Dialog abgebrochen o. Ä. – keine laute Fehlermeldung.
    return err && (err.name === 'NotAllowedError' || err.name === 'AbortError');
  }

  // ---- Registrierung (im Profil, angemeldet) -------------------------------
  function doRegister(btn) {
    var ep = btn.dataset.endpoint, csrf = btn.dataset.csrf;
    return api(ep, 'register_options', csrf).then(function (opt) {
      var pub = {
        challenge: b64urlToBuf(opt.challenge),
        rp: opt.rp,
        user: { id: b64urlToBuf(opt.user.id), name: opt.user.name, displayName: opt.user.displayName },
        pubKeyCredParams: opt.pubKeyCredParams,
        authenticatorSelection: opt.authenticatorSelection,
        timeout: opt.timeout,
        attestation: opt.attestation,
        excludeCredentials: (opt.excludeCredentials || []).map(function (c) {
          return { type: 'public-key', id: b64urlToBuf(c.id) };
        })
      };
      return navigator.credentials.create({ publicKey: pub });
    }).then(function (cred) {
      var resp = cred.response;
      return api(ep, 'register', csrf, {
        id: cred.id,
        rawId: bufToB64url(cred.rawId),
        type: cred.type,
        response: {
          clientDataJSON: bufToB64url(resp.clientDataJSON),
          attestationObject: bufToB64url(resp.attestationObject),
          transports: resp.getTransports ? resp.getTransports() : []
        }
      });
    });
  }

  // ---- Anmeldung (Login-Seite, öffentlich) ---------------------------------
  function doLogin(btn) {
    var ep = btn.dataset.endpoint, csrf = btn.dataset.csrf;
    return api(ep, 'login_options', csrf).then(function (opt) {
      var pub = {
        challenge: b64urlToBuf(opt.challenge),
        rpId: opt.rpId,
        userVerification: opt.userVerification,
        timeout: opt.timeout,
        allowCredentials: (opt.allowCredentials || []).map(function (c) {
          return { type: 'public-key', id: b64urlToBuf(c.id) };
        })
      };
      return navigator.credentials.get({ publicKey: pub });
    }).then(function (cred) {
      var resp = cred.response;
      return api(ep, 'login', csrf, {
        id: cred.id,
        rawId: bufToB64url(cred.rawId),
        type: cred.type,
        response: {
          clientDataJSON: bufToB64url(resp.clientDataJSON),
          authenticatorData: bufToB64url(resp.authenticatorData),
          signature: bufToB64url(resp.signature),
          userHandle: resp.userHandle ? bufToB64url(resp.userHandle) : null
        }
      });
    }).then(function (res) {
      if (res.redirect) location.href = res.redirect;
    });
  }

  function msg(btn, text, kind) {
    var box = document.querySelector('[data-passkey-msg]');
    if (!box) { if (kind === 'error') alert(text); return; }
    box.textContent = text;
    box.className = 'flash ' + (kind === 'error' ? 'error' : '');
    box.hidden = false;
  }

  function wire() {
    var blocks = document.querySelectorAll('[data-passkey-only]');
    // Nicht unterstützt -> Passkey-Bereiche ausgeblendet lassen (Fallback: Code-Login).
    if (!supported()) {
      blocks.forEach(function (el) { el.hidden = true; });
      return;
    }
    // Unterstützt -> Passkey-Bereiche einblenden.
    blocks.forEach(function (el) { el.hidden = false; });

    document.querySelectorAll('[data-passkey-register]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        setBusy(btn, true, 'Bitte bestätigen …');
        doRegister(btn)
          .then(function () { location.reload(); })
          .catch(function (err) {
            setBusy(btn, false);
            if (!isCancel(err)) msg(btn, 'Passkey konnte nicht eingerichtet werden: ' + err.message, 'error');
          });
      });
    });

    document.querySelectorAll('[data-passkey-login]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        setBusy(btn, true, 'Anmelden …');
        doLogin(btn)
          .catch(function (err) {
            setBusy(btn, false);
            if (!isCancel(err)) msg(btn, 'Anmeldung mit Passkey nicht möglich: ' + err.message, 'error');
          });
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wire);
  } else {
    wire();
  }
})();
