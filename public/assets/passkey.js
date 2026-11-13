/*
 * Passkeys for the curator console. Forms opt in with data attributes holding WebAuthn options
 * (JSON, issued by the server for this session):
 *   data-passkey-get     → asks for an existing passkey, result in input[name=passkey]
 *                          (skipped when input[name=recovery_code] is filled in)
 *   data-passkey-create  → creates a new passkey, result in input[name=attestation]
 * An element with data-passkey-status shows progress and errors.
 */
(() => {
  'use strict';

  const toBuffer = (value) => {
    let s = String(value).replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    const binary = atob(s);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return bytes.buffer;
  };
  const toBase64Url = (buffer) => {
    const bytes = new Uint8Array(buffer);
    let s = '';
    for (const b of bytes) s += String.fromCharCode(b);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  };
  const descriptors = (list) => (list || []).map((c) => ({ ...c, id: toBuffer(c.id) }));

  const creationOptions = (o) => (typeof PublicKeyCredential.parseCreationOptionsFromJSON === 'function'
    ? PublicKeyCredential.parseCreationOptionsFromJSON(o)
    : { ...o, challenge: toBuffer(o.challenge), user: { ...o.user, id: toBuffer(o.user.id) }, excludeCredentials: descriptors(o.excludeCredentials) });
  const requestOptions = (o) => (typeof PublicKeyCredential.parseRequestOptionsFromJSON === 'function'
    ? PublicKeyCredential.parseRequestOptionsFromJSON(o)
    : { ...o, challenge: toBuffer(o.challenge), allowCredentials: descriptors(o.allowCredentials) });

  const serialise = (credential) => {
    if (typeof credential.toJSON === 'function') {
      try { return credential.toJSON(); } catch (e) { /* fall through */ }
    }
    const r = credential.response;
    const json = {
      id: credential.id,
      rawId: toBase64Url(credential.rawId),
      type: credential.type,
      clientExtensionResults: credential.getClientExtensionResults ? credential.getClientExtensionResults() : {},
      response: { clientDataJSON: toBase64Url(r.clientDataJSON) },
    };
    if (credential.authenticatorAttachment) json.authenticatorAttachment = credential.authenticatorAttachment;
    if (r.attestationObject) {
      json.response.attestationObject = toBase64Url(r.attestationObject);
      json.response.transports = typeof r.getTransports === 'function' ? r.getTransports() : [];
    } else {
      json.response.authenticatorData = toBase64Url(r.authenticatorData);
      json.response.signature = toBase64Url(r.signature);
      if (r.userHandle) json.response.userHandle = toBase64Url(r.userHandle);
    }
    return json;
  };

  const explain = (error) => {
    switch (error && error.name) {
      case 'NotAllowedError': return 'The passkey prompt was cancelled or timed out. Please try again.';
      case 'InvalidStateError': return 'This device already has a passkey for your account.';
      case 'SecurityError': return 'Passkeys only work on the directory\'s own address over HTTPS.';
      default: return 'The passkey did not work: ' + (error && error.message ? error.message : String(error));
    }
  };

  document.querySelectorAll('form[data-passkey-get], form[data-passkey-create]').forEach((form) => {
    const status = form.querySelector('[data-passkey-status]');
    const say = (text) => { if (status) status.textContent = text; };

    form.addEventListener('submit', async (event) => {
      if (form.dataset.passkeyDone === '1') return;
      const recovery = form.querySelector('input[name="recovery_code"]');
      const useRecovery = recovery && recovery.value.trim() !== '';
      const needGet = form.dataset.passkeyGet && !useRecovery;
      const needCreate = !!form.dataset.passkeyCreate;
      if (!needGet && !needCreate) return;

      event.preventDefault();
      if (!window.PublicKeyCredential || !navigator.credentials) {
        say('This browser does not support passkeys.' + (recovery ? ' You can use a recovery code instead.' : ''));
        return;
      }
      const buttons = form.querySelectorAll('button[type="submit"]');
      buttons.forEach((b) => { b.disabled = true; });
      try {
        if (needGet) {
          say('Confirm with your passkey…');
          const credential = await navigator.credentials.get({ publicKey: requestOptions(JSON.parse(form.dataset.passkeyGet)) });
          form.querySelector('input[name="passkey"]').value = JSON.stringify(serialise(credential));
        }
        if (needCreate) {
          say('Create the new passkey on your device…');
          const credential = await navigator.credentials.create({ publicKey: creationOptions(JSON.parse(form.dataset.passkeyCreate)) });
          form.querySelector('input[name="attestation"]').value = JSON.stringify(serialise(credential));
        }
        say('');
        form.dataset.passkeyDone = '1';
        buttons.forEach((b) => { b.disabled = false; });
        if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
      } catch (error) {
        buttons.forEach((b) => { b.disabled = false; });
        say(explain(error));
      }
    });
  });
})();
