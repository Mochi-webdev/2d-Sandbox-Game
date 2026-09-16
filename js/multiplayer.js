
/* multiplayer.js – WebRTC rooms & networking */
let currentRoomCode = null;
let hostConnections = {};
let callersUnsub = null;
let heartbeatInterval = null, autosaveInterval = null;
let clientPC = null, clientDC = null, currentCallerId = null, joinCleanupFns = null;
let serverBrowserUnsub = null;
let serverBrowserCache = [];
function generateRoomCode() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    let code = '';
    for (let i = 0; i < 5; i++) code += chars[Math.floor(Math.random() * chars.length)];
    return 'PXL-' + code;
}

function copyRoomCode() {
    if (currentRoomCode) {
        navigator.clipboard.writeText(currentRoomCode)
            .then(() => notify('Room code copied to clipboard!'))
            .catch(() => notify('Could not copy to clipboard.'));
    } else {
        notify('No room code available yet.');
    }
}

function currentPlayerName() {
    return document.getElementById('player-name').value || 'Player';
}

async function hostMultiplayerRoom() {
    if (!requireOnline()) return;
    isMultiplayer = true; isHost = true;
    document.getElementById('host-room-btn').disabled = true;
    const box = document.getElementById('host-code-box');
    box.style.display = 'block'; box.innerText = 'SETTING UP...';

    const roomCode = generateRoomCode();
    const chosenWorldId = document.getElementById('host-world-select').value;

    resetHotbar();
    activeCloudWorldId = null;
    if (chosenWorldId && chosenWorldId !== '__new__') {
        try {
            const snap = await worldsDataColRef().doc(chosenWorldId).get();
            if (snap.exists) { applyWorldSnapshot(snap.data()); activeCloudWorldId = chosenWorldId; }
            else generateContinuousCaves();
        } catch (e) { generateContinuousCaves(); }
    } else {
        generateContinuousCaves();
    }

    currentRoomCode = roomCode;
    hostConnections = {};

    const worldMeta = worldsMetaCache.find(w => w.id === activeCloudWorldId);
    try {
        await db.collection('servers').doc(roomCode).set({
            hostUid: currentUser.uid,
            hostName: currentPlayerName(),
            worldName: worldMeta ? worldMeta.name : 'New World',
            mode: gameMode,
            players: 1,
            listed: document.getElementById('host-list-public').checked,
            createdAt: firebase.firestore.FieldValue.serverTimestamp(),
            updatedAt: firebase.firestore.FieldValue.serverTimestamp()
        });
    } catch (e) {
        notify('Could not create room: ' + e.message);
        document.getElementById('host-room-btn').disabled = false;
        isMultiplayer = false; isHost = false;
        return;
    }

    box.innerText = 'ROOM CODE: ' + roomCode;
    document.getElementById('copy-code-btn').style.display = 'inline-block';
    document.getElementById('start-hosted-btn').style.display = 'inline-block';
    notify('Room ready! Share the code with friends.');

    listenForCallers(roomCode);
    startHostAutosave();
}

function listenForCallers(roomCode) {
    callersUnsub = db.collection('servers').doc(roomCode).collection('callers').onSnapshot(snapshot => {
        snapshot.docChanges().forEach(change => {
            if (change.type === 'added') handleNewCaller(roomCode, change.doc.id, change.doc.data());
        });
    }, err => notify('Signaling error: ' + err.message));
}

async function handleNewCaller(roomCode, callerId, data) {
    if (hostConnections[callerId] || !data.offer) return;
    const pc = new RTCPeerConnection(ICE_CONFIG);
    const entry = { pc, dc: null, helloReceived: false, candUnsub: null };
    hostConnections[callerId] = entry;

    const callerDocRef = db.collection('servers').doc(roomCode).collection('callers').doc(callerId);

    pc.onicecandidate = e => { if (e.candidate) callerDocRef.collection('hostCandidates').add(e.candidate.toJSON()).catch(() => {}); };
    pc.ondatachannel = e => { entry.dc = e.channel; wireHostDataChannel(entry, callerId); };
    pc.onconnectionstatechange = () => {
        if (pc.connectionState === 'failed' || pc.connectionState === 'closed') removeHostConnection(callerId);
    };

    entry.candUnsub = callerDocRef.collection('callerCandidates').onSnapshot(snap => {
        snap.docChanges().forEach(ch => { if (ch.type === 'added') pc.addIceCandidate(new RTCIceCandidate(ch.doc.data())).catch(() => {}); });
    });

    try {
        await pc.setRemoteDescription(new RTCSessionDescription(data.offer));
        const answer = await pc.createAnswer();
        await pc.setLocalDescription(answer);
        await callerDocRef.update({ answer: { type: answer.type, sdp: answer.sdp } });
    } catch (e) {
        notify('Failed to answer a join request.');
        removeHostConnection(callerId);
    }
}

function wireHostDataChannel(entry, callerId) {
    const dc = entry.dc;
    dc.onmessage = ev => {
        let data; try { data = JSON.parse(ev.data); } catch (e) { return; }
        if (data.type === 'HELLO') {
            entry.helloReceived = true;
            notify((data.name || 'A player') + ' connected!');
            sendWorldTo(entry);
            updateHostPlayerCount();
        } else {
            handleNetworkData(data, callerId);
        }
    };
    dc.onclose = () => { removeHostConnection(callerId); notify('A player disconnected.'); };
    dc.onerror = () => notify('A connection error occurred.');
    setTimeout(() => { if (!entry.helloReceived && dc.readyState === 'open') sendWorldTo(entry); }, 1500);
}

function removeHostConnection(callerId) {
    const entry = hostConnections[callerId];
    if (!entry) return;
    if (entry.candUnsub) entry.candUnsub();
    if (entry.dc) try { entry.dc.close(); } catch (e) {}
    if (entry.pc) try { entry.pc.close(); } catch (e) {}
    delete hostConnections[callerId];
    otherPlayers.delete(callerId);
    updateHostPlayerCount();
}

function sendWorldTo(entry) {
    if (!entry.dc || entry.dc.readyState !== 'open') return;
    try { entry.dc.send(JSON.stringify({ type: 'WORLD', world: Array.from(world), wallWorld: Array.from(wallWorld) })); }
    catch (e) { setTimeout(() => sendWorldTo(entry), 1000); }
}

function updateHostPlayerCount() {
    updatePlayerCountDisplay();
    if (currentRoomCode && firebaseReady) {
        const count = Object.values(hostConnections).filter(e => e.dc && e.dc.readyState === 'open').length + 1;
        db.collection('servers').doc(currentRoomCode).update({ players: count, updatedAt: firebase.firestore.FieldValue.serverTimestamp() }).catch(() => {});
    }
}

function startHostAutosave() {
    if (autosaveInterval) clearInterval(autosaveInterval);
    if (heartbeatInterval) clearInterval(heartbeatInterval);
    autosaveInterval = setInterval(() => {
        if (activeCloudWorldId && firebaseReady) worldsDataColRef().doc(activeCloudWorldId).set(getWorldSnapshot()).catch(() => {});
    }, 45000);
    heartbeatInterval = setInterval(() => {
        if (currentRoomCode && firebaseReady) {
            const count = Object.values(hostConnections).filter(e => e.dc && e.dc.readyState === 'open').length + 1;
            db.collection('servers').doc(currentRoomCode).update({ updatedAt: firebase.firestore.FieldValue.serverTimestamp(), players: count }).catch(() => {});
        }
    }, 20000);
}

function cleanupHost() {
    if (callersUnsub) { callersUnsub(); callersUnsub = null; }
    Object.keys(hostConnections).forEach(removeHostConnection);
    hostConnections = {};
    if (heartbeatInterval) { clearInterval(heartbeatInterval); heartbeatInterval = null; }
    if (autosaveInterval) { clearInterval(autosaveInterval); autosaveInterval = null; }
    if (currentRoomCode && firebaseReady) db.collection('servers').doc(currentRoomCode).delete().catch(() => {});
    currentRoomCode = null;
    document.getElementById('host-room-btn').disabled = false;
}

async function joinMultiplayerRoom() {
    if (!requireOnline()) return;
    const code = document.getElementById('join-room-code').value.trim().toUpperCase();
    if (!code) return notify('Enter host code!');

    isMultiplayer = true; isHost = false;
    document.getElementById('join-room-btn').disabled = true;
    notify('Looking up server...');

    const roomRef = db.collection('servers').doc(code);
    let roomSnap;
    try { roomSnap = await roomRef.get(); }
    catch (e) { notify('Could not reach the server: ' + e.message); document.getElementById('join-room-btn').disabled = false; isMultiplayer = false; return; }

    if (!roomSnap.exists) {
        notify('No server found with that code.');
        document.getElementById('join-room-btn').disabled = false; isMultiplayer = false; return;
    }
    const roomData = roomSnap.data();
    if (roomData.updatedAt && (Date.now() - roomData.updatedAt.toMillis()) > 90000) {
        notify('This server appears to be offline (no recent heartbeat).');
        document.getElementById('join-room-btn').disabled = false; isMultiplayer = false; return;
    }

    notify('Connecting...');
    const pc = new RTCPeerConnection(ICE_CONFIG);
    clientPC = pc;
    const dc = pc.createDataChannel('game');
    clientDC = dc;
    currentRoomCode = code;

    const callerDocRef = roomRef.collection('callers').doc();
    currentCallerId = callerDocRef.id;

    let worldReceived = false;
    let helloTimer = null;

    pc.onicecandidate = e => { if (e.candidate) callerDocRef.collection('callerCandidates').add(e.candidate.toJSON()).catch(() => {}); };

    const hostCandUnsub = callerDocRef.collection('hostCandidates').onSnapshot(snap => {
        snap.docChanges().forEach(ch => { if (ch.type === 'added') pc.addIceCandidate(new RTCIceCandidate(ch.doc.data())).catch(() => {}); });
    });
    const answerUnsub = callerDocRef.onSnapshot(snap => {
        const data = snap.data();
        if (data && data.answer && !pc.currentRemoteDescription) {
            pc.setRemoteDescription(new RTCSessionDescription(data.answer)).catch(() => {});
        }
    });

    function sendHello() {
        if (dc.readyState === 'open') dc.send(JSON.stringify({ type: 'HELLO', name: currentPlayerName() }));
        if (!worldReceived) helloTimer = setTimeout(sendHello, 2500);
    }

    dc.onopen = () => { notify('Connected! Requesting world...'); sendHello(); };
    dc.onmessage = ev => {
        let data; try { data = JSON.parse(ev.data); } catch (e) { return; }
        if (data.type === 'WORLD') {
            worldReceived = true;
            clearTimeout(helloTimer);
            clearTimeout(timeoutId);
            resetHotbar();
            applyWorldSnapshot({ world: data.world, wallWorld: data.wallWorld });
            spawnPlayer();
            player.health = player.maxHealth;
            notify('World received!');
            startEngineUI();
        } else {
            handleNetworkData(data, data.__relayFrom || 'host');
        }
    };
    dc.onclose = () => notify('Disconnected from host.');
    dc.onerror = () => notify('Connection error.');

    joinCleanupFns = () => { hostCandUnsub(); answerUnsub(); clearTimeout(helloTimer); clearTimeout(timeoutId); };

    try {
        const offer = await pc.createOffer();
        await pc.setLocalDescription(offer);
        await callerDocRef.set({ offer: { type: offer.type, sdp: offer.sdp }, name: currentPlayerName(), createdAt: firebase.firestore.FieldValue.serverTimestamp() });
    } catch (e) {
        notify('Could not start connection: ' + e.message);
        document.getElementById('join-room-btn').disabled = false;
        cleanupClient(); isMultiplayer = false;
        return;
    }

    var timeoutId = setTimeout(() => {
        if (!worldReceived) {
            notify('Connection timed out. The host may be offline or unreachable.');
            document.getElementById('join-room-btn').disabled = false;
            cleanupFailedJoin();
        }
    }, 20000);
}

function cleanupClient() {
    if (joinCleanupFns) { joinCleanupFns(); joinCleanupFns = null; }
    if (clientPC) { try { clientPC.close(); } catch (e) {} clientPC = null; }
    clientDC = null;
    if (currentRoomCode && currentCallerId && firebaseReady) {
        db.collection('servers').doc(currentRoomCode).collection('callers').doc(currentCallerId).delete().catch(() => {});
    }
    currentRoomCode = null; currentCallerId = null;
    document.getElementById('join-room-btn').disabled = false;
}

function cleanupFailedJoin() { cleanupClient(); isMultiplayer = false; }

function sendToAllClients(msg, exceptId) {
    const payload = JSON.stringify(msg);
    Object.keys(hostConnections).forEach(id => {
        if (id === exceptId) return;
        const e = hostConnections[id];
        if (e.dc && e.dc.readyState === 'open') e.dc.send(payload);
    });
}

function broadcastPlayerPos() {
    if (!isMultiplayer) return;
    const msg = {
        type: 'POS',
        name: currentPlayerName(),
        x: player.x, y: player.y, facing: player.facing, animTime: player.animTime,
        skin: currentSkin,
        armor: { head: equipment.head.type, chest: equipment.chest.type, legs: equipment.legs.type, feet: equipment.feet.type }
    };
    if (isHost) sendToAllClients(msg);
    else if (clientDC && clientDC.readyState === 'open') clientDC.send(JSON.stringify(msg));
}

function broadcastBlockChange(col, row, blockType) {
    if (!isMultiplayer) return;
    const msg = { type: 'BLOCK', col, row, blockType };
    if (isHost) sendToAllClients(msg);
    else if (clientDC && clientDC.readyState === 'open') clientDC.send(JSON.stringify(msg));
}

function handleNetworkData(data, senderKey) {
    if (data.type === 'POS') {
        otherPlayers.set(senderKey, data);
        if (isHost) sendToAllClients({ ...data, __relayFrom: senderKey }, senderKey);
    } else if (data.type === 'BLOCK') {
        const index = data.row * WORLD_WIDTH + data.col;
        world[index] = data.blockType;
        blockDamageMap[index] = 0;
        if (isHost) sendToAllClients(data, senderKey);
    }
}

function startServerBrowserListener() {
    stopServerBrowserListener();
    if (!requireOnline()) { renderServerBrowser(); return; }
    serverBrowserUnsub = db.collection('servers').where('listed', '==', true).limit(30).onSnapshot(snap => {
        serverBrowserCache = snap.docs.map(d => ({ id: d.id, ...d.data() }));
        renderServerBrowser();
    }, err => { notify('Server list error: ' + err.message); });
}

function stopServerBrowserListener() { if (serverBrowserUnsub) { serverBrowserUnsub(); serverBrowserUnsub = null; } }

function renderServerBrowser() {
    const el = document.getElementById('server-browser-list');
    if (!el) return;
    el.innerHTML = '';
    if (!firebaseReady) { el.innerHTML = '<div style="font-size:7px;color:#888;">Multiplayer needs Firebase configured.</div>'; return; }
    const list = [...serverBrowserCache].sort((a, b) => ((b.updatedAt && b.updatedAt.seconds) || 0) - ((a.updatedAt && a.updatedAt.seconds) || 0));
    const fresh = list.filter(info => !info.updatedAt || (Date.now() - info.updatedAt.toMillis()) < 90000);
    if (fresh.length === 0) { el.innerHTML = '<div style="font-size:7px;color:#888;">No public rooms right now.</div>'; return; }
    fresh.forEach(info => {
        const row = document.createElement('div'); row.className = 'saved-list-row';
        const label = document.createElement('div'); label.className = 'name';
        label.innerText = (info.worldName || 'Room') + ' by ' + (info.hostName || '?') + ' - ' + info.id + ' [' + (info.mode || 'survival') + ', ' + (info.players || 1) + 'p]';
        row.appendChild(label);
        const joinBtn = document.createElement('button'); joinBtn.className = 'pixel-btn small'; joinBtn.innerText = 'Join';
        joinBtn.onclick = () => { document.getElementById('join-room-code').value = info.id; joinMultiplayerRoom(); };
        row.appendChild(joinBtn);
        el.appendChild(row);
    });
}
