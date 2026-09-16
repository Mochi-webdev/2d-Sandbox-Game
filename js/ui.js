
function showMenuSection(id) {
    document.querySelectorAll('.menu-card').forEach(el => { el.style.display = 'none'; });
    const target = document.getElementById(id);
    if (target) target.style.display = 'flex';
    if (id === 'mp-menu') { renderServerList(); renderHostWorldOptions(); renderRehostList(); startServerBrowserListener(); }
    else stopServerBrowserListener();
    if (id === 'sp-menu') { renderAccountWorldsList(); }
}

function loadWorldSnapshot(data) {
    try {
        if (!data.world || data.world.length !== WORLD_WIDTH * WORLD_HEIGHT) throw new Error('Bad dimensions');
        applyWorldSnapshot(data);
        notify('World Loaded!');
        closeWorldsModal();
        startEngineUI();
    } catch (err) { notify('Could not load that world.'); }
}

async function saveCurrentWorldToAccount(existingId) {
    if (document.getElementById('ui-layer').style.display === 'none') { notify('Start a world first!'); return; }
    if (!requireOnline()) return;
    const nameInput = document.getElementById('world-save-name');
    const name = (nameInput && nameInput.value.trim()) || ('World ' + new Date().toLocaleString());
    const snapshot = getWorldSnapshot();
    const ts = Date.now();
    try {
        if (existingId) {
            await worldsDataColRef().doc(existingId).set(snapshot);
            await worldsMetaColRef().doc(existingId).set({ name, ts }, { merge: true });
            const meta = worldsMetaCache.find(w => w.id === existingId);
            if (meta) { meta.name = name; meta.ts = ts; }
            activeCloudWorldId = existingId;
        } else {
            const metaRef = await worldsMetaColRef().add({ name, ts });
            await worldsDataColRef().doc(metaRef.id).set(snapshot);
            worldsMetaCache.unshift({ id: metaRef.id, name, ts });
            activeCloudWorldId = metaRef.id;
        }
        if (nameInput) nameInput.value = '';
        notify('World saved to your account!');
        renderWorldsModalList();
        renderAccountWorldsList();
    } catch (e) { notify('Could not save world: ' + e.message); }
}

async function loadWorldFromAccount(worldId) {
    if (!requireOnline()) return;
    try {
        const snap = await worldsDataColRef().doc(worldId).get();
        if (!snap.exists) return notify('World data not found.');
        activeCloudWorldId = worldId;
        loadWorldSnapshot(snap.data());
    } catch (e) { notify('Could not load world: ' + e.message); }
}

async function deleteAccountWorld(worldId) {
    try {
        await worldsMetaColRef().doc(worldId).delete();
        await worldsDataColRef().doc(worldId).delete();
        worldsMetaCache = worldsMetaCache.filter(w => w.id !== worldId);
        if (activeCloudWorldId === worldId) activeCloudWorldId = null;
        renderWorldsModalList(); renderAccountWorldsList();
    } catch (e) { notify('Could not delete: ' + e.message); }
}

function openWorldsModal() {
    renderWorldsModalList();
    document.getElementById('world-save-current-btn').style.display = activeCloudWorldId ? 'inline-flex' : 'none';
    document.getElementById('worlds-modal').style.display = 'flex';
    document.getElementById('worlds-backdrop').style.display = 'block';
}

function closeWorldsModal() {
    const modal = document.getElementById('worlds-modal');
    if (modal) modal.style.display = 'none';
    const backdrop = document.getElementById('worlds-backdrop');
    if (backdrop) backdrop.style.display = 'none';
}

function renderWorldsModalList() {
    const el = document.getElementById('worlds-modal-list');
    if (!el) return;
    el.innerHTML = '';
    if (!firebaseReady) { el.innerHTML = '<div style="font-size:7px;color:#888;">Cloud saves need Firebase configured.</div>'; return; }
    if (worldsMetaCache.length === 0) { el.innerHTML = '<div style="font-size:7px;color:#888;">No saved worlds yet.</div>'; return; }
    worldsMetaCache.forEach(w => {
        const row = document.createElement('div'); row.className = 'saved-list-row';
        const label = document.createElement('div'); label.className = 'name';
        label.innerText = w.name + (w.id === activeCloudWorldId ? ' (current)' : '') + ' - ' + new Date(w.ts).toLocaleDateString();
        row.appendChild(label);
        const loadBtn = document.createElement('button'); loadBtn.className = 'pixel-btn small'; loadBtn.innerText = 'Load';
        loadBtn.onclick = () => { if (confirm('Load "' + w.name + '"? Unsaved progress will be lost.')) loadWorldFromAccount(w.id); };
        row.appendChild(loadBtn);
        const delBtn = document.createElement('button'); delBtn.className = 'pixel-btn danger small'; delBtn.innerText = 'X';
        delBtn.onclick = () => deleteAccountWorld(w.id);
        row.appendChild(delBtn);
        el.appendChild(row);
    });
}

function renderAccountWorldsList() {
    const el = document.getElementById('account-worlds-list');
    if (!el) return;
    el.innerHTML = '';
    if (!firebaseReady) { el.innerHTML = '<div style="font-size:7px;color:#888;">Cloud saves need Firebase configured.</div>'; return; }
    if (worldsMetaCache.length === 0) { el.innerHTML = '<div style="font-size:7px;color:#888;">No saved worlds yet - play, then use "Worlds" in-game.</div>'; return; }
    worldsMetaCache.forEach(w => {
        const row = document.createElement('div'); row.className = 'saved-list-row';
        const label = document.createElement('div'); label.className = 'name';
        label.innerText = w.name + ' - ' + new Date(w.ts).toLocaleDateString();
        row.appendChild(label);
        const loadBtn = document.createElement('button'); loadBtn.className = 'pixel-btn small'; loadBtn.innerText = 'Play';
        loadBtn.onclick = () => loadWorldFromAccount(w.id);
        row.appendChild(loadBtn);
        const delBtn = document.createElement('button'); delBtn.className = 'pixel-btn danger small'; delBtn.innerText = 'X';
        delBtn.onclick = () => deleteAccountWorld(w.id);
        row.appendChild(delBtn);
        el.appendChild(row);
    });
}

function renderHostWorldOptions() {
    const sel = document.getElementById('host-world-select');
    if (!sel) return;
    sel.innerHTML = '<option value="__new__">New World</option>';
    worldsMetaCache.forEach(w => {
        const opt = document.createElement('option');
        opt.value = w.id; opt.innerText = w.name;
        sel.appendChild(opt);
    });
}

function renderRehostList() {
    const el = document.getElementById('rehost-list');
    if (!el) return;
    el.innerHTML = '';
    if (!firebaseReady) {
        el.innerHTML = '<div style="font-size:7px;color:#888;">Login to rehost saved worlds.</div>';
        return;
    }
    if (worldsMetaCache.length === 0) {
        el.innerHTML = '<div style="font-size:7px;color:#888;">No saved worlds yet. Save a world in-game first.</div>';
        return;
    }
    worldsMetaCache.forEach(w => {
        const row = document.createElement('div');
        row.className = 'saved-list-row';
        const label = document.createElement('div');
        label.className = 'name';
        label.innerText = w.name + ' - ' + new Date(w.ts).toLocaleDateString();
        row.appendChild(label);
        const btn = document.createElement('button');
        btn.className = 'pixel-btn small';
        btn.innerText = 'Rehost';
        btn.onclick = () => {
            const sel = document.getElementById('host-world-select');
            if (sel) sel.value = w.id;
            hostMultiplayerRoom();
        };
        row.appendChild(btn);
        el.appendChild(row);
    });
}

function startSingleplayer() {
    const seedVal = document.getElementById('world-seed').value;
    gameMode = document.getElementById('game-mode').value;
    document.getElementById('mode-display').innerText = gameMode.toUpperCase();
    activeCloudWorldId = null;
    resetHotbar();
    generateContinuousCaves(seedVal ? hashString(seedVal) : Math.random() * 10000);
    startEngineUI();
    notify("Punch trees for Wood! Use C for basic craft, then place & click a Crafting Bench.");
}

function startEngineUI() {
    document.getElementById('menu-overlay').style.display = 'none';
    document.getElementById('ui-layer').style.display = 'flex';
    document.getElementById('mode-display').innerText = isMultiplayer ? ('MULTIPLAYER - ' + gameMode.toUpperCase()) : gameMode.toUpperCase();
    document.getElementById('player-count-display').style.display = isMultiplayer ? 'inline-flex' : 'none';

    // Ensure hotbar + health bar render after the UI layer is visible
    setTimeout(() => {
        updateUI();
        updateHealthUI();
        renderInventoryModal();
    }, 50);

    lastFrameTime = performance.now();
    requestAnimationFrame(gameLoop);
}

function leaveToMenu() {
    if (isHost) cleanupHost();
    else if (isMultiplayer) cleanupClient();
    isMultiplayer = false; isHost = false;
    otherPlayers.clear();
    document.getElementById('ui-layer').style.display = 'none';
    document.getElementById('menu-overlay').style.display = 'flex';
    showMenuSection('main-menu');
}

function downloadWorldJSON() {
    const blob = new Blob([JSON.stringify(getWorldSnapshot())], { type: 'application/json' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'pixel_world.json';
    a.click();
}

function loadWorldFromFile(e) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function (evt) {
        try { const data = JSON.parse(evt.target.result); activeCloudWorldId = null; loadWorldSnapshot(data); }
        catch (err) { notify("Invalid World File!"); }
    };
    reader.readAsText(file);
}

function updateHealthUI() {
    const fill = document.getElementById('health-bar-fill');
    if (fill) fill.style.width = Math.max(0, Math.min(100, player.health)) + '%';
}

function updatePlayerCountDisplay() {
    const el = document.getElementById('player-count-display');
    if (el) el.innerText = 'PLAYERS: ' + (otherPlayers.size + 1);
}

async function saveCurrentServerCode() {
    if (!requireOnline()) return;
    const code = document.getElementById('join-room-code').value.trim().toUpperCase();
    const name = document.getElementById('server-save-name').value.trim() || ('Server ' + (getSavedServers().length + 1));
    if (!code) return notify('Enter a code first!');
    try {
        const ref = await serversColRef().add({ name, code });
        serversCache.push({ id: ref.id, name, code });
        document.getElementById('server-save-name').value = '';
        renderServerList();
        notify('Server bookmarked!');
    } catch (e) { notify('Could not save: ' + e.message); }
}

function renderServerList() {
    const listEl = document.getElementById('saved-servers-list');
    if (!listEl) return;
    listEl.innerHTML = '';
    if (!firebaseReady) { listEl.innerHTML = '<div style="font-size:7px;color:#888;">Bookmarks need Firebase configured.</div>'; return; }
    const servers = getSavedServers();
    if (servers.length === 0) { listEl.innerHTML = '<div style="font-size:7px;color:#888;">No bookmarked servers yet.</div>'; return; }
    servers.forEach((s) => {
        const row = document.createElement('div');
        row.className = 'saved-list-row';
        const label = document.createElement('div');
        label.className = 'name';
        label.innerText = s.name + ' (' + s.code + ')';
        row.appendChild(label);
        const joinBtn = document.createElement('button');
        joinBtn.className = 'pixel-btn small';
        joinBtn.innerText = 'Join';
        joinBtn.onclick = () => { document.getElementById('join-room-code').value = s.code; joinMultiplayerRoom(); };
        row.appendChild(joinBtn);
        const delBtn = document.createElement('button');
        delBtn.className = 'pixel-btn danger small';
        delBtn.innerText = 'X';
        delBtn.onclick = async () => {
            try { await serversColRef().doc(s.id).delete(); serversCache = serversCache.filter(x => x.id !== s.id); renderServerList(); }
            catch (e) { notify('Could not delete: ' + e.message); }
        };
        row.appendChild(delBtn);
        listEl.appendChild(row);
    });
}

function getSavedServers() { return serversCache; }

function renderSavedSkinsList() {
    const listEl = document.getElementById('saved-skins-list');
    listEl.innerHTML = '';
    const list = getSavedSkins();
    if (!firebaseReady) { listEl.innerHTML = '<div style="font-size:7px;color:#888;">Saved skins need Firebase configured.</div>'; return; }
    if (list.length === 0) { listEl.innerHTML = '<div style="font-size:7px;color:#888;">No saved skins yet.</div>'; return; }
    list.forEach((entry) => {
        const row = document.createElement('div');
        row.className = 'saved-list-row';
        const label = document.createElement('div');
        label.className = 'name';
        label.innerText = entry.name;
        row.appendChild(label);
        const useBtn = document.createElement('button');
        useBtn.className = 'pixel-btn small';
        useBtn.innerText = 'Use';
        useBtn.onclick = () => {
            currentSkin = { ...entry.skin };
            saveActiveSkin();
            document.getElementById('skin-color-skin').value = currentSkin.skin;
            document.getElementById('skin-color-shirt').value = currentSkin.shirt;
            document.getElementById('skin-color-pants').value = currentSkin.pants;
            document.getElementById('skin-color-hair').value = currentSkin.hair;
            drawSkinPreview();
            notify('Skin applied!');
        };
        row.appendChild(useBtn);
        const delBtn = document.createElement('button');
        delBtn.className = 'pixel-btn danger small';
        delBtn.innerText = 'X';
        delBtn.onclick = async () => {
            try { await skinsColRef().doc(entry.id).delete(); skinsCache = skinsCache.filter(s => s.id !== entry.id); renderSavedSkinsList(); }
            catch (e) { notify('Could not delete: ' + e.message); }
        };
        row.appendChild(delBtn);
        listEl.appendChild(row);
    });
}

function drawSkinPreview() {
    const cvs = document.getElementById('skin-preview-canvas');
    const c = cvs.getContext('2d');
    c.clearRect(0, 0, cvs.width, cvs.height);
    c.fillStyle = '#1c1c28';
    c.fillRect(0, 0, cvs.width, cvs.height);
    const sx = 30, sy = 10;
    c.fillStyle = currentSkin.shirt;
    c.fillRect(sx + 2, sy + 22, 32, 34);
    c.fillStyle = '#2c3e50';
    c.fillRect(sx + 3, sy + 56, 12, 28);
    c.fillRect(sx + 21, sy + 56, 12, 28);
    c.fillStyle = currentSkin.pants;
    c.fillRect(sx + 3, sy + 56, 12, 20);
    c.fillRect(sx + 21, sy + 56, 12, 20);
    c.fillStyle = currentSkin.skin;
    c.fillRect(sx, sy, 36, 22);
    c.fillStyle = currentSkin.hair;
    c.fillRect(sx - 1, sy - 4, 38, 8);
    c.fillStyle = '#000';
    c.fillRect(sx + 8, sy + 8, 4, 4);
    c.fillRect(sx + 24, sy + 8, 4, 4);
}

function closeSkinEditor() {
    saveActiveSkin();
    document.getElementById('skin-editor-modal').style.display = 'none';
    document.getElementById('skin-backdrop').style.display = 'none';
}

function openSkinEditor() {
    document.getElementById('skin-color-skin').value = currentSkin.skin;
    document.getElementById('skin-color-shirt').value = currentSkin.shirt;
    document.getElementById('skin-color-pants').value = currentSkin.pants;
    document.getElementById('skin-color-hair').value = currentSkin.hair;
    ['skin', 'shirt', 'pants', 'hair'].forEach(k => {
        document.getElementById('skin-color-' + k).oninput = (e) => { currentSkin[k] = e.target.value; drawSkinPreview(); };
    });
    drawSkinPreview();
    renderSavedSkinsList();
    document.getElementById('skin-editor-modal').style.display = 'flex';
    document.getElementById('skin-backdrop').style.display = 'block';
}

async function saveNamedSkin() {
    const name = document.getElementById('skin-save-name').value.trim();
    if (!name) return notify('Give your skin a name!');
    if (!requireOnline()) return;
    try {
        const ref = await skinsColRef().add({ name, skin: { ...currentSkin }, ts: Date.now() });
        skinsCache.push({ id: ref.id, name, skin: { ...currentSkin } });
        document.getElementById('skin-save-name').value = '';
        renderSavedSkinsList();
        notify('Skin saved to your account!');
    } catch (e) { notify('Could not save skin: ' + e.message); }
}

function getSavedSkins() { return skinsCache; }

function saveActiveSkin() { profile.activeSkin = currentSkin; saveProfileField({ activeSkin: currentSkin }); }

function loadSkin() { currentSkin = { ...defaultSkinObj(), ...(profile.activeSkin || {}) }; }

function closeSettings() {
    settings.shake = document.getElementById('set-shake').checked;
    settings.lighting = document.getElementById('set-lighting').checked;
    settings.dust = document.getElementById('set-dust').checked;
    settings.vignette = document.getElementById('set-vignette').checked;
    settings.smoothCamera = document.getElementById('set-smoothcam').checked;
    saveSettings();
    document.getElementById('settings-modal').style.display = 'none';
    document.getElementById('settings-backdrop').style.display = 'none';
}

function openSettings() {
    document.getElementById('set-shake').checked = settings.shake;
    document.getElementById('set-lighting').checked = settings.lighting;
    document.getElementById('set-dust').checked = settings.dust;
    document.getElementById('set-vignette').checked = settings.vignette;
    document.getElementById('set-smoothcam').checked = settings.smoothCamera;
    document.getElementById('settings-modal').style.display = 'flex';
    document.getElementById('settings-backdrop').style.display = 'block';
}

function saveSettings() { profile.settings = settings; saveProfileField({ settings }); }

function loadSettings() { settings = { ...defaultSettingsObj(), ...(profile.settings || {}) }; }

async function handleLogout() {
    try { await auth.signOut(); notify('Signed out - starting a fresh guest profile on this device.'); }
    catch (e) { notify(e.message); }
}

async function handleForgotPassword() {
    const email = document.getElementById('auth-username').value.trim();
    if (!email) return notify('Enter your email above first.');
    try { await auth.sendPasswordResetEmail(email); notify('Password reset email sent.'); }
    catch (e) { notify(e.message); }
}

async function handleLogin() {
    const email = document.getElementById('auth-username').value.trim();
    const pass = document.getElementById('auth-password').value;
    const errEl = document.getElementById('auth-error');
    errEl.innerText = '';
    if (!email || !pass) { errEl.innerText = 'Enter an email and password.'; return; }
    if (currentUser && currentUser.isAnonymous) {
        const proceed = confirm('Logging into an existing account leaves this device\'s guest progress behind. Continue?');
        if (!proceed) return;
    }
    try {
        await auth.signOut();
        await auth.signInWithEmailAndPassword(email, pass);
        showMenuSection('main-menu');
    } catch (e) { errEl.innerText = e.message; }
}

async function handleRegister() {
    const email = document.getElementById('auth-username').value.trim();
    const pass = document.getElementById('auth-password').value;
    const errEl = document.getElementById('auth-error');
    errEl.innerText = '';
    if (!email || !pass) { errEl.innerText = 'Enter an email and password.'; return; }
    try {
        if (currentUser && currentUser.isAnonymous) {
            const cred = firebase.auth.EmailAuthProvider.credential(email, pass);
            await currentUser.linkWithCredential(cred);
            notify('Account created - your guest progress is now saved permanently!');
        } else {
            await auth.createUserWithEmailAndPassword(email, pass);
        }
        showMenuSection('main-menu');
    } catch (e) { errEl.innerText = e.message; }
}

function refreshAccountBar() {
    const label = document.getElementById('account-bar-label');
    const action = document.getElementById('account-bar-action');
    if (!firebaseReady) { label.innerText = 'Offline mode - Firebase not configured'; action.style.display = 'none'; return; }
    if (!currentUser) { label.innerText = 'Connecting...'; return; }
    action.style.display = 'inline-flex';
    if (currentUser.isAnonymous) {
        label.innerText = 'Playing as Guest';
        action.innerText = 'Save Progress Permanently';
        action.onclick = () => showMenuSection('auth-menu');
    } else {
        label.innerText = 'Signed in: ' + currentUser.email;
        action.innerText = 'Log Out';
        action.onclick = handleLogout;
    }
}

function persistPlayerName() {
    profile.playerName = document.getElementById('player-name').value;
    saveProfileField({ playerName: profile.playerName });
}

function saveProfileField(patch) {
    Object.assign(profile, patch);
    if (!firebaseReady || !currentUser) return;
    userDocRef().set(patch, { merge: true }).catch(e => notify('Cloud save failed: ' + e.message));
}

async function loadCloudProfile() {
    const snap = await userDocRef().get();
    if (snap.exists) {
        const d = snap.data();
        profile.playerName = d.playerName || 'Explorer';
        profile.settings = { ...defaultSettingsObj(), ...(d.settings || {}) };
        profile.activeSkin = { ...defaultSkinObj(), ...(d.activeSkin || {}) };
    } else {
        profile = { playerName: 'Explorer', settings: defaultSettingsObj(), activeSkin: defaultSkinObj() };
        await userDocRef().set({ playerName: profile.playerName, settings: profile.settings, activeSkin: profile.activeSkin, createdAt: firebase.firestore.FieldValue.serverTimestamp() }, { merge: true });
    }
    settings = profile.settings;
    currentSkin = profile.activeSkin;
    const [skinsSnap, serversSnap, worldsSnap] = await Promise.all([
        skinsColRef().get(), serversColRef().get(), worldsMetaColRef().get()
    ]);
    skinsCache = skinsSnap.docs.map(d => ({ id: d.id, ...d.data() }));
    serversCache = serversSnap.docs.map(d => ({ id: d.id, ...d.data() }));
    worldsMetaCache = worldsSnap.docs.map(d => ({ id: d.id, ...d.data() })).sort((a, b) => (b.ts || 0) - (a.ts || 0));
}
